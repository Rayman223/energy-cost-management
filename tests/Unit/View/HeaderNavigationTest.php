<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Support\Url;
use App\View\ViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderNavigationTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private function header(string $locale = 'fr', array $data = []): string
    {
        return ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', $locale)->partial('_header', $data + [
            'subtitle' => 'Energy',
            'current' => 'tariffs',
            'available' => ['fr', 'en', 'de', 'nl'],
            'isAdmin' => false,
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function linkText(\DOMXPath $xpath, string $path): string
    {
        return (string) $xpath->evaluate('normalize-space(//nav//a[@href="' . Url::to($path) . '"])');
    }

    public function testAllPrivateDestinationsHaveVisibleTextAndOnlyCurrentPageIsActive(): void
    {
        $xpath = $this->xpath($this->header());
        foreach ([
            '' => 'Tableau de bord', 'meter-readings' => 'Saisir mes index',
            'tariffs' => 'Tarifs', 'reconciliation' => 'Rapprochement facture',
            'advances' => 'Acomptes', 'batteries' => 'Batteries',
            'meters' => 'Compteurs', 'stats' => 'Statistiques', 'account' => 'Mon compte',
        ] as $path => $label) {
            self::assertSame($label, $this->linkText($xpath, $path));
        }
        self::assertSame(1.0, $xpath->evaluate('count(//a[@aria-current="page"])'));
        self::assertSame(Url::to('tariffs'), $xpath->evaluate('string(//a[@aria-current="page"]/@href)'));
        self::assertSame('Se déconnecter', $xpath->evaluate('normalize-space(//form[@class="logout-form"]//button)'));
        self::assertSame('post', $xpath->evaluate('string(//form[@class="logout-form"]/@method)'));
        self::assertSame(Url::to('auth/logout'), $xpath->evaluate('string(//form[@class="logout-form"]/@action)'));
        self::assertNotEmpty($xpath->evaluate('string(//form[@class="logout-form"]//input[@name="_csrf"]/@value)'));
        self::assertSame('', $this->linkText($xpath, 'admin'));
    }

    public function testAdminNavigationIsVisibleForAnAuthenticatedAdmin(): void
    {
        $xpath = $this->xpath($this->header(data: ['isAdmin' => true, 'current' => 'admin']));
        self::assertSame('Admin', $this->linkText($xpath, 'admin'));
        self::assertSame(Url::to('admin'), $xpath->evaluate('string(//a[@aria-current="page"]/@href)'));
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function locales(): iterable
    {
        yield 'French' => ['fr', 'Statistiques', 'Langue', 'Menu', 'Changer de thème', 'Activer le thème clair'];
        yield 'English' => ['en', 'Statistics', 'Language', 'Menu', 'Toggle theme', 'Switch to light theme'];
        yield 'German' => ['de', 'Statistiken', 'Sprache', 'Menü', 'Thema umschalten', 'Helles Design aktivieren'];
        yield 'Dutch' => ['nl', 'Statistieken', 'Taal', 'Menu', 'Thema wisselen', 'Licht thema inschakelen'];
    }

    #[DataProvider('locales')]
    public function testPublicHeaderHasTranslatedVisibleControls(
        string $locale, string $stats, string $language, string $menu, string $theme, string $light,
    ): void {
        $html = $this->header($locale, ['authenticated' => false, 'current' => 'stats']);
        $xpath = $this->xpath($html);
        self::assertSame($stats, $this->linkText($xpath, 'stats'));
        self::assertSame($language . ' ' . strtoupper($locale), $xpath->evaluate('normalize-space(//summary)'));
        self::assertSame($language . ' ' . strtoupper($locale), $xpath->evaluate('string(//summary/@aria-label)'));
        self::assertSame($menu, $xpath->evaluate('normalize-space(//button[@data-header-toggle])'));
        self::assertSame($theme, $xpath->evaluate('normalize-space(//button[@id="theme-toggle"])'));
        self::assertSame($light, $xpath->evaluate('string(//button[@id="theme-toggle"]/@data-theme-light)'));
        self::assertNotEmpty($this->linkText($xpath, 'login'));
        self::assertStringNotContainsString('logout-form', $html);
        self::assertStringNotContainsString('🌐', $html);
    }

    public function testExternalLinksHaveTextAndPreserveTheirConfigurationAndAttributes(): void
    {
        $xpath = $this->xpath($this->header(data: [
            'discordUrl' => 'https://discord.gg/example', 'donateUrl' => 'https://example.com/support?a=1&b=2',
        ]));
        foreach ([
            'https://discord.gg/example' => 'Rejoindre le Discord',
            'https://github.com/Rayman223/energy-cost-management' => 'Code source sur GitHub',
            'https://example.com/support?a=1&b=2' => 'Soutenir le projet',
        ] as $url => $text) {
            $link = '//a[@href="' . $url . '"]';
            self::assertSame($text, $xpath->evaluate('normalize-space(' . $link . ')'));
            self::assertSame('_blank', $xpath->evaluate('string(' . $link . '/@target)'));
            self::assertSame('noopener noreferrer', $xpath->evaluate('string(' . $link . '/@rel)'));
        }
        self::assertSame(0.0, $xpath->evaluate('count(//svg)'));
        $html = $this->header();
        self::assertStringNotContainsString('discord.gg', $html);
        self::assertStringNotContainsString('Soutenir le projet', $html);
        self::assertStringContainsString('Code source sur GitHub', $html);
    }

    public function testMenuIsVisibleBeforeJavaScriptAndToggleTargetsIt(): void
    {
        $xpath = $this->xpath($this->header());
        self::assertSame('header-menu', $xpath->evaluate('string(//button[@data-header-toggle]/@aria-controls)'));
        self::assertSame('true', $xpath->evaluate('string(//button[@data-header-toggle]/@aria-expanded)'));
        self::assertSame(1.0, $xpath->evaluate('count(//button[@data-header-toggle][@hidden])'));
        self::assertSame(1.0, $xpath->evaluate('count(//div[@id="header-menu"][not(@hidden)])'));
    }

    public function testTextualLanguageOptionDoesNotChangeStandaloneSwitcher(): void
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');
        $html = $view->partial('_lang-switcher', ['available' => ['fr', 'en']]);
        self::assertStringContainsString('🌐', $html);
        self::assertStringContainsString('▾', $html);
    }

    #[DataProvider('locales')]
    public function testLandingUsesSameTranslatedControls(
        string $locale, string $stats, string $language, string $menu, string $theme, string $light,
    ): void {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', $locale);
        $xpath = $this->xpath($view->render('welcome', ['available' => ['fr', 'en', 'de', 'nl']]));
        self::assertSame($stats, $this->linkText($xpath, 'stats'));
        self::assertSame($menu, $xpath->evaluate('normalize-space(//header//button[@data-header-toggle])'));
        self::assertSame($language . ' ' . strtoupper($locale), $xpath->evaluate('normalize-space(//header//summary)'));
        self::assertSame($theme, $xpath->evaluate('normalize-space(//header//button[@id="theme-toggle"])'));
        self::assertSame($light, $xpath->evaluate('string(//header//button[@id="theme-toggle"]/@data-theme-light)'));
        self::assertNotEmpty($this->linkText($xpath, 'login'));
        self::assertSame(0.0, $xpath->evaluate('count(//header//svg)'));
    }
}
