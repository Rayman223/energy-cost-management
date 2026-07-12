<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\WebAccessGuard;
use PHPUnit\Framework\TestCase;

final class WebAccessGuardPathTest extends TestCase
{
    public function testStripsAuthSegmentAtRoot(): void
    {
        self::assertSame('', WebAccessGuard::stripTrailingSegment('/auth', 'auth'));
    }

    public function testStripsAuthSegmentUnderSubpath(): void
    {
        self::assertSame('/energyv2', WebAccessGuard::stripTrailingSegment('/energyv2/auth', 'auth'));
    }

    public function testLeavesRootUntouched(): void
    {
        self::assertSame('', WebAccessGuard::stripTrailingSegment('', 'auth'));
    }

    public function testLeavesNonAuthPathUntouched(): void
    {
        self::assertSame('/energyv2', WebAccessGuard::stripTrailingSegment('/energyv2', 'auth'));
    }

    public function testDoesNotStripPartialSegmentMatch(): void
    {
        // « /oauth » se termine par « auth » mais n'est PAS le segment « /auth ».
        self::assertSame('/oauth', WebAccessGuard::stripTrailingSegment('/oauth', 'auth'));
    }

    /** @return array<string, mixed> */
    private static function security(string $user, string $pass): array
    {
        return ['basic_auth' => ['enabled' => true, 'username' => $user, 'password' => $pass]];
    }

    public function testCredentialsMatchExact(): void
    {
        self::assertTrue(WebAccessGuard::credentialsMatch(self::security('admin', 'adminadmin'), 'admin', 'adminadmin'));
    }

    public function testCredentialsMatchTrimsProvidedWhitespace(): void
    {
        // Espace / retour-ligne parasite au copier-coller côté saisie.
        self::assertTrue(WebAccessGuard::credentialsMatch(self::security('admin', 'adminadmin'), " admin ", "adminadmin\n"));
    }

    public function testCredentialsMatchTrimsConfiguredWhitespace(): void
    {
        // Espace parasite côté config.php.
        self::assertTrue(WebAccessGuard::credentialsMatch(self::security(' admin', 'adminadmin '), 'admin', 'adminadmin'));
    }

    public function testCredentialsRejectWrongPassword(): void
    {
        self::assertFalse(WebAccessGuard::credentialsMatch(self::security('admin', 'adminadmin'), 'admin', 'nope'));
    }

    public function testCredentialsRejectEmptyConfig(): void
    {
        self::assertFalse(WebAccessGuard::credentialsMatch(self::security('', ''), '', ''));
    }
}
