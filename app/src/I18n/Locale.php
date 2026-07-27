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
    /** Noms d'affichage (endonymes) des locales gérées. */
    private const ENDONYMS = [
        'fr' => 'Français',
        'en' => 'English',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
    ];

    /**
     * Paramètres de query reconduits par {@see self::switchUrl()} : l'état de
     * page (onglet énergie, grille en cours d'édition/duplication, template
     * importé) qu'il serait pénible de perdre en changeant de langue.
     *
     * Liste blanche volontaire : les paramètres à usage unique — bandeaux
     * `?linked` / `?already` / `?link_error` de « Mon compte », déclencheur de
     * téléchargement `?export` — ne doivent PAS être rejoués. Une future page
     * qui oublierait d'y déclarer son état retombe sur l'ancien comportement
     * (paramètre simplement perdu), jamais sur un effet de bord.
     */
    private const CARRIED_PARAMS = ['edit', 'duplicate', 'energy', 'template', 'page'];

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
            $candidate = self::normalize($query);
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
     * Nom d'affichage (endonyme) d'une locale, pour les sélecteurs de langue.
     *
     * Les endonymes sont indépendants de la langue de l'UI (on affiche chaque
     * langue dans son propre nom) et sont donc volontairement non traduits.
     * Repli sur le code en majuscules pour toute locale non répertoriée.
     */
    public static function displayName(string $locale): string
    {
        return self::ENDONYMS[$locale] ?? strtoupper($locale);
    }

    /**
     * URL de bascule vers `$locale`, pour les liens du sélecteur de langue.
     *
     * Conserve le chemin courant (URL relative réduite à la query) et reconduit
     * l'état de page lu dans l'URL ({@see self::CARRIED_PARAMS}) : changer de
     * langue depuis l'onglet gaz d'une grille en édition y revient.
     *
     * `$extra` sert aux valeurs que l'URL courante ne porte pas forcément — la
     * cible post-connexion des pages de login, déjà validée côté route, qui
     * arrive en POST quand l'authentification vient d'échouer.
     *
     * @param array<string, string> $extra Paramètres ajoutés (priment sur l'URL).
     */
    public static function switchUrl(string $locale, array $extra = []): string
    {
        $query = array_intersect_key($_GET, array_flip(self::CARRIED_PARAMS));

        return '?' . http_build_query(array_merge($query, $extra, ['lang' => $locale]));
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

        $candidate = self::normalize($query);

        return in_array($candidate, self::available($config), true) ? $candidate : null;
    }

    /**
     * Normalise une valeur de langue brute en code 2 lettres minuscule
     * (`" FR "` → `"fr"`), forme comparée à la liste des locales disponibles.
     */
    private static function normalize(string $raw): string
    {
        return strtolower(substr(trim($raw), 0, 2));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: list<string>, 1: string}
     */
    private static function settings(array $config): array
    {
        $i18n = is_array($config['i18n'] ?? null) ? $config['i18n'] : [];

        $available = ['fr', 'en', 'nl', 'de'];
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

        $normalized = self::normalize($query);
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
