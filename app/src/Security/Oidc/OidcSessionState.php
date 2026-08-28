<?php

declare(strict_types=1);

namespace App\Security\Oidc;

/**
 * État transitoire qu'une connexion OpenID Connect laisse en session.
 *
 * La lib mémorise le `code_verifier` PKCE en session le temps de l'aller-retour
 * vers l'IdP — mais ne l'efface jamais ensuite : `unsetCodeVerifier()` existe
 * dans jumbojett et n'y est appelé nulle part. Le verifier d'un fournisseur
 * survit donc à sa propre connexion et contamine le flux suivant : la lib
 * l'envoie au token endpoint dès qu'une méthode de challenge est configurée,
 * même quand elle n'a joint aucun `code_challenge` à la demande d'autorisation
 * (cas d'un IdP qui ne déclare pas PKCE dans sa découverte). L'IdP reçoit alors
 * un verifier sans challenge et refuse l'échange — « Code challenge failed »
 * côté Discord, après une connexion Google, dans #25.
 *
 * On repart donc d'un état propre à chaque initiation.
 */
final class OidcSessionState
{
    /** Clé de session où la lib mémorise le `code_verifier` PKCE. */
    public const CODE_VERIFIER_KEY = 'openid_connect_code_verifier';

    /**
     * Oublie le `code_verifier` d'un flux antérieur. À n'appeler qu'à
     * l'**initiation** : au callback, le verifier de la demande en cours est
     * indispensable à l'échange du code.
     */
    public static function forgetCodeVerifier(): void
    {
        unset($_SESSION[self::CODE_VERIFIER_KEY]);
    }
}
