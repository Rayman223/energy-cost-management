<?php

declare(strict_types=1);

namespace Tests\Unit\Security\OAuth;

use App\Security\OAuth\GithubOAuthClient;
use PHPUnit\Framework\TestCase;

/**
 * Parties pures du connecteur GitHub (#24) : reconnaissance du fournisseur,
 * demande d'autorisation, lecture des deux réponses de GitHub. Les aller-retours
 * HTTP eux-mêmes ne sont pas couverts ici — même découpage que
 * EntsoePriceParser / EntsoePriceClient.
 */
final class GithubOAuthClientTest extends TestCase
{
    public function testSupportsOnlyGithubIssuers(): void
    {
        self::assertTrue(GithubOAuthClient::supports(['issuer' => 'https://github.com']));
        self::assertTrue(GithubOAuthClient::supports(['issuer' => 'https://www.github.com']));

        // Un hôte qui se termine par « github.com » sans en être un sous-domaine
        // ne doit pas emprunter le flux GitHub.
        self::assertFalse(GithubOAuthClient::supports(['issuer' => 'https://notgithub.com']));
        self::assertFalse(GithubOAuthClient::supports(['issuer' => 'https://discord.com']));
        self::assertFalse(GithubOAuthClient::supports(['issuer' => '']));
        self::assertFalse(GithubOAuthClient::supports([]));
    }

    public function testAuthorizationUrlOmitsScopeByDefault(): void
    {
        $url = GithubOAuthClient::authorizationUrl(
            ['issuer' => 'https://github.com', 'client_id' => 'cli ent'],
            'st4te',
            'https://example.org/app/auth/login',
        );

        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=cli%20ent', $url);
        self::assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.org%2Fapp%2Fauth%2Flogin', $url);
        self::assertStringContainsString('state=st4te', $url);
        self::assertStringNotContainsString('scope=', $url);
    }

    public function testAuthorizationUrlJoinsConfiguredScopes(): void
    {
        $url = GithubOAuthClient::authorizationUrl(
            ['issuer' => 'https://github.com', 'client_id' => 'abc', 'scopes' => ['read:user', 'openid', '']],
            's',
            'https://example.org/auth/login',
        );

        // « openid » n'existe pas côté GitHub : il est filtré plutôt que de faire
        // échouer la demande.
        self::assertStringContainsString('scope=read%3Auser', $url);
        self::assertStringNotContainsString('openid', $url);
    }

    public function testAccessTokenFromResponse(): void
    {
        self::assertSame(
            'gho_token',
            GithubOAuthClient::accessTokenFromResponse('{"access_token":"gho_token","token_type":"bearer"}'),
        );
    }

    public function testAccessTokenRejectsGithubError(): void
    {
        // GitHub répond 200 avec un corps d'erreur : c'est le corps qui fait foi.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/bad_verification_code/');

        GithubOAuthClient::accessTokenFromResponse('{"error":"bad_verification_code"}');
    }

    public function testAccessTokenRejectsMissingTokenOrGarbage(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubOAuthClient::accessTokenFromResponse('{"token_type":"bearer"}');
    }

    public function testAccessTokenRejectsUnparseableBody(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubOAuthClient::accessTokenFromResponse('<html>502</html>');
    }

    public function testIdentityUsesNumericIdAsSubject(): void
    {
        $identity = GithubOAuthClient::identityFromUserPayload('{"id":583231,"login":"octocat","name":"The Octocat"}');

        // Le sub est l'id immuable, jamais le login (renommable).
        self::assertSame(['sub' => '583231', 'name' => 'The Octocat'], $identity);
    }

    public function testIdentityFallsBackToLoginWhenNameIsEmpty(): void
    {
        self::assertSame(
            ['sub' => '42', 'name' => 'octocat'],
            GithubOAuthClient::identityFromUserPayload('{"id":42,"login":"octocat","name":null}'),
        );
        self::assertSame(
            ['sub' => '42', 'name' => 'octocat'],
            GithubOAuthClient::identityFromUserPayload('{"id":42,"login":"octocat","name":"   "}'),
        );
    }

    public function testIdentityAcceptsStringId(): void
    {
        self::assertSame(
            '583231',
            GithubOAuthClient::identityFromUserPayload('{"id":"583231","login":"octocat"}')['sub'],
        );
    }

    public function testIdentityRejectsMissingId(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubOAuthClient::identityFromUserPayload('{"login":"octocat"}');
    }

    public function testIdentityRejectsUnparseableBody(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubOAuthClient::identityFromUserPayload('Not Found');
    }
}
