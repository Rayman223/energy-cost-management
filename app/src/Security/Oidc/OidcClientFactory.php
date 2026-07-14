<?php

declare(strict_types=1);

namespace App\Security\Oidc;

use Jumbojett\OpenIDConnectClient;

/**
 * Construit un client OpenID Connect générique (Authorization Code + PKCE S256)
 * à partir de la configuration `oidc`.
 */
final class OidcClientFactory
{
    /**
     * Normalise le bloc `oidc` en une liste `clé fournisseur → bloc fournisseur`.
     *
     * Deux formes acceptées :
     *  - **multi-fournisseurs** : `$oidcConfig['providers']` est un array non
     *    vide. Seules les entrées à clé string (bornée par `VARCHAR(60)` de
     *    `users.provider`) avec `issuer` **et** `client_id` non vides sont
     *    conservées ; les blocs incomplets sont ignorés silencieusement.
     *  - **plate historique** : pas de `providers` mais un `issuer` à la racine.
     *    Le bloc entier fait office de fournisseur unique, dont la clé implicite
     *    est dérivée via {@see self::providerLabel()} (rétro-compat : une install
     *    Google existante continue d'écrire `provider = 'google'`).
     *
     * @param array<string, mixed> $oidcConfig Bloc « oidc » complet.
     * @return array<string, array<string, mixed>> Clé fournisseur → bloc fournisseur.
     */
    public static function providersFromConfig(array $oidcConfig): array
    {
        $providers = $oidcConfig['providers'] ?? null;
        if (is_array($providers) && $providers !== []) {
            $clean = [];
            foreach ($providers as $key => $block) {
                // Tolérant à la casse : une clé « Microsoft » est normalisée en
                // « microsoft » (icône du bouton + valeur users.provider cohérentes)
                // plutôt que rejetée silencieusement.
                $key = is_string($key) ? strtolower($key) : '';
                if (preg_match('/^[a-z0-9_-]{1,60}$/', $key) !== 1) {
                    continue;
                }
                if (!is_array($block)) {
                    continue;
                }
                $issuer = $block['issuer'] ?? null;
                $clientId = $block['client_id'] ?? null;
                if (!is_string($issuer) || $issuer === '' || !is_string($clientId) || $clientId === '') {
                    continue;
                }
                $clean[$key] = $block;
            }

            return $clean;
        }

        $issuer = $oidcConfig['issuer'] ?? null;
        if (is_string($issuer) && $issuer !== '') {
            $key = self::providerLabel($issuer);
            if ($key === '') {
                $key = 'oidc';
            }

            return [$key => $oidcConfig];
        }

        return [];
    }

    /**
     * Libellé d'affichage par clé fournisseur : `label` de config s'il est
     * renseigné, sinon la clé capitalisée. Source unique partagée par la page
     * de connexion et la page compte (liaison d'identités #137).
     *
     * @param array<string, mixed> $oidcConfig Bloc « oidc » complet.
     * @return array<string, string> Clé fournisseur → libellé d'affichage.
     */
    public static function labelsFromConfig(array $oidcConfig): array
    {
        $labels = [];
        foreach (self::providersFromConfig($oidcConfig) as $key => $block) {
            $label = (string) ($block['label'] ?? '');
            $labels[$key] = $label !== '' ? $label : ucfirst($key);
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $config Bloc d'un fournisseur (issue de {@see self::providersFromConfig()}).
     */
    public static function fromConfig(array $config): OpenIDConnectClient
    {
        $issuer = (string) ($config['issuer'] ?? '');

        $client = new OpenIDConnectClient(
            $issuer,
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
        );

        $redirectUri = (string) ($config['redirect_uri'] ?? '');
        if ($redirectUri !== '') {
            $client->setRedirectURL($redirectUri);
        }

        $client->setCodeChallengeMethod('S256');
        $client->addScope(self::scopes($config));

        // Découverte OIDC mise en cache par issuer : injectée telle quelle, la lib
        // ne re-télécharge plus le well-known à chaque initiation/callback. On EXCLUT
        // la clé `issuer` du document afin de préserver l'issuer configuré (et donc la
        // validation, dont l'assouplissement Microsoft ci-dessous). Cache absent/
        // expiré/corrompu → découverte à la volée (comportement historique).
        if ($issuer !== '') {
            $discovery = (new OidcDiscoveryCache())->get($issuer);
            if ($discovery !== null) {
                unset($discovery['issuer']);
                if ($discovery !== []) {
                    $client->providerConfigParam($discovery);
                }
            }
        }

        // Microsoft/Entra multi-tenant : l'issuer configuré (common/organizations/
        // consumers) ne correspond jamais à l'issuer réel des jetons, qui contient
        // le GUID du tenant de l'utilisateur. On assouplit la validation à tout
        // tenant login.microsoftonline.com valide (cf. app/docs/oidc-microsoft.md).
        if (preg_match('#^https://login\.microsoftonline\.com/(common|organizations|consumers)/v2\.0/?$#', $issuer) === 1) {
            $client->setIssuerValidator(
                static fn (string $iss): bool =>
                    preg_match('#^https://login\.microsoftonline\.com/[0-9a-fA-F-]{36}/v2\.0$#', $iss) === 1
            );
        }

        return $client;
    }

    /**
     * Libellé court du fournisseur dérivé de l'issuer (ex. accounts.google.com → google).
     */
    public static function providerLabel(string $issuer): string
    {
        $host = parse_url($issuer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $parts = explode('.', $host);
        $count = count($parts);

        return $count >= 2 ? $parts[$count - 2] : $host;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function scopes(array $config): array
    {
        $scopes = $config['scopes'] ?? null;
        if (!is_array($scopes)) {
            return ['openid', 'profile'];
        }

        $clean = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && $scope !== '') {
                $clean[] = $scope;
            }
        }

        return $clean === [] ? ['openid', 'profile'] : $clean;
    }
}
