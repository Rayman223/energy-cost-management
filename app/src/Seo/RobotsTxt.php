<?php

declare(strict_types=1);

namespace App\Seo;

use App\Security\AuthGuard;
use App\Support\SiteUrl;
use App\Support\Url;

/**
 * Contenu de `/robots.txt` (#84).
 *
 * Méthode pure : la route ne fait que poser l'en-tête et l'afficher.
 *
 * Deux régimes, dictés par le mode d'authentification — la même distinction qui
 * fait que `/stats` n'est publique qu'en mode OIDC :
 *
 *  - **OIDC activé** (déploiement public, energy-cost.eu) : les pages publiques
 *    sont ouvertes au crawl, les chemins privés explicitement refusés, et le
 *    sitemap annoncé en URL absolue (la spécification l'exige).
 *  - **OIDC désactivé** (auto-hébergement mono-tenant) : l'instance entière est
 *    derrière une allowlist IP et Basic Auth. `Disallow: /`, et surtout aucune
 *    ligne `Sitemap:` — inutile d'indiquer à un tiers la carte d'un site auquel
 *    il n'a pas accès.
 */
final class RobotsTxt
{
    /**
     * Chemins jamais indexables : pages authentifiées, API et tunnel de
     * connexion. Liste blanche inversée volontaire — une nouvelle page publique
     * est crawlable sans rien changer ici, une nouvelle page privée doit y être
     * ajoutée (le `noindex` de {@see PageMeta} reste le garde-fou de fond).
     */
    private const DISALLOWED = [
        'account',
        'admin',
        'advances',
        'api',
        'api-guide',
        'auth/',
        'batteries',
        'login',
        'meter-readings',
        'meters',
        'reconciliation',
        'tariffs',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public static function render(array $config): string
    {
        if (!AuthGuard::isOidcEnabled($config)) {
            return "# Instance privée : accès restreint (allowlist IP + authentification).\n"
                . "User-agent: *\n"
                . "Disallow: /\n";
        }

        $lines = ["User-agent: *"];
        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: ' . Url::to($path);
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . SiteUrl::absolute($config, 'sitemap.xml');

        return implode("\n", $lines) . "\n";
    }
}
