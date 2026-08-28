<?php

declare(strict_types=1);

namespace Tests\Unit\Security\Oidc;

use App\Security\Oidc\OidcSessionState;
use PHPUnit\Framework\TestCase;

/**
 * Purge du code_verifier PKCE entre deux flux OIDC. Sans elle, le verifier
 * laissé en session par une connexion Google est envoyé au fournisseur suivant,
 * qui n'a reçu aucun code_challenge, et l'échange du code est refusé (#25).
 */
final class OidcSessionStateTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testForgetsAStaleCodeVerifier(): void
    {
        $_SESSION[OidcSessionState::CODE_VERIFIER_KEY] = 'résidu-de-la-connexion-google';

        OidcSessionState::forgetCodeVerifier();

        self::assertArrayNotHasKey(OidcSessionState::CODE_VERIFIER_KEY, $_SESSION);
    }

    /**
     * La purge est chirurgicale : la session authentifiée et le contexte du flux
     * en cours doivent survivre, sans quoi une liaison d'identité perdrait
     * l'utilisateur qui l'a demandée.
     */
    public function testLeavesTheRestOfTheSessionUntouched(): void
    {
        $_SESSION = [
            OidcSessionState::CODE_VERIFIER_KEY => 'résidu',
            'user_id'                           => 42,
            'auth_oidc_provider'                => 'discord',
            'auth_link_user_id'                 => 42,
            'auth_next'                         => '/account',
        ];

        OidcSessionState::forgetCodeVerifier();

        self::assertSame(
            ['user_id' => 42, 'auth_oidc_provider' => 'discord', 'auth_link_user_id' => 42, 'auth_next' => '/account'],
            $_SESSION,
        );
    }

    public function testIsHarmlessWhenNothingWasStored(): void
    {
        $_SESSION = ['user_id' => 42];

        OidcSessionState::forgetCodeVerifier();

        self::assertSame(['user_id' => 42], $_SESSION);
    }

    /**
     * La clé doit rester celle de la lib : la renommer purgerait un état que
     * jumbojett continuerait de lire ailleurs.
     */
    public function testKeyMatchesTheLibrarySessionKey(): void
    {
        self::assertSame('openid_connect_code_verifier', OidcSessionState::CODE_VERIFIER_KEY);
    }
}
