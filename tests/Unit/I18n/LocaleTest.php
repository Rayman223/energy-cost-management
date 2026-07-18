<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Locale;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [
        'i18n' => ['available' => ['fr', 'en', 'nl', 'de'], 'default_locale' => 'fr'],
    ];

    protected function setUp(): void
    {
        unset($_GET['lang'], $_COOKIE['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    protected function tearDown(): void
    {
        unset($_GET['lang'], $_COOKIE['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testExplicitQueryWinsOverProfile(): void
    {
        $_GET['lang'] = 'de';
        self::assertSame('de', Locale::resolve($this->config, 'fr'));
    }

    public function testProfileUsedWhenNoQuery(): void
    {
        self::assertSame('nl', Locale::resolve($this->config, 'nl'));
    }

    public function testCookieUsedWhenNoQueryNorProfile(): void
    {
        $_COOKIE['lang'] = 'en';
        self::assertSame('en', Locale::resolve($this->config, null));
    }

    public function testAcceptLanguageFallback(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';
        self::assertSame('de', Locale::resolve($this->config, null));
    }

    public function testDefaultWhenNothingMatches(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-ES';
        self::assertSame('fr', Locale::resolve($this->config, null));
    }

    public function testInvalidQueryIsIgnored(): void
    {
        $_GET['lang'] = 'xx';
        self::assertSame('nl', Locale::resolve($this->config, 'nl'));
    }

    public function testAvailableReadsConfig(): void
    {
        self::assertSame(['fr', 'en', 'nl', 'de'], Locale::available($this->config));
        // Sans section i18n : les 4 langues livrées sont disponibles (défaut aligné
        // sur les catalogues app/translations/{fr,en,nl,de}.php, cf. #153).
        self::assertSame(['fr', 'en', 'nl', 'de'], Locale::available([]));
    }

    public function testExplicitChoiceReturnsValidQuery(): void
    {
        $_GET['lang'] = 'DE';
        self::assertSame('de', Locale::explicitChoice($this->config));
    }

    public function testExplicitChoiceNullWhenNoQuery(): void
    {
        self::assertNull(Locale::explicitChoice($this->config));
    }

    public function testExplicitChoiceNullWhenInvalidQuery(): void
    {
        $_GET['lang'] = 'xx';
        self::assertNull(Locale::explicitChoice($this->config));
    }

    public function testExplicitChoiceNullWhenEmptyQuery(): void
    {
        $_GET['lang'] = '';
        self::assertNull(Locale::explicitChoice($this->config));
    }
}
