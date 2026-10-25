<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/** Charge la configuration principale et applique les surcharges locales privées. */
final class ConfigLoader
{
    /** @return array<string, mixed> */
    public static function load(string $basePath, ?string $overridePath = null): array
    {
        if (!is_file($basePath)) {
            throw new RuntimeException('Le fichier ' . $basePath . ' est manquant.');
        }

        $base = require $basePath;
        if (!is_array($base)) {
            throw new RuntimeException('Le fichier ' . $basePath . ' ne retourne pas un tableau de configuration.');
        }

        if ($overridePath === null || !is_file($overridePath)) {
            return $base;
        }

        $override = require $overridePath;
        if (!is_array($override)) {
            throw new RuntimeException('Le fichier ' . $overridePath . ' ne retourne pas un tableau de configuration.');
        }

        return self::merge($base, $override);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
