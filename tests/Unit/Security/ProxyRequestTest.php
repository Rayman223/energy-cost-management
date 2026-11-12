<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\ProxyRequest;
use App\Security\Session;
use PHPUnit\Framework\TestCase;

final class ProxyRequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER = ['REMOTE_ADDR' => '172.20.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        ProxyRequest::configure([]);
    }

    public function testUntrustedForwardedHeadersCannotChangeSchemeOrIp(): void
    {
        ProxyRequest::configure([]);
        self::assertFalse(Session::isHttps());
        self::assertSame('172.20.0.2', ProxyRequest::clientIp());
    }

    public function testExactSwagIpAllowsRebuiltHeaders(): void
    {
        ProxyRequest::configure(['trusted_proxies' => ['172.20.0.2']]);
        self::assertTrue(Session::isHttps());
        self::assertSame('203.0.113.10', ProxyRequest::clientIp());
    }

    public function testChainAndMalformedAddressesFallBackToPeer(): void
    {
        ProxyRequest::configure(['trusted_proxies' => ['172.20.0.2']]);
        foreach (['203.0.113.10, 192.0.2.1', 'invalid', ''] as $value) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $value;
            self::assertSame('172.20.0.2', ProxyRequest::clientIp());
        }
    }

    public function testIpv6AndDirectHttps(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:0:0::2';
        ProxyRequest::configure(['trusted_proxies' => ['2001:db8::2']]);
        self::assertTrue(Session::isHttps());
        ProxyRequest::configure([]);
        $_SERVER['HTTPS'] = 'on';
        self::assertTrue(Session::isHttps());
        $_SERVER['REMOTE_ADDR'] = 'invalid';
        self::assertNull(ProxyRequest::clientIp());
    }
}
