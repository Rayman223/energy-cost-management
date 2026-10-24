<?php

declare(strict_types=1);

namespace App\Support;

/** Lien interne vers la page de soutien, uniquement sur les instances activées. */
final class SupportLink
{
    /** @param array<string, mixed> $config */
    public static function url(array $config): ?string
    {
        return SupportConfig::enabled($config) ? Url::to('support') : null;
    }
}
