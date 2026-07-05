<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testNonceIsStableWithinRequest(): void
    {
        $first  = SecurityHeaders::nonce();
        $second = SecurityHeaders::nonce();

        self::assertNotSame('', $first);
        self::assertSame($first, $second, 'Le nonce doit être mémoïsé pour la requête.');
    }

    public function testPolicyIsEnforcedAndCarriesNonce(): void
    {
        $nonce = SecurityHeaders::nonce();
        $csp   = SecurityHeaders::contentSecurityPolicy($nonce);

        // CSP enforced (jamais Report-Only) et scripts inline couverts par le nonce.
        self::assertStringNotContainsString('Report-Only', $csp);
        self::assertStringContainsString("script-src 'self' https://cdn.jsdelivr.net 'nonce-" . $nonce . "'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("default-src 'self'", $csp);
    }
}
