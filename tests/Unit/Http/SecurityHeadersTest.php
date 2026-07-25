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
        // Sans publicité, aucune origine tierce ni cadre autorisé (#185).
        self::assertStringNotContainsString('googlesyndication', $csp);
        self::assertStringNotContainsString('frame-src', $csp);
    }

    /**
     * Publicité activée (#185) : la politique s'ouvre aux origines Google —
     * l'élargissement est le prix du chargement d'AdSense et du CMP.
     */
    public function testPolicyAllowsAdsenseOriginsWhenAdvertisingIsEnabled(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy(true);

        self::assertStringContainsString('script-src \'self\' https://cdn.jsdelivr.net https://pagead2.googlesyndication.com', $csp);
        self::assertStringContainsString('frame-src https://googleads.g.doubleclick.net', $csp);
        self::assertStringContainsString('https://fundingchoicesmessages.google.com', $csp);
        self::assertStringContainsString("img-src 'self' data: https:;", $csp);
        self::assertStringContainsString("connect-src 'self' https://pagead2.googlesyndication.com", $csp);
        // Concession assumée et circonscrite : les styles inline d'Auto ads et
        // du CMP. `script-src` reste sans inline, la CSP reste enforced.
        self::assertStringContainsString("style-src 'self' https://fonts.googleapis.com 'unsafe-inline';", $csp);
        self::assertStringNotContainsString("script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'", $csp);
        self::assertStringNotContainsString('Report-Only', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    /**
     * Le défaut du paramètre porte la garantie de non-régression : un
     * déploiement sans publicité conserve la politique stricte historique.
     */
    public function testDisabledAdvertisingKeepsTheStrictPolicy(): void
    {
        self::assertSame(
            SecurityHeaders::contentSecurityPolicy(),
            SecurityHeaders::contentSecurityPolicy(false),
        );
    }
}
