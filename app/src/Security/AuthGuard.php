<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\JsonResponse;
use App\Infrastructure\Database;

/**
 * Garde d'accès unifié.
 *
 * - OIDC désactivé : comportement historique (allowlist IP + Basic Auth) via
 *   {@see WebAccessGuard::protect()} — strictement non-cassant.
 * - OIDC activé : allowlist IP conservée, puis exige une session authentifiée ;
 *   sinon redirection vers la page de connexion /login (HTML) ou 401 JSON.
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

        $userId = AuthSession::userId();
        if ($userId !== null) {
            self::enforceActiveAccount($config, $userId, $jsonResponse);

            return;
        }

        self::requireLogin($jsonResponse);
    }

    /**
     * Révoque immédiatement l'accès d'un compte bloqué (ou supprimé) même si sa
     * session est encore ouverte : la connexion vérifie déjà `isActive()`, mais
     * un blocage administratif doit prendre effet dès la requête suivante.
     *
     * Tolérant aux pannes : si la base est indisponible, on ne verrouille pas
     * tout le monde (dégradation gracieuse, cohérente avec le reste de l'app).
     *
     * @param array<string, mixed> $config
     */
    private static function enforceActiveAccount(array $config, int $userId, bool $jsonResponse): void
    {
        $database = $config['database'] ?? null;
        if (!is_array($database)) {
            return;
        }

        try {
            $stmt = (new Database($database))->pdo()->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $status = $stmt->fetchColumn();
        } catch (\Throwable) {
            return; // Base injoignable : ne pas verrouiller abusivement.
        }

        // Compte encore actif → rien à faire.
        if ($status === 'active') {
            return;
        }

        // Bloqué ou disparu : on ferme la session et on exige une reconnexion.
        AuthSession::logout();
        self::requireLogin($jsonResponse);
    }

    private static function requireLogin(bool $jsonResponse): void
    {
        if ($jsonResponse) {
            JsonResponse::error('Authentication required', 401)->send();
            exit;
        }

        // Page de connexion brandée (bouton fournisseur) plutôt que la route
        // /auth/login qui rebondit immédiatement vers l'IdP.
        $next = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $target = WebAccessGuard::basePath() . '/login?next=' . urlencode($next);

        header('Location: ' . $target, true, 302);
        exit;
    }
}
