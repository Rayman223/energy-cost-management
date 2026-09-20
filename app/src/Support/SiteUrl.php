<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\Session;
use App\Security\WebAccessGuard;

/**
 * URL **absolues** du site — origine canonique et liens pleinement qualifiés.
 *
 * Pendant de {@see Url} (liens internes relatifs) pour les cas où une URL
 * relative ne suffit pas : `<link rel="canonical">`, `hreflang`, Open Graph,
 * `sitemap.xml` (la spécification exige des `<loc>` absolus), ligne `Sitemap:`
 * de `robots.txt`, et la commande curl copiable de `/api-guide`.
 *
 * Trois sources d'hôte, par ordre de préférence décroissante (#84) :
 *
 *  1. `seo.base_url` de `config.php` — **la seule source fiable pour un moteur
 *     de recherche**. Un canonical dérivé du `Host` de la requête consacrerait
 *     comme canonique l'hôte par lequel le crawler est entré (www, IP, alias),
 *     exactement le symptôme signalé par Search Console.
 *  2. `web_security.trusted_hosts[0]` — déjà traité comme l'hôte canonique par
 *     l'anti-spoof historique du guide API.
 *  3. Le `Host` de la requête, validé par forme ; repli `localhost`.
 *
 * Le schéma suit {@see Session::isHttps()} (X-Forwarded-Proto honoré : SWAG
 * termine le TLS), et le chemin est préfixé par
 * {@see WebAccessGuard::appRootPath()} pour rester correct en déploiement sous
 * un sous-répertoire.
 */
final class SiteUrl
{
    /** Forme acceptable d'un `Host` (nom + port optionnel), anti-spoof. */
    private const HOST_PATTERN = '/^[A-Za-z0-9.\-]+(:[0-9]+)?$/';

    /**
     * Origine du site, sans barre finale (ex. `https://energy-cost.eu`).
     *
     * @param array<string, mixed> $config
     */
    public static function origin(array $config): string
    {
        $configured = ConfigUrl::httpUrl($config, 'seo', 'base_url');
        if ($configured !== null) {
            $scheme = (string) parse_url($configured, PHP_URL_SCHEME);
            $host   = (string) parse_url($configured, PHP_URL_HOST);
            $port   = parse_url($configured, PHP_URL_PORT);

            if ($host !== '') {
                return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
            }
        }

        return (Session::isHttps() ? 'https' : 'http') . '://' . self::host($config);
    }

    /**
     * URL absolue d'un chemin de page interne (mêmes entrées que {@see Url::to()} :
     * `''` pour l'accueil, `'stats'`, `'auth/login'`…).
     *
     * Un `$query` non vide est ajouté tel quel, déjà encodé par l'appelant
     * (typiquement via `http_build_query()`).
     *
     * @param array<string, mixed> $config
     */
    public static function absolute(array $config, string $path, string $query = ''): string
    {
        $url = self::origin($config) . Url::to($path);

        return $query === '' ? $url : $url . '?' . $query;
    }

    /**
     * Hôte retenu quand `seo.base_url` n'est pas configurée.
     *
     * Reproduit l'anti-spoof de `/api-guide` : avec une allowlist
     * `web_security.trusted_hosts`, un `Host` non listé retombe sur le premier
     * hôte déclaré ; sans allowlist, seul un `Host` de forme sûre est accepté.
     *
     * @param array<string, mixed> $config
     */
    private static function host(array $config): string
    {
        $security     = is_array($config['web_security'] ?? null) ? $config['web_security'] : [];
        $trustedHosts = array_values(array_filter(
            is_array($security['trusted_hosts'] ?? null) ? $security['trusted_hosts'] : [],
            'is_string',
        ));

        $requestedHost = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($trustedHosts !== []) {
            return in_array($requestedHost, $trustedHosts, true) ? $requestedHost : $trustedHosts[0];
        }

        return preg_match(self::HOST_PATTERN, $requestedHost) === 1 ? $requestedHost : 'localhost';
    }
}
