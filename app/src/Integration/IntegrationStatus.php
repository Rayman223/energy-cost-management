<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * État d'un connecteur d'export pour un utilisateur, prêt à être rendu par la
 * carte « Mon compte ». Le générique ne connaît que l'état et une liste de
 * lignes de statut libres (clé i18n + params + éventuel fragment monospace) :
 * chaque module reste maître de la sémantique de ses détails (ex. claim EnergyID).
 */
final class IntegrationStatus
{
    public const DISABLED = 'disabled';
    public const PENDING  = 'pending';
    public const ACTIVE   = 'active';

    /**
     * @param self::DISABLED|self::PENDING|self::ACTIVE $state
     * @param list<array{key: string, params: array<string, string>, code: string|null}> $lines
     */
    public function __construct(
        public readonly string $state,
        public readonly array $lines = [],
    ) {
    }

    /**
     * @param array<string, string> $params
     * @return array{key: string, params: array<string, string>, code: string|null}
     */
    public static function line(string $key, array $params = [], ?string $code = null): array
    {
        return ['key' => $key, 'params' => $params, 'code' => $code];
    }
}
