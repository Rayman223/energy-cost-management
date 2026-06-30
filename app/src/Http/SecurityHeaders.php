<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\Session;

/**
 * En-têtes de sécurité communs pour les réponses web.
 *
 * Les en-têtes « sûrs » sont posés en mode strict. La CSP est en
 * Report-Only : l'application utilise encore des gestionnaires inline
 * (onclick…) ; l'enforcement de la CSP et le nettoyage des handlers sont
 * planifiés en P7 (voir #47).
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

        header(
            'Content-Security-Policy-Report-Only: '
            . "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'"
        );
    }
}
