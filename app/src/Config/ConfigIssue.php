<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Constat unitaire produit par {@see ConfigValidator} : une sévérité, une
 * catégorie (`kind`), le chemin pointé (`section.clé`) et un message lisible.
 *
 * Objet-valeur immuable, sans I/O — c'est ce qui rend le validateur testable
 * sans base ni réseau.
 */
final class ConfigIssue
{
    public const ERROR   = 'ERROR';
    public const WARNING = 'WARNING';

    // Catégories : la sévérité dit « bloquant ou non », le `kind` dit « quelle
    // sorte de problème » — ce qui permet au bootstrap de ne journaliser que ce
    // qui traduit une dérive silencieuse dangereuse (cf. isRuntimeSignal()).
    public const KIND_MISSING_REQUIRED = 'missing_required';
    public const KIND_MISSING_SECTION  = 'missing_section';
    public const KIND_UNKNOWN_KEY      = 'unknown_key';
    public const KIND_SENTINEL         = 'sentinel';
    public const KIND_MOVED            = 'moved';
    public const KIND_INVALID_VALUE    = 'invalid_value';

    public function __construct(
        public readonly string $severity,
        public readonly string $path,
        public readonly string $message,
        public readonly string $kind,
    ) {
    }

    public static function error(string $path, string $message, string $kind): self
    {
        return new self(self::ERROR, $path, $message, $kind);
    }

    public static function warning(string $path, string $message, string $kind): self
    {
        return new self(self::WARNING, $path, $message, $kind);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * Un constat mérite un signal au runtime (journalisé au bootstrap) s'il est
     * bloquant (ERROR : `database`) ou s'il traduit une dérive silencieuse
     * dangereuse : sentinelle laissée dans une section *active*, clé déplacée dont
     * la valeur en config est désormais ignorée, ou valeur invalide que le code
     * avale sans broncher (ex. un `timezone` non-UTC qui décale les bornes
     * tarifaires, #16). Les sections absentes et clés inconnues restent muettes en
     * prod (bruit inutile — ex. le config `database`-only de la CI en émettrait à
     * chaque requête).
     */
    public function isRuntimeSignal(): bool
    {
        return $this->isError()
            || $this->kind === self::KIND_SENTINEL
            || $this->kind === self::KIND_MOVED
            || $this->kind === self::KIND_INVALID_VALUE;
    }
}
