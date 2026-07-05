<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\Session;

/**
 * En-têtes de sécurité communs pour les réponses web.
 *
 * Les en-têtes « sûrs » sont posés en mode strict. La CSP est **enforced** :
 * les scripts inline légitimes (snippet anti-FOUC de thème, données `window.__INIT_*__`)
 * portent le nonce par requête exposé par {@see self::nonce()} ; aucun gestionnaire
 * inline `on*` ne subsiste (délégation via addEventListener). Voir #47/#73.
 */
final class SecurityHeaders
{
    /** Nonce CSP mémoïsé, unique par requête (généré à la 1re demande). */
    private static ?string $nonce = null;

    /**
     * Nonce CSP de la requête courante (base64, 128 bits d'entropie).
     *
     * Généré une seule fois puis mémoïsé : `send()` l'injecte dans l'en-tête et
     * les templates le posent sur chaque `<script>` inline via `nonce="…"`.
     * Indépendant des retours anticipés de `send()` (fonctionne en CLI/tests).
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

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

        header('Content-Security-Policy: ' . self::contentSecurityPolicy(self::nonce()));
    }

    /**
     * Construit la valeur de l'en-tête `Content-Security-Policy` (méthode pure,
     * testable). Le nonce autorise les `<script>` inline légitimes de l'app.
     */
    public static function contentSecurityPolicy(string $nonce): string
    {
        return "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net 'nonce-" . $nonce . "'; "
            . "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'";
    }
}
