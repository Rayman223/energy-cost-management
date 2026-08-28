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

        $client->addScope(self::additionalScopes($config));

        // Discord : exiger un consentement frais. Une application déjà autorisée
        // par l'utilisateur — typiquement lors d'un essai antérieur, avec des
        // scopes qui n'incluaient pas « openid » — voit sinon son écran de
        // consentement court-circuité, et Discord renvoie un jeton SANS id_token :
        // la lib échoue alors sur « User did not authorize openid scope. » et
        // l'utilisateur ne voit qu'un échec de connexion (#25).
        if (self::isDiscord($issuer)) {
            $client->addAuthParam(['prompt' => 'consent']);
        }

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

        // Complément de découverte, injecté APRÈS le document découvert : la lib
        // fusionne (array_merge) et celui-ci l'écraserait sinon.
        $overrides = self::discoveryOverrides($issuer);
        if ($overrides !== []) {
            $client->providerConfigParam($overrides);
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
     * Scopes demandés à l'IdP : ceux de la config s'ils sont exploitables, sinon
     * le repli de {@see self::defaultScopes()}. Publique pour être testable sans
     * construire de client (ce que {@see self::fromConfig()} ne permet pas : la
     * découverte OIDC y déclenche un aller-retour réseau).
     *
     * @param array<string, mixed> $config Bloc d'un fournisseur.
     * @return list<string>
     */
    public static function scopesFromConfig(array $config): array
    {
        $issuer = (string) ($config['issuer'] ?? '');

        $scopes = $config['scopes'] ?? null;
        if (!is_array($scopes)) {
            return self::defaultScopes($issuer);
        }

        $clean = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && $scope !== '') {
                $clean[] = $scope;
            }
        }

        return $clean === [] ? self::defaultScopes($issuer) : $clean;
    }

    /**
     * Corrections à appliquer au document de découverte d'un IdP qui décrit mal
     * ses propres capacités.
     *
     * Discord **supporte** PKCE S256 sans l'annoncer. Or la lib ne joint un
     * `code_challenge` à la demande d'autorisation que si l'IdP le déclare — tout
     * en envoyant au token endpoint le `code_verifier` qu'elle trouve en session,
     * fût-il le résidu d'une connexion précédente ({@see OidcSessionState}).
     * Discord recevait donc un verifier sans challenge et refusait l'échange sur
     * « Code challenge failed » (#25). Déclarer la capacité rend le flux cohérent
     * — et PKCE réellement actif.
     *
     * @return array<string, mixed> Vide quand la découverte de l'IdP se suffit.
     */
    public static function discoveryOverrides(string $issuer): array
    {
        return self::isDiscord($issuer) ? ['code_challenge_methods_supported' => ['S256']] : [];
    }

    /**
     * Scopes à déclarer à la lib, c'est-à-dire ceux de la config **privés de
     * `openid`** : la lib le rajoute systématiquement en fin de liste
     * (`requestAuthorization()` → `array_merge($this->scopes, ['openid'])`). Le
     * lui passer produirait un `scope=openid identify openid` dupliqué, que les
     * IdP stricts refusent en `invalid_scope` (#25).
     *
     * @param array<string, mixed> $config Bloc d'un fournisseur.
     * @return list<string>
     */
    public static function additionalScopes(array $config): array
    {
        $additional = [];
        foreach (self::scopesFromConfig($config) as $scope) {
            if ($scope !== 'openid') {
                $additional[] = $scope;
            }
        }

        return $additional;
    }

    /**
     * Repli quand `scopes` n'est pas configuré. `openid` + `profile` partout,
     * SAUF Discord : son OAuth2 ignore le scope OIDC `profile` et rejette la
     * demande en `invalid_scope` — l'équivalent maison est `identify`, qui
     * débloque `preferred_username`/`nickname` sur /api/oauth2/userinfo
     * (cf. app/docs/oidc-discord.md).
     *
     * @return list<string>
     */
    private static function defaultScopes(string $issuer): array
    {
        return self::isDiscord($issuer) ? ['openid', 'identify'] : ['openid', 'profile'];
    }

    /**
     * L'issuer désigne-t-il Discord ? Comparaison sur l'hôte seul : un
     * `https://notdiscord.com` ne doit pas hériter des adaptations Discord.
     */
    public static function isDiscord(string $issuer): bool
    {
        $host = parse_url($issuer, PHP_URL_HOST);

        return is_string($host) && ($host === 'discord.com' || str_ends_with($host, '.discord.com'));
    }
}
