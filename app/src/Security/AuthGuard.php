<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\JsonResponse;

/**
 * Garde d'accès unifié.
 *
 * - OIDC désactivé : comportement historique (allowlist IP + Basic Auth) via
 *   {@see WebAccessGuard::protect()} — strictement non-cassant.
 * - OIDC activé : allowlist IP conservée, puis exige une session authentifiée ;
 *   sinon redirection vers /auth/login.php (HTML) ou 401 JSON.
 *
 * @phpstan-param array<string, mixed> $config
 */
final class AuthGuard
{
    /**
     * @param array<string, mixed> $config Configuration complète de l'application.
     */
    public static function protect(array $config, bool $jsonResponse = false): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        $security = $config['web_security'] ?? [];
        if (!is_array($security)) {
            $security = [];
        }

        $oidc = $config['oidc'] ?? [];
        $oidcEnabled = is_array($oidc) && ($oidc['enabled'] ?? false) === true;

        if ($oidcEnabled === false) {
            WebAccessGuard::protect($security, $jsonResponse);

            return;
        }

        WebAccessGuard::enforceIp($security, $jsonResponse);

        if (AuthSession::userId() !== null) {
            return;
        }

        self::requireLogin($jsonResponse);
    }

    private static function requireLogin(bool $jsonResponse): void
    {
        if ($jsonResponse) {
            JsonResponse::error('Authentication required', 401)->send();
            exit;
        }

        $next = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $target = WebAccessGuard::basePath() . '/auth/login.php?next=' . urlencode($next);

        header('Location: ' . $target, true, 302);
        exit;
    }
}
