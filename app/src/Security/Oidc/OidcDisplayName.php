<?php

declare(strict_types=1);

namespace App\Security\Oidc;

/**
 * Nom d'affichage d'un compte, extrait des claims OIDC.
 *
 * Le claim `name` n'est pas garanti : Discord ne le fournit jamais (il expose
 * `preferred_username`/`nickname`, cf. app/docs/oidc-discord.md) et un Keycloak
 * sans prénom/nom renseigné ne le remplit pas davantage. Sans repli, ces comptes
 * seraient créés avec un `users.display_name` vide.
 *
 * Les trois claims lus sont standards (OpenID Connect Core, section 5.1), donc
 * le repli profite à tout IdP conforme et pas seulement à Discord.
 */
final class OidcDisplayName
{
    /** Claims candidats, du plus explicite au plus approximatif. */
    private const CLAIMS = ['name', 'preferred_username', 'nickname'];

    /**
     * Premier claim exploitable de l'id_token, sinon du userinfo. Retourne `''`
     * quand aucune source ne convient — {@see \App\Security\AccountProvisioner}
     * n'écrase jamais un nom existant par une chaîne vide.
     *
     * @param object|null $verified Claims vérifiés de l'id_token (jumbojett : `getVerifiedClaims()`).
     * @param object|null $userInfo Réponse du userinfo endpoint (jumbojett : `requestUserInfo()`).
     */
    public static function fromClaims(?object $verified, ?object $userInfo = null): string
    {
        foreach ([$verified, $userInfo] as $source) {
            $name = self::pick($source);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Premier claim non vide d'une source unique (les claims OIDC arrivent en
     * objet JSON décodé : accès par propriété, jamais par index de tableau).
     */
    private static function pick(?object $source): string
    {
        if ($source === null) {
            return '';
        }

        foreach (self::CLAIMS as $claim) {
            if (!property_exists($source, $claim)) {
                continue;
            }

            $value = $source->{$claim};
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
