<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Protection CSRF par jeton de synchronisation (synchronizer token) stocké en
 * session. Les formulaires HTML insèrent le champ caché via {@see field()} ;
 * les handlers POST valident avec {@see validate()}.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Session::start();

        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($token) === false || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function validate(mixed $provided): bool
    {
        Session::start();

        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected)
            ? self::matches($expected, is_string($provided) ? $provided : null)
            : false;
    }

    public static function matches(string $expected, ?string $provided): bool
    {
        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Champ caché à insérer dans un <form>. Le jeton est hexadécimal,
     * donc sûr à injecter tel quel dans l'attribut value.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }
}
