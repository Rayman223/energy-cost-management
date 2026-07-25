<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\Session;
use App\Support\Adsense;

/**
 * En-têtes de sécurité communs pour les réponses web.
 *
 * Les en-têtes « sûrs » sont posés en mode strict. La CSP est **enforced** et,
 * par défaut, **stricte** : plus aucun script ni style inline (tout le CSS/JS
 * vit dans `assets/`, l'état serveur passe par un data block
 * `<script type="application/json">`). `script-src`/`style-src` n'autorisent
 * donc ni `'nonce-…'` ni `'unsafe-inline'` ; aucun gestionnaire inline `on*`
 * ne subsiste (délégation via addEventListener). Voir #47/#73/#98.
 *
 * **Exception assumée — publicité (#185)** : quand AdSense est activé en
 * configuration ({@see Adsense::isEnabled()}), la politique s'élargit aux
 * origines Google et doit inclure `'unsafe-inline'` dans `style-src`, car les
 * Auto ads et le CMP de consentement injectent leurs propres styles inline
 * (bandeau d'ancrage, vignettes, boîte de consentement). C'est un
 * affaiblissement réel : il est **confiné au cas « pub activée »**, si bien
 * qu'un déploiement sans publicité conserve exactement la politique stricte
 * d'origine. `script-src` reste sans `'unsafe-inline'` dans les deux cas.
 */
final class SecurityHeaders
{
    /**
     * Origines Google nécessaires au chargement des Auto ads et du CMP,
     * ajoutées à la politique uniquement quand la publicité est activée.
     *
     * @var array<string, list<string>> directive → sources supplémentaires
     */
    private const ADSENSE_SOURCES = [
        'script-src' => [
            'https://pagead2.googlesyndication.com',
            'https://partner.googleadservices.com',
            'https://tpc.googlesyndication.com',
            'https://adservice.google.com',
            'https://fundingchoicesmessages.google.com',
            'https://*.adtrafficquality.google',
        ],
        'frame-src' => [
            'https://googleads.g.doubleclick.net',
            'https://tpc.googlesyndication.com',
            'https://*.safeframe.googlesyndication.com',
            'https://www.google.com',
        ],
        'connect-src' => [
            'https://pagead2.googlesyndication.com',
            'https://googleads.g.doubleclick.net',
            'https://fundingchoicesmessages.google.com',
            'https://*.adtrafficquality.google',
        ],
    ];

    /**
     * @param array<string, mixed>|null $config Configuration applicative, quand
     *        elle est disponible : elle seule permet de savoir si la CSP doit
     *        être élargie à la publicité. Omise (dégradation 503 posée avant que
     *        le bootstrap ait pu aboutir), la politique stricte s'applique.
     */
    public static function send(?array $config = null): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (Session::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header('Content-Security-Policy: ' . self::contentSecurityPolicy(
            $config !== null && Adsense::isEnabled($config),
        ));
    }

    /**
     * Construit la valeur de l'en-tête `Content-Security-Policy` (méthode pure,
     * testable). Sans publicité : politique stricte, aucune source inline.
     *
     * @param bool $adsEnabled Élargit la politique aux origines AdSense/CMP.
     */
    public static function contentSecurityPolicy(bool $adsEnabled = false): string
    {
        $scriptSrc  = "'self' https://cdn.jsdelivr.net";
        $styleSrc   = "'self' https://fonts.googleapis.com";
        $imgSrc     = "'self' data:";
        $connectSrc = "'self'";
        $frameSrc   = '';

        if ($adsEnabled) {
            $scriptSrc .= ' ' . implode(' ', self::ADSENSE_SOURCES['script-src']);
            // Auto ads et le CMP injectent des styles inline dans la page.
            $styleSrc .= " 'unsafe-inline'";
            // Les créations proviennent de domaines annonceurs arbitraires :
            // impossible d'en dresser la liste à l'avance.
            $imgSrc .= ' https:';
            $connectSrc .= ' ' . implode(' ', self::ADSENSE_SOURCES['connect-src']);
            // Sans publicité, l'absence de `frame-src` laisse `default-src 'self'`
            // s'appliquer — inutile de la déclarer.
            $frameSrc = 'frame-src ' . implode(' ', self::ADSENSE_SOURCES['frame-src']) . '; ';
        }

        return "default-src 'self'; "
            . 'script-src ' . $scriptSrc . '; '
            . 'style-src ' . $styleSrc . '; '
            . "font-src 'self' https://fonts.gstatic.com; "
            . 'img-src ' . $imgSrc . '; '
            . 'connect-src ' . $connectSrc . '; '
            . $frameSrc
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'";
    }
}
