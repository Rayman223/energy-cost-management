<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use App\Seo\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * `/robots.txt` (#84). Le cas qui compte est le déploiement auto-hébergé :
 * une instance privée ne doit ni s'offrir au crawl, ni publier l'adresse de
 * son sitemap.
 */
final class RobotsTxtTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server           = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** @return array<string, mixed> */
    private function publicConfig(): array
    {
        return [
            'oidc' => ['enabled' => true],
            'seo'  => ['base_url' => 'https://energy-cost.eu'],
        ];
    }

    public function testPrivateInstanceDisallowsEverything(): void
    {
        $robots = RobotsTxt::render(['oidc' => ['enabled' => false]]);

        self::assertStringContainsString("User-agent: *\nDisallow: /\n", $robots);
        self::assertStringNotContainsString('Sitemap:', $robots);
    }

    public function testMissingOidcSectionIsTreatedAsPrivate(): void
    {
        self::assertStringContainsString('Disallow: /', RobotsTxt::render([]));
    }

    public function testPublicInstanceAnnouncesTheSitemapAsAnAbsoluteUrl(): void
    {
        self::assertStringContainsString(
            'Sitemap: https://energy-cost.eu/sitemap.xml',
            RobotsTxt::render($this->publicConfig()),
        );
    }

    public function testPublicInstanceKeepsPrivatePathsOutOfTheCrawl(): void
    {
        $robots = RobotsTxt::render($this->publicConfig());

        foreach (['/account', '/admin', '/api', '/tariffs', '/auth/'] as $path) {
            self::assertStringContainsString('Disallow: ' . $path . "\n", $robots);
        }
    }

    public function testPublicPagesAreNotDisallowed(): void
    {
        $robots = RobotsTxt::render($this->publicConfig());

        foreach (['/stats', '/privacy', '/terms', '/cookies', '/legal-notice'] as $path) {
            self::assertStringNotContainsString('Disallow: ' . $path . "\n", $robots);
        }
    }

    public function testDisallowedPathsFollowASubdirectoryDeployment(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/energie/index.php';

        self::assertStringContainsString('Disallow: /energie/account', RobotsTxt::render($this->publicConfig()));
    }
}
