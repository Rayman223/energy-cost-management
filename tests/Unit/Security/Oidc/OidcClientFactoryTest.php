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
}
