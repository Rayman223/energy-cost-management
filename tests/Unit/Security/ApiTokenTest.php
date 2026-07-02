<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\ApiToken;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
{
    public function testGenerateProducesWellFormedToken(): void
    {
        $t = ApiToken::generate();

        self::assertTrue(ApiToken::looksValid($t['token']));
        self::assertSame(ApiToken::hash($t['token']), $t['hash']);
        self::assertSame(64, strlen($t['hash']));
        self::assertSame(substr($t['token'], 0, 12), $t['prefix']);
        self::assertStringStartsWith('mec_', $t['prefix']);
    }

    public function testGenerateIsUnique(): void
    {
        self::assertNotSame(ApiToken::generate()['token'], ApiToken::generate()['token']);
    }

    public function testLooksValidRejectsMalformedTokens(): void
    {
        self::assertFalse(ApiToken::looksValid(''));
        self::assertFalse(ApiToken::looksValid('mec_'));
        self::assertFalse(ApiToken::looksValid('mec_' . str_repeat('g', 40))); // non-hex
        self::assertFalse(ApiToken::looksValid('abc_' . str_repeat('a', 40)));
        self::assertFalse(ApiToken::looksValid('mec_' . str_repeat('a', 39))); // trop court
    }

    public function testFromAuthorizationHeader(): void
    {
        self::assertSame('mec_abc', ApiToken::fromAuthorizationHeader('Bearer mec_abc'));
        self::assertSame('mec_abc', ApiToken::fromAuthorizationHeader('bearer mec_abc'));
        self::assertNull(ApiToken::fromAuthorizationHeader('Basic dXNlcjpwYXNz'));
        self::assertNull(ApiToken::fromAuthorizationHeader('Bearer '));
        self::assertNull(ApiToken::fromAuthorizationHeader(null));
    }
}
