<?php

declare(strict_types=1);

namespace App\Seo;

use App\I18n\Locale;
use App\Support\SiteUrl;

/**
 * Génération de `/sitemap.xml` (#84).
 *
 * Méthode pure, comme {@see RobotsTxt} : la route se borne au bootstrap, à la
 * collecte des pays et au rendu.
 *
 * **Une entrée par (page × locale), avec `?lang=`.** Un sitemap ne doit
 * contenir que des URLs canoniques ; or le canonical d'une page est
 * auto-référent et porte la locale rendue ({@see PageMeta}). Lister `/stats`
 * tout court y contredirait la page elle-même, qui se déclare canonique sous
 * `/stats?lang=xx`.
 *
 * Les pages légales y figurent : peu lues, mais ce sont des pages réelles, et
 * AdSense comme Search Console attendent de les trouver.
 */
final class Sitemap
{
    /**
     * Pages publiques permanentes, dans l'ordre d'importance décroissante.
     * Les variantes par pays de `/stats` s'y ajoutent dynamiquement.
     *
     * @var list<string>
     */
    private const PATHS = ['', 'stats', 'privacy', 'terms', 'cookies', 'legal-notice'];

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $countries Codes pays (ISO 3166-1 alpha-2) dont les
     *        agrégats sont réellement publiés. Un pays sous le seuil d'anonymat
     *        n'a rien à montrer : l'inscrire produirait une page vide au crawl.
     */
    public static function render(array $config, array $countries = []): string
    {
        $locales = Locale::available($config);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach (self::urls($config, $locales, $countries) as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $locales
     * @param list<string>         $countries
     * @return list<string>
     */
    private static function urls(array $config, array $locales, array $countries): array
    {
        /** @var list<array{path: string, query: array<string, string>}> $pages */
        $pages = [];
        foreach (self::PATHS as $path) {
            $pages[] = ['path' => $path, 'query' => []];
        }
        foreach ($countries as $country) {
            $pages[] = ['path' => 'stats', 'query' => ['country' => $country]];
        }

        $urls = [];
        foreach ($pages as $page) {
            foreach ($locales as $locale) {
                $urls[] = SiteUrl::absolute(
                    $config,
                    $page['path'],
                    http_build_query($page['query'] + ['lang' => $locale]),
                );
            }
        }

        return $urls;
    }
}
