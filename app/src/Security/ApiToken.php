<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Génération et vérification des jetons API (logique pure, testable).
 *
 * Format : `mec_` + 40 caractères hexadécimaux (160 bits d'entropie CSPRNG).
 * Seul le hash SHA-256 du jeton complet est stocké ; le préfixe (non secret)
 * sert à identifier le jeton dans l'UI.
 */
final class ApiToken
{
    public const TOKEN_PREFIX = 'mec_';

    /** Longueur du préfixe d'identification stocké (mec_ + 8 hex). */
    private const DISPLAY_PREFIX_LENGTH = 12;

    /**
     * @return array{token: string, hash: string, prefix: string}
     */
    public static function generate(): array
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(20));

        return [
            'token'  => $token,
            'hash'   => self::hash($token),
            'prefix' => substr($token, 0, self::DISPLAY_PREFIX_LENGTH),
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Forme plausible d'un jeton (évite un hit BDD sur des valeurs quelconques). */
    public static function looksValid(string $token): bool
    {
        return preg_match('/^mec_[0-9a-f]{40}$/', $token) === 1;
    }

    /** Extrait le jeton Bearer de l'en-tête Authorization (null si absent). */
    public static function fromAuthorizationHeader(?string $header): ?string
    {
        if ($header === null || stripos($header, 'bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
