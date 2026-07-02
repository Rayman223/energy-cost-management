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
}
