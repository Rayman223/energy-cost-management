<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\LocaleResolver;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    private const AVAILABLE = ['fr', 'en', 'nl', 'de'];

    public function testUserLocaleWins(): void
    {
        self::assertSame('en', LocaleResolver::resolve('en', 'fr', 'de', self::AVAILABLE, 'fr'));
    }

    public function testQueryLangUsedWhenNoUserLocale(): void
    {
        self::assertSame('de', LocaleResolver::resolve(null, 'de', 'fr-BE', self::AVAILABLE, 'fr'));
    }

    public function testAcceptLanguageUsedAsThirdChoice(): void
    {
        self::assertSame('fr', LocaleResolver::resolve(null, null, 'fr-BE,fr;q=0.9,en;q=0.8', self::AVAILABLE, 'en'));
    }

    public function testFallsBackToDefaultWhenNothingMatches(): void
    {
        self::assertSame('fr', LocaleResolver::resolve(null, null, 'es-ES,pt;q=0.9', self::AVAILABLE, 'fr'));
    }

    public function testUnavailableLocaleIsIgnored(): void
    {
        self::assertSame('fr', LocaleResolver::resolve('es', null, '', self::AVAILABLE, 'fr'));
    }

    public function testRegionSubtagIsNormalized(): void
    {
        self::assertSame('en', LocaleResolver::resolve('en-US', null, '', self::AVAILABLE, 'fr'));
    }
}
