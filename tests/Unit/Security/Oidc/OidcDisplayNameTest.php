<?php

declare(strict_types=1);

namespace Tests\Unit\Security\Oidc;

use App\Security\Oidc\OidcDisplayName;
use PHPUnit\Framework\TestCase;

/**
 * Résolution du nom d'affichage : le claim « name » n'est pas garanti (Discord
 * ne le fournit jamais), d'où le repli sur les claims OIDC standards
 * « preferred_username » puis « nickname », d'abord dans l'id_token puis dans le
 * userinfo.
 */
final class OidcDisplayNameTest extends TestCase
{
    public function testNameClaimWins(): void
    {
        $claims = (object) ['name' => 'Ada Lovelace', 'preferred_username' => 'ada', 'nickname' => 'countess'];

        self::assertSame('Ada Lovelace', OidcDisplayName::fromClaims($claims));
    }

    public function testPreferredUsernameBeforeNickname(): void
    {
        $claims = (object) ['preferred_username' => 'ada', 'nickname' => 'countess'];

        self::assertSame('ada', OidcDisplayName::fromClaims($claims));
    }

    public function testNicknameAsLastResort(): void
    {
        self::assertSame('countess', OidcDisplayName::fromClaims((object) ['nickname' => 'countess']));
    }

    /**
     * Cas Discord : l'id_token ne porte que sub/aud/iss ; le nom n'arrive que du
     * userinfo (scope identify).
     */
    public function testFallsBackToUserInfoWhenIdTokenCarriesNothing(): void
    {
        $verified = (object) ['sub' => '123456789012345678', 'aud' => 'client'];
        $userInfo = (object) ['sub' => '123456789012345678', 'preferred_username' => 'ada'];

        self::assertSame('ada', OidcDisplayName::fromClaims($verified, $userInfo));
    }

    public function testIdTokenWinsOverUserInfo(): void
    {
        $verified = (object) ['name' => 'Ada Lovelace'];
        $userInfo = (object) ['name' => 'Autre'];

        self::assertSame('Ada Lovelace', OidcDisplayName::fromClaims($verified, $userInfo));
    }

    public function testBlankAndNonStringClaimsAreSkipped(): void
    {
        $claims = (object) ['name' => '   ', 'preferred_username' => 42, 'nickname' => ' countess '];

        // Valeur retenue rognée : un nom encadré d'espaces n'est pas un nom.
        self::assertSame('countess', OidcDisplayName::fromClaims($claims));
    }

    public function testNoSourceYieldsEmptyString(): void
    {
        self::assertSame('', OidcDisplayName::fromClaims(null, null));
        self::assertSame('', OidcDisplayName::fromClaims((object) ['sub' => 'x']));
    }
}
