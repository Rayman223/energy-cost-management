<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validation centralisée des cibles de redirection internes (`$next`) contre
 * l'open redirect et l'injection d'en-têtes. Factorise la garde jusqu'ici
 * dupliquée dans les routes de connexion et {@see \App\Security\AuthGuard}.
 */
final class SafeRedirect
{
    /**
     * Retourne $next s'il désigne un chemin interne sûr, sinon $fallback.
     *
     * Rejette (→ $fallback) : chaîne vide ; ne commençant pas par « / » ;
     * commençant par « // » (URL protocole-relative) ; contenant « \ » (les
     * navigateurs normalisent « /\evil.com » en « //evil.com ») ; contenant un
     * saut de ligne CR/LF (injection d'en-tête via l'en-tête Location).
     */
    public static function sanitize(string $next, string $fallback): string
    {
        if (
            $next === ''
            || str_starts_with($next, '/') === false
            || str_starts_with($next, '//')
            || str_contains($next, '\\')
            || str_contains($next, "\r")
            || str_contains($next, "\n")
        ) {
            return $fallback;
        }

        return $next;
    }
}
