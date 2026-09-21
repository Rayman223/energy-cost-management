<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use App\Seo\Sitemap;
use App\View\GuideContent;
use PHPUnit\Framework\TestCase;

/**
 * `/sitemap.xml` (#84). Un sitemap invalide est rejeté en bloc par Search
 * Console : le XML est donc réellement reparsé ici, pas seulement grepé.
 */
final class SitemapTest extends TestCase
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
    private function config(): array
    {
        return [
            'seo'  => ['base_url' => 'https://energy-cost.eu'],
            'i18n' => ['default_locale' => 'fr', 'available' => ['fr', 'en', 'nl', 'de']],
        ];
    }

    /**
     * @param list<string> $countries
     * @return list<string>
     */
    private function locations(array $countries = []): array
    {
        $xml = simplexml_load_string(Sitemap::render($this->config(), $countries));
        self::assertNotFalse($xml, 'Le sitemap doit être un document XML valide.');

        $urls = [];
        foreach ($xml->url as $url) {
            $urls[] = (string) $url->loc;
        }

        return $urls;
    }

    public function testEveryPermanentPageIsListedInEveryLocale(): void
    {
        $urls = $this->locations();

        foreach (['', 'stats', 'guides', 'privacy', 'terms', 'cookies', 'legal-notice'] as $path) {
            foreach (['fr', 'en', 'nl', 'de'] as $locale) {
                self::assertContains(
                    'https://energy-cost.eu/' . $path . '?lang=' . $locale,
                    $urls,
                );
            }
        }
    }

    public function testEveryUrlIsAbsoluteAndCarriesALocale(): void
    {
        foreach ($this->locations() as $url) {
            self::assertStringStartsWith('https://energy-cost.eu/', $url);
            self::assertMatchesRegularExpression('/[?&]lang=(fr|en|nl|de)$/', $url);
        }
    }

    public function testEveryGuideIsListed(): void
    {
        $urls = $this->locations();

        foreach (GuideContent::SLUGS as $slug) {
            self::assertContains('https://energy-cost.eu/guides/' . $slug . '?lang=fr', $urls);
        }
    }

    public function testPublishedCountriesGetTheirOwnUrls(): void
    {
        $urls = $this->locations(['BE', 'FR']);

        self::assertContains('https://energy-cost.eu/stats?country=BE&lang=fr', $urls);
        self::assertContains('https://energy-cost.eu/stats?country=FR&lang=de', $urls);
    }

    public function testWithoutCountriesOnlyPermanentPagesAreListed(): void
    {
        // Corpus vide ou base injoignable : la carte se réduit aux pages
        // permanentes — 7 pages plus les 3 guides, dans 4 locales.
        self::assertCount(10 * 4, $this->locations());
    }

    public function testLocaleListComesFromTheConfiguration(): void
    {
        $config = $this->config();
        $config['i18n']['available'] = ['fr', 'en'];

        $xml = simplexml_load_string(Sitemap::render($config));
        self::assertNotFalse($xml);
        self::assertCount(10 * 2, $xml->url);
    }
}
