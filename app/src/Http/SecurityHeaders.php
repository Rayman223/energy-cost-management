<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\Session;

/**
 * En-têtes de sécurité communs pour les réponses web.
 *
 * Les en-têtes « sûrs » sont posés en mode strict. La CSP est **enforced** et
 * **stricte** : plus aucun script ni style inline (tout le CSS/JS vit dans
 * `assets/`, l'état serveur passe par un data block `<script type="application/json">`).
 * `script-src`/`style-src` n'autorisent donc ni `'nonce-…'` ni `'unsafe-inline'` ;
 * aucun gestionnaire inline `on*` ne subsiste (délégation via addEventListener).
 * Voir #47/#73/#98.
 */
final class SecurityHeaders
{
    public static function send(): void
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

        header('Content-Security-Policy: ' . self::contentSecurityPolicy());
    }

    /**
     * Construit la valeur de l'en-tête `Content-Security-Policy` (méthode pure,
     * testable). Politique stricte : aucune source inline autorisée.
     */
    public static function contentSecurityPolicy(): string
    {
        return "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'";
    }
}
