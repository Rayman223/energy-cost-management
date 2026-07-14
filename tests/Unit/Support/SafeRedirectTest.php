<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
    public function testKeepsInternalPath(): void
    {
        self::assertSame('/account', SafeRedirect::sanitize('/account', '/'));
        self::assertSame('/account?tab=x', SafeRedirect::sanitize('/account?tab=x', '/'));
    }

    #[DataProvider('unsafeTargets')]
    public function testRejectsUnsafeTargets(string $next): void
    {
        self::assertSame('/home', SafeRedirect::sanitize($next, '/home'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeTargets(): iterable
    {
        yield 'empty'            => [''];
        yield 'absolute url'     => ['https://evil.example'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'backslash'        => ['/\\evil.com'];
        yield 'not-a-path'       => ['account'];
        yield 'crlf injection'   => ["/x\r\nSet-Cookie: a=b"];
    }
}
