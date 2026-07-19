<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Opt-in des « connecteurs d'export » PAR UTILISATEUR (système de modules #70).
 *
 * Une ligne par couple (utilisateur, module). Les réglages propres au module
 * (ex. device_id, claimed_at pour EnergyID) vivent dans la colonne JSON
 * `settings` : ajouter un module ne nécessite aucune migration de schéma.
 * Aucun secret n'y est stocké (mêmes garanties que l'ancien opt-in EnergyID).
 */
final class UserIntegrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{enabled: bool, settings: array<string, mixed>}|null
     */
    public function get(int $userId, string $moduleKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT enabled, settings FROM user_integrations
             WHERE user_id = :uid AND module_key = :mk'
        );
        $stmt->execute(['uid' => $userId, 'mk' => $moduleKey]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'enabled'  => (bool) $row['enabled'],
            'settings' => $this->decodeSettings($row['settings']),
        ];
    }

    /**
     * Active l'intégration (crée la ligne au besoin). Les `$defaultSettings` sont
     * fusionnés dans le JSON existant (JSON_MERGE_PATCH) : un ré-enable réapplique
     * les valeurs dérivées (ex. device_id) sans écraser les clés absentes des
     * défauts (ex. claimed_at posé par le cron).
     *
     * @param array<string, mixed> $defaultSettings
     */
    public function enable(int $userId, string $moduleKey, array $defaultSettings): void
    {
        $json = $this->encode($defaultSettings);

        $this->pdo->prepare(
            'INSERT INTO user_integrations (user_id, module_key, enabled, settings)
             VALUES (:uid, :mk, 1, :settings)
             ON DUPLICATE KEY UPDATE
                 enabled  = 1,
                 settings = JSON_MERGE_PATCH(settings, VALUES(settings))'
        )->execute(['uid' => $userId, 'mk' => $moduleKey, 'settings' => $json]);
    }

    public function disable(int $userId, string $moduleKey): void
    {
        $this->pdo->prepare(
            'UPDATE user_integrations SET enabled = 0
             WHERE user_id = :uid AND module_key = :mk'
        )->execute(['uid' => $userId, 'mk' => $moduleKey]);
    }

    /**
     * Fusionne `$patch` dans les settings existants (atomique côté SQL). Utilisé
     * par le cron pour poser des marqueurs de statut (ex. claimed_at) sans
     * interférer avec un enable/disable concurrent côté web.
     *
     * Sémantique JSON_MERGE_PATCH (RFC 7396) : une clé de `$patch` valant `null`
     * SUPPRIME la clé du document (elle ne la met pas à `null`). Pour effacer un
     * réglage, passer `null` ; pour stocker une valeur, passer cette valeur.
     *
     * @param array<string, mixed> $patch
     */
    public function patchSettings(int $userId, string $moduleKey, array $patch): void
    {
        if ($patch === []) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE user_integrations
             SET settings = JSON_MERGE_PATCH(settings, :patch)
             WHERE user_id = :uid AND module_key = :mk'
        )->execute(['uid' => $userId, 'mk' => $moduleKey, 'patch' => $this->encode($patch)]);
    }

    /**
     * Utilisateurs ayant activé le module (pour le cron d'export).
     *
     * @return list<array{user_id: int, settings: array<string, mixed>}>
     */
    public function listEnabledUsers(string $moduleKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, settings FROM user_integrations
             WHERE module_key = :mk AND enabled = 1 ORDER BY user_id'
        );
        $stmt->execute(['mk' => $moduleKey]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'user_id'  => (int) $row['user_id'],
                'settings' => $this->decodeSettings($row['settings']),
            ];
        }

        return $out;
    }

    /**
     * Décode le JSON `settings` en tableau associatif (jamais `mixed`/scalaire).
     *
     * @return array<string, mixed>
     */
    private function decodeSettings(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function encode(array $settings): string
    {
        return json_encode($settings, JSON_THROW_ON_ERROR);
    }
}
