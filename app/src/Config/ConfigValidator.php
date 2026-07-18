<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Valide un tableau de configuration contre {@see ConfigSchema}. Fonction pure :
 * aucune I/O (ni PDO, ni réseau, ni require) — c'est ce qui la rend testable
 * sans base et sûre à appeler au bootstrap.
 *
 * Calibrage des sévérités (cf. #153) :
 *   - ERROR   : uniquement `database` absente ou incomplète.
 *   - WARNING : section optionnelle absente, clé inconnue (typo), sentinelle
 *               restante dans une section *active*, clé déplacée (`moved`).
 *
 * Règle d'or : « section absente = WARNING, jamais ERROR » — c'est ce qui rend
 * valide le config `database`-only généré par la CI.
 */
final class ConfigValidator
{
    /**
     * @param array<string, mixed> $config
     * @param bool $checkSentinels false en mode `--schema-only` : les `change_me`
     *             sont *attendus* dans config.example.php.
     * @return list<ConfigIssue>
     */
    public static function validate(array $config, bool $checkSentinels = true): array
    {
        $issues = [];
        self::walkSection(ConfigSchema::root(), $config, '', true, $checkSentinels, $issues);

        return $issues;
    }

    /**
     * Parcourt un nœud « section » (racine ou sous-section fermée) : sections
     * absentes/incomplètes, clés inconnues, puis récursion sur chaque enfant.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $value
     * @param list<ConfigIssue> $issues
     */
    private static function walkSection(
        array $node,
        array $value,
        string $path,
        bool $active,
        bool $checkSentinels,
        array &$issues,
    ): void {
        $children = self::childrenOf($node);

        foreach ($children as $key => $childNode) {
            $childPath    = $path === '' ? $key : $path . '.' . $key;
            $present      = array_key_exists($key, $value);
            $optional     = ($childNode['optional'] ?? true) === true;
            $isSection    = isset($childNode['children']) || isset($childNode['requiredKeys']);

            if (!$present) {
                if (!$optional) {
                    $issues[] = ConfigIssue::error($childPath, "section « {$key} » requise mais absente", ConfigIssue::KIND_MISSING_REQUIRED);
                } elseif ($isSection) {
                    $hint     = isset($childNode['absentHint']) && is_string($childNode['absentHint'])
                        ? ' → ' . $childNode['absentHint']
                        : '';
                    $issues[] = ConfigIssue::warning($childPath, "section « {$key} » absente{$hint}", ConfigIssue::KIND_MISSING_SECTION);
                }
                continue;
            }

            self::walkNode($childNode, $value[$key], $childPath, $active, $checkSentinels, $issues);
        }

        // Détection de clés inconnues (sauf nœud « map » à clés libres).
        if (($node['map'] ?? false) !== true) {
            $known = array_keys($children);
            $flat  = self::legacyFlatKeys($node);
            foreach (array_keys($value) as $key) {
                if (in_array($key, $known, true) || in_array($key, $flat, true)) {
                    continue;
                }
                $childPath = $path === '' ? $key : $path . '.' . $key;
                $issues[]  = ConfigIssue::warning($childPath, "clé inconnue « {$key} » (typo ?)", ConfigIssue::KIND_UNKNOWN_KEY);
            }
        }
    }

    /**
     * Valide une valeur contre son nœud (feuille scalaire, section fermée, ou map).
     *
     * @param array<string, mixed> $node
     * @param list<ConfigIssue> $issues
     */
    private static function walkNode(
        array $node,
        mixed $value,
        string $path,
        bool $active,
        bool $checkSentinels,
        array &$issues,
    ): void {
        // Clé déplacée (P3) : sa seule présence est un WARNING d'instruction.
        if (isset($node['moved']) && is_string($node['moved'])) {
            $issues[] = ConfigIssue::warning($path, $node['moved'], ConfigIssue::KIND_MOVED);
            return;
        }

        // « Actif » se propage : une sentinelle ne compte que si sa section l'est.
        $childActive = $active;
        if (array_key_exists('enabledDefault', $node)) {
            $enabled     = is_array($value) ? ($value['enabled'] ?? $node['enabledDefault']) : $node['enabledDefault'];
            $childActive = $active && ($enabled === true);
        }

        // Section incomplète (database) → ERROR par clé requise manquante.
        if (isset($node['requiredKeys']) && is_array($node['requiredKeys'])) {
            foreach ($node['requiredKeys'] as $req) {
                if (!is_string($req)) {
                    continue;
                }
                if (!is_array($value) || !array_key_exists($req, $value) || self::isBlank($value[$req])) {
                    $issues[] = ConfigIssue::error($path . '.' . $req, "clé « {$req} » requise mais absente ou vide", ConfigIssue::KIND_MISSING_REQUIRED);
                }
            }
        }

        // Feuille sentinelle.
        if (($node['sentinel'] ?? false) === true && $checkSentinels && $childActive && self::isSentinel($value)) {
            $issues[] = ConfigIssue::warning($path, 'valeur sentinelle « ' . (string) $value . ' » à remplacer', ConfigIssue::KIND_SENTINEL);
        }

        // Map à clés libres : pas de contrôle de clé inconnue, mais scan des sentinelles.
        if (($node['map'] ?? false) === true) {
            if ($checkSentinels && $childActive && is_array($value)) {
                self::scanSentinels($value, $path, $issues);
            }
            return;
        }

        // Section fermée avec enfants connus → récursion.
        if (isset($node['children']) && is_array($value)) {
            self::walkSection($node, $value, $path, $childActive, $checkSentinels, $issues);
        }
    }

    /**
     * Scan récursif des sentinelles dans une valeur à clés libres (blocs OIDC
     * providers.*, où client_id/client_secret peuvent rester en `change_me`).
     *
     * @param array<mixed> $value
     * @param list<ConfigIssue> $issues
     */
    private static function scanSentinels(array $value, string $path, array &$issues): void
    {
        foreach ($value as $key => $sub) {
            $subPath = $path . '.' . (string) $key;
            if (is_array($sub)) {
                self::scanSentinels($sub, $subPath, $issues);
            } elseif (self::isSentinel($sub)) {
                $issues[] = ConfigIssue::warning($subPath, 'valeur sentinelle « ' . (string) $sub . ' » à remplacer', ConfigIssue::KIND_SENTINEL);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, array<string, mixed>>
     */
    private static function childrenOf(array $node): array
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return [];
        }
        /** @var array<string, array<string, mixed>> $children */
        $children = $node['children'];

        return $children;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private static function legacyFlatKeys(array $node): array
    {
        if (!isset($node['legacyFlatKeys']) || !is_array($node['legacyFlatKeys'])) {
            return [];
        }
        $keys = [];
        foreach ($node['legacyFlatKeys'] as $k) {
            if (is_string($k)) {
                $keys[] = $k;
            }
        }

        return $keys;
    }

    private static function isSentinel(mixed $value): bool
    {
        return is_string($value) && in_array($value, ConfigSchema::SENTINELS, true);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
