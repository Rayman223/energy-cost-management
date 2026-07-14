<?php

declare(strict_types=1);

namespace App\Security;

/**
 * État d'authentification stocké en session : l'identifiant de l'utilisateur
 * connecté. S'appuie sur {@see Session} (cookies durcis).
 */
final class AuthSession
{
    private const KEY = 'user_id';

    public static function login(int $userId): void
    {
        Session::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::KEY] = $userId;
        // Force une revérification « compte actif » au premier hit protégé après
        // connexion (cf. AuthGuard::enforceActiveAccount, cache session TTL).
        unset($_SESSION[AuthGuard::STATUS_CHECK_KEY]);
    }

    public static function userId(): ?int
    {
        Session::start();
        $id = $_SESSION[self::KEY] ?? null;

        return is_int($id) ? $id : null;
    }

    public static function logout(): void
    {
        Session::start();
        unset($_SESSION[self::KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
