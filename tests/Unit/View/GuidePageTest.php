<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\GuideContent;
use App\View\GuidePage;
use PHPUnit\Framework\TestCase;

/**
 * Rendu des pages de guides (#85). Ces pages existent pour être indexées : le
 * test vérifie donc autant leur contenu que leurs métadonnées.
 */
final class GuidePageTest extends TestCase
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
        unset($_GET['lang']);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'seo'  => ['base_url' => 'https://energy-cost.eu'],
            'i18n' => ['default_locale' => 'fr', 'available' => ['fr', 'en', 'nl', 'de']],
        ];
    }

    public function testEveryGuideRendersInEveryLocale(): void
    {
        foreach (GuideContent::SLUGS as $slug) {
            foreach (['fr', 'en', 'nl', 'de'] as $locale) {
                $_GET['lang'] = $locale;
                $html         = GuidePage::render($this->config(), $slug);

                self::assertNotNull($html, "{$slug} ne se rend pas en {$locale}.");
                self::assertStringContainsString('<html lang="' . $locale . '"', $html);

                $guide = GuideContent::load($slug, $locale);
                self::assertNotNull($guide);
                self::assertStringContainsString(htmlspecialchars($guide['title'], ENT_QUOTES), $html);
            }
        }
    }

    public function testAGuideIsIndexableAndSelfCanonical(): void
    {
        $_GET['lang'] = 'fr';
        $html         = (string) GuidePage::render($this->config(), 'kwh-price');

        self::assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        self::assertStringContainsString(
            '<link rel="canonical" href="https://energy-cost.eu/guides/kwh-price?lang=fr">',
            $html,
        );
        // Chaque guide porte sa propre description, pas celle du site.
        $guide = GuideContent::load('kwh-price', 'fr');
        self::assertNotNull($guide);
        self::assertStringContainsString(htmlspecialchars($guide['description'], ENT_QUOTES), $html);
    }

    public function testAGuideLinksToStatsAndToTheOtherGuides(): void
    {
        // Un guide qui ne mène nulle part ne sert ni au lecteur ni au crawl.
        $html = (string) GuidePage::render($this->config(), 'kwh-price');

        self::assertStringContainsString('href="/stats"', $html);
        foreach (GuideContent::SLUGS as $slug) {
            if ($slug === 'kwh-price') {
                continue;
            }
            self::assertStringContainsString('href="/guides/' . $slug . '"', $html);
        }
    }

    public function testUnknownSlugRendersNothing(): void
    {
        self::assertNull(GuidePage::render($this->config(), 'inconnu'));
    }

    public function testTheIndexListsEveryGuide(): void
    {
        $html = GuidePage::index($this->config());

        foreach (GuideContent::SLUGS as $slug) {
            self::assertStringContainsString('href="/guides/' . $slug . '"', $html);
        }
        self::assertStringContainsString(
            '<link rel="canonical" href="https://energy-cost.eu/guides?lang=fr">',
            $html,
        );
    }

    public function testContentIsEscapedNotInjected(): void
    {
        $html = (string) GuidePage::render($this->config(), 'kwh-price');

        // Le contenu est du texte : aucune balise ne doit en sortir. Les
        // apostrophes françaises sont un bon marqueur d'échappement effectif.
        self::assertStringContainsString('&#039;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }
}
