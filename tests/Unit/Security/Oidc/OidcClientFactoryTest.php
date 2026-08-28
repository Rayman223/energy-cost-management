<?php

declare(strict_types=1);

namespace Tests\Unit\Security\Oidc;

use App\Security\Oidc\OidcClientFactory;
use PHPUnit\Framework\TestCase;

final class OidcClientFactoryTest extends TestCase
{
    public function testFlatFormDerivesGoogleKey(): void
    {
        $providers = OidcClientFactory::providersFromConfig([
            'enabled'   => true,
            'issuer'    => 'https://accounts.google.com',
            'client_id' => 'abc',
        ]);

        self::assertSame(['google'], array_keys($providers));
        // Le bloc plat entier sert de bloc fournisseur (clé « enabled » inoffensive).
        self::assertSame('https://accounts.google.com', $providers['google']['issuer']);
    }

    public function testProvidersFormKeepsKeys(): void
    {
        $providers = OidcClientFactory::providersFromConfig([
            'providers' => [
                'google'    => ['issuer' => 'https://accounts.google.com', 'client_id' => 'a'],
                'microsoft' => ['issuer' => 'https://login.microsoftonline.com/common/v2.0', 'client_id' => 'b'],
            ],
        ]);

        self::assertSame(['google', 'microsoft'], array_keys($providers));
    }

    public function testProviderKeyIsLowercased(): void
    {
        $providers = OidcClientFactory::providersFromConfig([
            'providers' => [
                'Microsoft' => ['issuer' => 'https://login.microsoftonline.com/common/v2.0', 'client_id' => 'b'],
            ],
        ]);

        // Une clé en casse mixte est normalisée (icône + users.provider cohérents),
        // pas rejetée silencieusement.
        self::assertSame(['microsoft'], array_keys($providers));
    }

    public function testIgnoresIncompleteOrInvalidEntries(): void
    {
        $providers = OidcClientFactory::providersFromConfig([
            'providers' => [
                'ok'          => ['issuer' => 'https://auth.example.com/realms/x', 'client_id' => 'c'],
                'no_issuer'   => ['client_id' => 'c'],
                'empty_issuer' => ['issuer' => '', 'client_id' => 'c'],
                'no_client'   => ['issuer' => 'https://auth.example.com/realms/y'],
                'Bad Key!'    => ['issuer' => 'https://auth.example.com/realms/z', 'client_id' => 'c'],
            ],
        ]);

        self::assertSame(['ok'], array_keys($providers));
    }

    public function testEmptyConfigYieldsNoProvider(): void
    {
        self::assertSame([], OidcClientFactory::providersFromConfig([]));
        self::assertSame([], OidcClientFactory::providersFromConfig(['providers' => []]));
    }

    public function testProviderLabelDerivesShortName(): void
    {
        self::assertSame('google', OidcClientFactory::providerLabel('https://accounts.google.com'));
        self::assertSame('example', OidcClientFactory::providerLabel('https://auth.example.com/realms/x'));
        self::assertSame('', OidcClientFactory::providerLabel('not-a-url'));
    }

    public function testLabelsFromConfigUsesLabelOrCapitalisedKey(): void
    {
        $labels = OidcClientFactory::labelsFromConfig([
            'providers' => [
                'google'    => ['issuer' => 'https://accounts.google.com', 'client_id' => 'c', 'label' => 'Google Workspace'],
                'microsoft' => ['issuer' => 'https://login.microsoftonline.com/common/v2.0', 'client_id' => 'c'],
                'no_client' => ['issuer' => 'https://auth.example.com/realms/y'], // écarté (incomplet)
            ],
        ]);

        // Libellé explicite conservé ; clé capitalisée en secours ; entrée invalide absente.
        self::assertSame(['google' => 'Google Workspace', 'microsoft' => 'Microsoft'], $labels);
    }

    public function testLabelsFromConfigEmptyWhenNoProvider(): void
    {
        self::assertSame([], OidcClientFactory::labelsFromConfig([]));
    }

    public function testConfiguredScopesWin(): void
    {
        self::assertSame(
            ['openid', 'email'],
            OidcClientFactory::scopesFromConfig([
                'issuer' => 'https://discord.com',
                'scopes' => ['openid', 'email'],
            ]),
        );
    }

    public function testScopesFallBackToProfileForStandardProviders(): void
    {
        foreach (['https://accounts.google.com', 'https://auth.example.com/realms/x', '', 'not-a-url'] as $issuer) {
            self::assertSame(
                ['openid', 'profile'],
                OidcClientFactory::scopesFromConfig(['issuer' => $issuer]),
                $issuer,
            );
        }
    }

    /**
     * La lib ajoute « openid » d'office à la liste qu'on lui déclare : le garder
     * de notre côté enverrait un scope dupliqué (« openid identify openid »),
     * refusé par les IdP stricts (#25).
     */
    public function testAdditionalScopesDropOpenid(): void
    {
        self::assertSame(
            ['identify'],
            OidcClientFactory::additionalScopes(['issuer' => 'https://discord.com']),
        );
        self::assertSame(
            ['profile'],
            OidcClientFactory::additionalScopes(['issuer' => 'https://accounts.google.com']),
        );
        // Doublon explicite en config : « openid » disparaît quelle que soit sa place.
        self::assertSame(
            ['email', 'profile'],
            OidcClientFactory::additionalScopes([
                'issuer' => 'https://auth.example.com/realms/x',
                'scopes' => ['email', 'openid', 'profile'],
            ]),
        );
        // Config réduite à « openid » : plus rien à déclarer, la lib s'en charge.
        self::assertSame(
            [],
            OidcClientFactory::additionalScopes([
                'issuer' => 'https://auth.example.com/realms/x',
                'scopes' => ['openid'],
            ]),
        );
    }

    public function testIsDiscordMatchesTheHostOnly(): void
    {
        self::assertTrue(OidcClientFactory::isDiscord('https://discord.com'));
        self::assertTrue(OidcClientFactory::isDiscord('https://canary.discord.com'));
        self::assertFalse(OidcClientFactory::isDiscord('https://notdiscord.com'));
        self::assertFalse(OidcClientFactory::isDiscord('https://accounts.google.com'));
        self::assertFalse(OidcClientFactory::isDiscord(''));
    }

    /**
     * Discord ne connaît pas le scope OIDC « profile » (invalid_scope) : à défaut
     * de scopes configurés, le repli doit demander « identify » à la place, sans
     * quoi une config Discord minimale échouerait dès l'écran de consentement.
     */
    public function testScopesFallBackToIdentifyForDiscord(): void
    {
        self::assertSame(
            ['openid', 'identify'],
            OidcClientFactory::scopesFromConfig(['issuer' => 'https://discord.com']),
        );

        // Repli aussi quand « scopes » est présent mais inexploitable.
        self::assertSame(
            ['openid', 'identify'],
            OidcClientFactory::scopesFromConfig(['issuer' => 'https://discord.com/', 'scopes' => ['', 42]]),
        );

        // Sous-domaine Discord (canary) ; un homographe ne doit PAS matcher.
        self::assertSame(
            ['openid', 'identify'],
            OidcClientFactory::scopesFromConfig(['issuer' => 'https://canary.discord.com']),
        );
        self::assertSame(
            ['openid', 'profile'],
            OidcClientFactory::scopesFromConfig(['issuer' => 'https://notdiscord.com']),
        );
    }
}
