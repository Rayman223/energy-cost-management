<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testPolicyIsEnforcedWithoutInlineAllowances(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();

        // CSP enforced (jamais Report-Only) et stricte : aucune source inline.
        self::assertStringNotContainsString('Report-Only', $csp);
        self::assertStringContainsString("script-src 'self' https://cdn.jsdelivr.net;", $csp);
        self::assertStringContainsString("style-src 'self' https://fonts.googleapis.com;", $csp);
        self::assertStringNotContainsString("'nonce-", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("default-src 'self'", $csp);
    }
}
