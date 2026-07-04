<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Résolution de la locale d'une requête web et persistance du choix explicite.
 *
 * Ordre de préférence : `?lang=` explicite > profil utilisateur > cookie `lang`
 * > en-tête Accept-Language > défaut. Un `?lang=` prime pour que le sélecteur de
 * langue agisse dès le clic (avant que le nouveau choix ne soit relu du profil).
 * Un `?lang=` valide est mémorisé dans un cookie (1 an) pour rester d'une page à
 * l'autre.
 */
final class Locale
{
    /**
     * @param array<string, mixed> $config
     */
    public static function resolve(array $config, ?string $profileLocale = null): string
    {
        [$available, $default] = self::settings($config);

        $query  = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : null;
        $cookie = is_string($_COOKIE['lang'] ?? null) ? $_COOKIE['lang'] : null;
        $accept = is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

        self::persistChoice($query, $available);

        // Un ?lang explicite (l'utilisateur vient de cliquer) prime immédiatement,
        // puis le profil, puis le cookie mémorisé, puis Accept-Language, puis défaut.
        $normalizedQuery = null;
        if ($query !== null) {
            $candidate = strtolower(substr(trim($query), 0, 2));
            if (in_array($candidate, $available, true)) {
                $normalizedQuery = $candidate;
            }
        }

        return LocaleResolver::resolve($normalizedQuery ?? $profileLocale, $cookie, $accept, $available, $default);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function available(array $config): array
    {
        return self::settings($config)[0];
    }

    /**
     * Choix de langue explicite et valide de l'utilisateur (`?lang=`), ou null.
     *
     * Renvoie la locale normalisée uniquement si `?lang=` est présent ET
     * correspond à une locale disponible. Sert à ne persister le choix en
     * profil que sur une sélection réelle (jamais sur un `?lang` vide/inconnu).
     *
     * @param array<string, mixed> $config
     */
    public static function explicitChoice(array $config): ?string
    {
        $query = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : null;
        if ($query === null) {
            return null;
        }

        $candidate = strtolower(substr(trim($query), 0, 2));

        return in_array($candidate, self::available($config), true) ? $candidate : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: list<string>, 1: string}
     */
    private static function settings(array $config): array
    {
        $i18n = is_array($config['i18n'] ?? null) ? $config['i18n'] : [];

        $available = ['fr', 'en'];
        if (isset($i18n['available']) && is_array($i18n['available'])) {
            $clean = [];
            foreach ($i18n['available'] as $loc) {
                if (is_string($loc) && $loc !== '') {
                    $clean[] = $loc;
                }
            }
            if ($clean !== []) {
                $available = $clean;
            }
        }

        $default = is_string($i18n['default_locale'] ?? null) ? $i18n['default_locale'] : 'fr';

        return [$available, $default];
    }

    /**
     * @param list<string> $available
     */
    private static function persistChoice(?string $query, array $available): void
    {
        if ($query === null || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $normalized = strtolower(substr(trim($query), 0, 2));
        if (!in_array($normalized, $available, true)) {
            return;
        }

        setcookie('lang', $normalized, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'httponly' => false, // lisible côté client pour d'éventuels usages JS
            'samesite' => 'Lax',
        ]);
    }
}
