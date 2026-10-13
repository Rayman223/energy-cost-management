<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Support\Url;
use App\View\ViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderNavigationTest extends TestCase
{
    private const LANGUAGE_NAMES = ['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch', 'nl' => 'Nederlands'];

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
        $link = '//header//a[not(@class="logo")][@href="' . Url::to($path) . '"]';
        $label = $xpath->evaluate('count(' . $link . '/span)') > 0 ? $link . '/span[1]' : $link;

        return (string) $xpath->evaluate('normalize-space(' . $label . ')');
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
        self::assertSame('Administration', $this->linkText($xpath, 'admin'));
        self::assertSame(Url::to('admin'), $xpath->evaluate('string(//a[@aria-current="page"]/@href)'));
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function locales(): iterable
    {
        yield 'French' => ['fr', 'Statistiques', 'Langue', 'Menu', 'Thème sombre', 'Activer le thème clair'];
        yield 'English' => ['en', 'Statistics', 'Language', 'Menu', 'Dark theme', 'Switch to light theme'];
        yield 'German' => ['de', 'Statistiken', 'Sprache', 'Menü', 'Dunkles Design', 'Helles Design aktivieren'];
        yield 'Dutch' => ['nl', 'Statistieken', 'Taal', 'Menu', 'Donker thema', 'Licht thema inschakelen'];
    }

    #[DataProvider('locales')]
    public function testPublicHeaderHasTranslatedVisibleControls(
        string $locale, string $stats, string $language, string $menu, string $theme, string $light,
    ): void {
        $html = $this->header($locale, ['authenticated' => false, 'current' => 'stats']);
        $xpath = $this->xpath($html);
        self::assertSame($stats, $this->linkText($xpath, 'stats'));
        self::assertSame(self::LANGUAGE_NAMES[$locale], $xpath->evaluate('normalize-space(//summary/span[@class="lang-current"])'));
        self::assertSame($language . ' : ' . self::LANGUAGE_NAMES[$locale], $xpath->evaluate('string(//summary/@aria-label)'));
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
        self::assertSame(1.0, $xpath->evaluate('count(//*[@id="header-menu"][not(@hidden)])'));
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
        self::assertSame(self::LANGUAGE_NAMES[$locale], $xpath->evaluate('normalize-space(//header//summary/span[@class="lang-current"])'));
        self::assertSame($theme, $xpath->evaluate('normalize-space(//header//button[@id="theme-toggle"])'));
        self::assertSame($light, $xpath->evaluate('string(//header//button[@id="theme-toggle"]/@data-theme-light)'));
        self::assertNotEmpty($this->linkText($xpath, 'login'));
        self::assertSame(0.0, $xpath->evaluate('count(//header//svg)'));
    }

    public function testCategoriesContainTheirExpectedDestinations(): void
    {
        $xpath = $this->xpath($this->header(data: ['isAdmin' => true]));
        foreach ([
            'followup' => ['meters', 'batteries', 'stats'],
            'budget' => ['tariffs', 'advances', 'reconciliation'],
            'account' => ['account', 'admin'],
        ] as $group => $paths) {
            foreach ($paths as $path) {
                self::assertSame(1.0, $xpath->evaluate('count(//details[@data-header-group="' . $group . '"]//a[@href="' . Url::to($path) . '"])'));
            }
        }
        self::assertSame(1.0, $xpath->evaluate('count(//nav/a[@href="' . Url::to('') . '"])'));
        self::assertSame(1.0, $xpath->evaluate('count(//nav/a[@href="' . Url::to('meter-readings') . '"])'));
        self::assertSame('Budget', $xpath->evaluate('normalize-space(//details[@data-header-group="budget"]/summary)'));
        self::assertNotEmpty($xpath->evaluate('normalize-space(//details[@data-header-group="budget"]//small)'));
    }

    public function testActiveCategoryFollowsItsPage(): void
    {
        foreach ([
            'tariffs' => 'budget', 'advances' => 'budget', 'reconciliation' => 'budget',
            'meters' => 'followup', 'batteries' => 'followup', 'stats' => 'followup',
            'account' => 'account', 'admin' => 'account',
        ] as $page => $group) {
            $xpath = $this->xpath($this->header(data: ['current' => $page, 'isAdmin' => true]));
            self::assertSame($group, $xpath->evaluate('string(//details[contains(concat(" ", @class, " "), " current ")]/@data-header-group)'));
            self::assertSame(1.0, $xpath->evaluate('count(//a[@aria-current="page"])'));
        }
        $xpath = $this->xpath($this->header(data: ['current' => 'meter-readings']));
        self::assertSame(0.0, $xpath->evaluate('count(//details[contains(concat(" ", @class, " "), " current ")])'));
    }

    public function testLanguageThemeAndAccountAreOutsideCollapsibleNavigation(): void
    {
        $xpath = $this->xpath($this->header());
        self::assertSame(0.0, $xpath->evaluate('count(//*[@id="header-menu"]//button[@id="theme-toggle"])'));
        self::assertSame(0.0, $xpath->evaluate('count(//*[@id="header-menu"]//details[contains(@class, "lang-menu")])'));
        self::assertSame(0.0, $xpath->evaluate('count(//*[@id="header-menu"]//details[@data-header-group="account"])'));
        self::assertSame('switch', $xpath->evaluate('string(//button[@id="theme-toggle"]/@role)'));
        self::assertSame('false', $xpath->evaluate('string(//button[@id="theme-toggle"]/@aria-checked)'));
        self::assertSame(4.0, $xpath->evaluate('count(//details[contains(@class, "lang-menu")]//a)'));
    }

    public function testAnonymousHeaderHasPublicNavigationAndNoPrivateCategories(): void
    {
        $xpath = $this->xpath($this->header(data: ['authenticated' => false, 'isAdmin' => true, 'current' => 'stats']));
        foreach (['followup', 'budget', 'account'] as $group) {
            self::assertSame(0.0, $xpath->evaluate('count(//details[@data-header-group="' . $group . '"])'));
        }
        self::assertSame(1.0, $xpath->evaluate('count(//details[@data-header-group="project"])'));
        self::assertSame('Statistiques', $this->linkText($xpath, 'stats'));
        self::assertSame('Se connecter', $this->linkText($xpath, 'login'));
    }
}
