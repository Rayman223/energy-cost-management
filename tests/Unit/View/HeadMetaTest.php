<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Seo\PageMeta;
use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Balises de référencement émises par `partials/_head.php` (#84).
 *
 * Le point sensible est le **défaut fermé** : une page qui ne fournit pas de
 * `PageMeta` doit sortir en `noindex`. Sans ce test, ajouter une page privée
 * sans y penser suffirait à la rendre indexable.
 */
final class HeadMetaTest extends TestCase
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
            'seo'  => [
                'base_url'                 => 'https://energy-cost.eu',
                'google_site_verification' => 'abcdef0123456789_token',
            ],
            'i18n' => ['default_locale' => 'fr', 'available' => ['fr', 'en', 'nl', 'de']],
        ];
    }

    /** @param array<string, mixed> $params */
    private function head(array $params): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        return $view->partial('_head', ['title' => 'Titre de test'] + $params);
    }

    public function testWithoutMetaThePageIsNoindex(): void
    {
        $head = $this->head([]);

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $head);
        self::assertStringNotContainsString('rel="canonical"', $head);
        self::assertStringNotContainsString('og:url', $head);
    }

    public function testIndexablePageEmitsCanonicalDescriptionAndRobots(): void
    {
        $head = $this->head([
            'meta' => PageMeta::indexable($this->config(), 'stats', 'fr', 'Résumé de la page.'),
        ]);

        self::assertStringContainsString('<meta name="robots" content="index, follow">', $head);
        self::assertStringContainsString('<meta name="description" content="Résumé de la page.">', $head);
        self::assertStringContainsString(
            '<link rel="canonical" href="https://energy-cost.eu/stats?lang=fr">',
            $head,
        );
    }

    public function testIndexablePageLinksEveryLocaleAndADefault(): void
    {
        $head = $this->head([
            'meta' => PageMeta::indexable($this->config(), 'stats', 'en', 'Summary.'),
        ]);

        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            self::assertStringContainsString(
                sprintf('hreflang="%s" href="https://energy-cost.eu/stats?lang=%s"', $locale, $locale),
                $head,
            );
        }

        // x-default : l'URL sans `lang`, celle qui négocie la langue du visiteur.
        self::assertStringContainsString('hreflang="x-default" href="https://energy-cost.eu/stats"', $head);
        self::assertStringContainsString('<meta property="og:locale" content="en_GB">', $head);
    }

    public function testQueryParametersAreCarriedIntoCanonicalAndAlternates(): void
    {
        $head = $this->head([
            'meta' => PageMeta::indexable($this->config(), 'stats', 'fr', 'Résumé.', ['country' => 'BE']),
        ]);

        self::assertStringContainsString(
            '<link rel="canonical" href="https://energy-cost.eu/stats?country=BE&amp;lang=fr">',
            $head,
        );
        self::assertStringContainsString('hreflang="x-default" href="https://energy-cost.eu/stats?country=BE"', $head);
    }

    public function testHiddenPageKeepsTheVerificationTagButNoCanonical(): void
    {
        $head = $this->head(['meta' => PageMeta::hidden($this->config())]);

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $head);
        self::assertStringNotContainsString('rel="canonical"', $head);
        self::assertStringContainsString(
            '<meta name="google-site-verification" content="abcdef0123456789_token">',
            $head,
        );
    }

    public function testMalformedVerificationTokenIsDropped(): void
    {
        $config                                = $this->config();
        $config['seo']['google_site_verification'] = '"><script>alert(1)</script>';

        self::assertStringNotContainsString(
            'google-site-verification',
            $this->head(['meta' => PageMeta::indexable($config, '', 'fr', 'Résumé.')]),
        );
    }

    public function testDescriptionIsEscaped(): void
    {
        $head = $this->head([
            'meta' => PageMeta::indexable($this->config(), '', 'fr', 'Guillemet " et <balise>'),
        ]);

        self::assertStringContainsString('content="Guillemet &quot; et &lt;balise&gt;"', $head);
        self::assertStringNotContainsString('<balise>', $head);
    }
}
