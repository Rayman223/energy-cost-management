<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testMatchesAcceptsIdenticalTokens(): void
    {
        self::assertTrue(Csrf::matches('abc123def', 'abc123def'));
    }

    public function testMatchesRejectsDifferentTokens(): void
    {
        self::assertFalse(Csrf::matches('abc123def', 'zzz999yyy'));
    }

    public function testMatchesRejectsEmptyOrNull(): void
    {
        self::assertFalse(Csrf::matches('', 'abc'));
        self::assertFalse(Csrf::matches('abc', ''));
        self::assertFalse(Csrf::matches('abc', null));
    }
}
