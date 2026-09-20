<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SiteUrl;
use PHPUnit\Framework\TestCase;

/**
 * URLs absolues du site (#84). Les trois sources d'hôte sont testées dans leur
 * ordre de préférence, ainsi que l'anti-spoof hérité de `/api-guide`.
 */
final class SiteUrlTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        // Sous PHPUnit, SCRIPT_NAME vaut « vendor/bin/phpunit » : sans ce
        // réglage, Url::to() préfixerait chaque chemin de « vendor/bin ».
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testConfiguredBaseUrlWins(): void
    {
        $_SERVER['HTTP_HOST'] = 'www.attaquant.test';

        self::assertSame(
            'https://energy-cost.eu',
            SiteUrl::origin(['seo' => ['base_url' => 'https://energy-cost.eu']]),
        );
    }

    public function testConfiguredBaseUrlIsReducedToItsOrigin(): void
    {
        // Une barre finale ou un chemin résiduel dans la config ne doit pas
        // produire une URL à double barre une fois le chemin concaténé.
        self::assertSame(
            'https://energy-cost.eu',
            SiteUrl::origin(['seo' => ['base_url' => 'https://energy-cost.eu/']]),
        );
    }

    public function testConfiguredBaseUrlKeepsAnExplicitPort(): void
    {
        self::assertSame(
            'https://energy-cost.eu:8443',
            SiteUrl::origin(['seo' => ['base_url' => 'https://energy-cost.eu:8443']]),
        );
    }

    public function testMalformedBaseUrlFallsBackToTheRequestHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'energie.example.eu';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        self::assertSame(
            'http://energie.example.eu',
            SiteUrl::origin(['seo' => ['base_url' => 'javascript:alert(1)']]),
        );
    }

    public function testTrustedHostsActAsCanonicalHostWhenBaseUrlIsAbsent(): void
    {
        $_SERVER['HTTP_HOST'] = 'www.attaquant.test';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        self::assertSame(
            'http://energie.example.eu',
            SiteUrl::origin(['web_security' => ['trusted_hosts' => ['energie.example.eu']]]),
        );
    }

    public function testListedHostIsKept(): void
    {
        $_SERVER['HTTP_HOST'] = 'energie.example.eu:8443';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        self::assertSame(
            'http://energie.example.eu:8443',
            SiteUrl::origin(['web_security' => ['trusted_hosts' => ['energie.example.eu', 'energie.example.eu:8443']]]),
        );
    }

    public function testMalformedRequestHostFallsBackToLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = 'exemple.test/../evil';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        self::assertSame('http://localhost', SiteUrl::origin([]));
    }

    public function testForwardedProtoSelectsHttps(): void
    {
        $_SERVER['HTTP_HOST']              = 'energie.example.eu';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        self::assertSame('https://energie.example.eu', SiteUrl::origin([]));
    }

    public function testAbsoluteBuildsPageUrls(): void
    {
        $config = ['seo' => ['base_url' => 'https://energy-cost.eu']];

        self::assertSame('https://energy-cost.eu/', SiteUrl::absolute($config, ''));
        self::assertSame('https://energy-cost.eu/stats', SiteUrl::absolute($config, 'stats'));
        self::assertSame('https://energy-cost.eu/auth/login', SiteUrl::absolute($config, '/auth/login'));
    }

    public function testAbsoluteHonoursASubdirectoryDeployment(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/energie/index.php';

        self::assertSame(
            'https://exemple.tld/energie/stats',
            SiteUrl::absolute(['seo' => ['base_url' => 'https://exemple.tld']], 'stats'),
        );
    }

    public function testAbsoluteAppendsQueryString(): void
    {
        self::assertSame(
            'https://energy-cost.eu/stats?country=BE&lang=fr',
            SiteUrl::absolute(
                ['seo' => ['base_url' => 'https://energy-cost.eu']],
                'stats',
                http_build_query(['country' => 'BE', 'lang' => 'fr']),
            ),
        );
    }
}
