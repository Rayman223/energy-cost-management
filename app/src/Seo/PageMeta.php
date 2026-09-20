<?php

declare(strict_types=1);

namespace App\Seo;

use App\I18n\Locale;
use App\Support\SiteUrl;

/**
 * Métadonnées de référencement d'une page, calculées par la route et rendues
 * par `partials/_head.php` (#84).
 *
 * Objet-valeur plutôt que six paramètres de template : le `<head>` est un point
 * d'émission unique, et la règle « quelles pages sont indexables » doit se lire
 * à un seul endroit. Le défaut est **fermé** — une page qui ne construit pas de
 * `PageMeta` est rendue `noindex` ({@see hidden()}), si bien qu'une nouvelle
 * page privée ne se retrouve pas indexée par simple oubli.
 *
 * **Stratégie multilingue.** Les quatre locales sont servies sur la même URL,
 * négociées par `?lang=` puis cookie puis `Accept-Language`
 * ({@see Locale::resolve()}). Pour un moteur, le contenu d'une URL doit être
 * stable : le canonical est donc **auto-référent avec `?lang=` explicite** (la
 * locale réellement rendue), et les quatre variantes sont reliées entre elles
 * par `hreflang`. Le `x-default` pointe l'URL sans paramètre, celle qui négocie
 * la langue du visiteur.
 *
 * @phpstan-type Alternate array{hreflang: string, href: string}
 */
final class PageMeta
{
    /**
     * `og:locale` par locale gérée. Open Graph attend une étiquette
     * `langue_TERRITOIRE`, que nos codes à deux lettres n'ont pas.
     */
    private const OG_LOCALES = [
        'fr' => 'fr_FR',
        'en' => 'en_GB',
        'nl' => 'nl_NL',
        'de' => 'de_DE',
    ];

    /**
     * @param list<Alternate> $alternates
     */
    private function __construct(
        public readonly string $robots,
        public readonly string $description,
        public readonly ?string $canonical,
        public readonly array $alternates,
        public readonly ?string $imageUrl,
        public readonly ?string $verification,
        public readonly string $ogLocale,
    ) {
    }

    /**
     * Page publique à indexer.
     *
     * @param array<string, mixed>  $config
     * @param string                $path        Chemin interne, au sens de {@see \App\Support\Url::to()} ('' = accueil)
     * @param string                $locale      Locale réellement rendue
     * @param string                $description Résumé traduit, 150-160 caractères visés
     * @param array<string, string> $query       Paramètres identifiants de la page (ex. `country`),
     *                                           reconduits dans le canonical et les alternates
     */
    public static function indexable(
        array $config,
        string $path,
        string $locale,
        string $description,
        array $query = [],
    ): self {
        $available = Locale::available($config);

        $alternates = [];
        foreach ($available as $alternate) {
            $alternates[] = [
                'hreflang' => $alternate,
                'href'     => SiteUrl::absolute($config, $path, http_build_query($query + ['lang' => $alternate])),
            ];
        }
        // x-default : l'URL sans `lang`, qui négocie la langue du visiteur.
        $alternates[] = [
            'hreflang' => 'x-default',
            'href'     => SiteUrl::absolute($config, $path, http_build_query($query)),
        ];

        return new self(
            robots: 'index, follow',
            description: $description,
            canonical: SiteUrl::absolute($config, $path, http_build_query($query + ['lang' => $locale])),
            alternates: $alternates,
            imageUrl: SiteUrl::absolute($config, 'assets/img/icon-512.png'),
            verification: self::verificationToken($config),
            ogLocale: self::OG_LOCALES[$locale] ?? $locale,
        );
    }

    /**
     * Page publique mais **sans intérêt à indexer** : page de connexion, page
     * d'erreur, ou variante paramétrée sans contenu (un pays dont aucun agrégat
     * ne franchit le seuil d'anonymat n'a rien à montrer à un moteur).
     *
     * `follow` est conservé : la page ne doit pas être indexée, mais ses liens
     * restent des chemins de découverte valides.
     *
     * @param array<string, mixed> $config
     */
    public static function hidden(array $config, string $description = ''): self
    {
        return new self(
            robots: 'noindex, follow',
            description: $description,
            canonical: null,
            alternates: [],
            imageUrl: null,
            verification: self::verificationToken($config),
            ogLocale: '',
        );
    }

    /**
     * Jeton de la balise meta de validation Search Console, ou null.
     *
     * Filtré sur `[A-Za-z0-9_-]` : cette valeur de configuration part dans un
     * attribut HTML, et Google n'émet que des jetons de cette forme.
     *
     * @param array<string, mixed> $config
     */
    private static function verificationToken(array $config): ?string
    {
        $seo = $config['seo'] ?? [];
        if (!is_array($seo)) {
            return null;
        }

        $token = $seo['google_site_verification'] ?? '';
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        return preg_match('/^[A-Za-z0-9_-]{10,128}$/', $token) === 1 ? $token : null;
    }
}
