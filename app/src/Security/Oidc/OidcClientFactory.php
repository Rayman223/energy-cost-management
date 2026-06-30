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
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): OpenIDConnectClient
    {
        $client = new OpenIDConnectClient(
            (string) ($config['issuer'] ?? ''),
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
        );

        $redirectUri = (string) ($config['redirect_uri'] ?? '');
        if ($redirectUri !== '') {
            $client->setRedirectURL($redirectUri);
        }

        $client->setCodeChallengeMethod('S256');
        $client->addScope(self::scopes($config));

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
