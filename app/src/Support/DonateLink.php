<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compatibilité du nom historique : renvoie désormais la page /support interne.
 */
final class DonateLink
{
    /**
     * @deprecated Utiliser SupportLink::url().
     *
     * @param array<string, mixed> $config
     */
    public static function url(array $config): ?string
    {
        return SupportLink::url($config);
    }
}
