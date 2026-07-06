<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lecture d'arguments CLI au format `--clé=valeur`. Centralise le parsing
 * dupliqué entre les scripts d'import et {@see \App\Security\UserContext}.
 */
final class CliArguments
{
    /**
     * Renvoie la valeur de `--{$name}=...` dans `$argv`, ou null si absente.
     *
     * @param array<int, mixed> $argv
     */
    public static function value(array $argv, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($argv as $arg) {
            $arg = (string) $arg;
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }
}
