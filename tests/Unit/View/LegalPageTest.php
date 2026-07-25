<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Support\LegalIdentity;
use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Rendu des quatre pages légales (#185) dans les quatre langues. Ces pages
 * portent une centaine de clés de traduction : le test garantit qu'aucune ne
 * fuit à l'écran sous sa forme brute et que les blocs publicitaires suivent
 * l'état réel de la régie.
 */
final class LegalPageTest extends TestCase
{
    private const PAGES   = ['privacy', 'terms', 'cookies', 'legal-notice'];
    private const LOCALES = ['fr', 'en', 'nl', 'de'];

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(string $page, string $locale = 'fr', array $overrides = []): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', $locale);

        return $view->render('legal', array_merge([
            'page'          => $page,
            'available'     => self::LOCALES,
            'legal'         => LegalIdentity::from([]),
            'adsEnabled'    => false,
            'adsenseClient' => null,
        ], $overrides));
    }

    public function testEveryPageRendersInEveryLocaleWithoutRawKeys(): void
    {
        foreach (self::PAGES as $page) {
            foreach (self::LOCALES as $locale) {
                $html = $this->render($page, $locale, ['adsEnabled' => true]);

                self::assertStringContainsString('<h1>', $html, "$page/$locale");
                // Une clé non traduite ressortirait telle quelle (Translator
                // retombe sur la clé brute) — ex. « legal.privacy.intro ».
                self::assertDoesNotMatchRegularExpression(
                    '/legal\.[a-z_]+\.[a-z_]+/',
                    strip_tags($html),
                    "clé de traduction brute rendue sur $page/$locale",
                );
            }
        }
    }

    public function testAdvertisingSectionsFollowTheActualAdState(): void
    {
        $withAds    = $this->render('cookies', 'fr', ['adsEnabled' => true]);
        $withoutAds = $this->render('cookies', 'fr', ['adsEnabled' => false]);

        self::assertStringContainsString('AdSense', $withAds);
        self::assertStringContainsString('policies.google.com', $withAds);
        // Sans régie active, la politique n'annonce pas de traitement fictif.
        self::assertStringNotContainsString('AdSense', $withoutAds);
        self::assertStringNotContainsString('policies.google.com', $withoutAds);
        self::assertStringContainsString('Aucune publicité', $withoutAds);
    }

    public function testAdsenseScriptIsLoadedOnlyWithAClientId(): void
    {
        $script = 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

        self::assertStringNotContainsString($script, $this->render('privacy'));
        self::assertStringContainsString(
            $script . '?client=ca-pub-1234567890123456',
            $this->render('privacy', 'fr', ['adsenseClient' => 'ca-pub-1234567890123456']),
        );
    }

    /**
     * Une identité d'éditeur non configurée doit se voir, pas passer inaperçue.
     */
    public function testMissingPublisherIdentityIsSignalled(): void
    {
        $html = $this->render('legal-notice');

        self::assertSame(
            \count(LegalIdentity::FIELDS),
            substr_count($html, 'information non configurée'),
        );
    }

    public function testConfiguredIdentityIsRenderedWithAMailtoLink(): void
    {
        $identity = LegalIdentity::from(['legal' => [
            'publisher'     => 'Exemple SRL',
            'contact_email' => 'rgpd@exemple.be',
        ]]);

        $html = $this->render('legal-notice', 'fr', ['legal' => $identity]);

        self::assertStringContainsString('Exemple SRL', $html);
        self::assertStringContainsString('<a href="mailto:rgpd@exemple.be">', $html);
    }

    /**
     * Le responsable du traitement est interpolé dans le corps du texte : c'est
     * la mention qui rend la politique opposable.
     */
    public function testControllerIdentityIsInterpolatedInPrivacyPolicy(): void
    {
        $html = $this->render('privacy', 'fr', [
            'legal' => LegalIdentity::from(['legal' => [
                'publisher'     => 'Exemple SRL',
                'contact_email' => 'rgpd@exemple.be',
            ]]),
        ]);

        self::assertStringContainsString('Exemple SRL', $html);
        self::assertStringContainsString('rgpd@exemple.be', $html);
        self::assertStringNotContainsString('{publisher}', $html);
        self::assertStringNotContainsString('{email}', $html);
    }

    public function testEachPageLinksToTheThreeOthers(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->render($page);

            foreach (self::PAGES as $other) {
                if ($other === $page) {
                    continue;
                }
                self::assertStringContainsString($other . '"', $html, "$page ne renvoie pas vers $other");
            }
        }
    }
}
