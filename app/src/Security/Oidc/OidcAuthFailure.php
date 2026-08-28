<?php

declare(strict_types=1);

namespace App\Security\Oidc;

/**
 * Trace d'un échec d'authentification OpenID Connect.
 *
 * La page d'erreur reste volontairement muette (un visiteur non authentifié n'a
 * pas à connaître la mécanique de l'IdP) — mais sans trace côté serveur, une
 * panne de connexion est indiagnosticable : c'est ce qui a rendu #25 opaque, la
 * cause réelle (« User did not authorize openid scope. », `invalid_client`,
 * signature invalide…) étant avalée par le catch de la route.
 *
 * On journalise donc la cause, et on affiche à l'utilisateur un identifiant de
 * corrélation — la seule information technique exposée — pour que l'admin
 * retrouve la ligne correspondante dans le log PHP.
 */
final class OidcAuthFailure
{
    /** Longueur maximale du message d'exception recopié dans le log. */
    private const MAX_MESSAGE = 300;

    /**
     * Identifiant court, aléatoire et non devinable, partagé entre la page
     * d'erreur et la ligne de log.
     */
    public static function reference(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Ligne de log décrivant l'échec : référence, fournisseur, étape du flux et
     * cause. Le message de l'IdP est conservé (il porte le diagnostic : code
     * d'erreur OAuth, claim manquant…) mais tronqué et remis sur une seule ligne
     * pour ne pas éclater le log.
     *
     * Aucun secret n'y transite : ni le code d'autorisation, ni le `state`, ni
     * le client_secret ne sont recopiés.
     *
     * @param string $reference  Identifiant issu de {@see self::reference()}, affiché à l'utilisateur.
     * @param string $provider   Clé du fournisseur (google, discord…), vide si non encore résolue.
     * @param bool   $isCallback true = retour de l'IdP, false = initiation de la connexion.
     */
    public static function describe(
        string $reference,
        \Throwable $e,
        string $provider,
        bool $isCallback,
    ): string {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
        $message = trim($message);
        if ($message === '') {
            $message = '(sans message)';
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE) . '…';
        }

        return sprintf(
            'OIDC auth failed [%s] provider=%s stage=%s %s: %s',
            $reference !== '' ? $reference : '(sans référence)',
            $provider !== '' ? $provider : '(inconnu)',
            $isCallback ? 'callback' : 'initiation',
            $e::class,
            $message,
        );
    }
}
