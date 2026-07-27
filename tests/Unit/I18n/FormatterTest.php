<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Le formatage exact dépend d'ext-intl (séparateurs par locale). On teste des
 * propriétés robustes valables avec ou sans intl (repli inclus).
 */
final class FormatterTest extends TestCase
{
    public function testMoneyContainsAmountAndCurrencySymbol(): void
    {
        $out = (new Formatter('fr'))->money(12.5, 'EUR');
        self::assertStringContainsString('12', $out);
        self::assertStringContainsString('€', $out);
    }

    public function testMoneyFallsBackToCodeForUnknownSymbol(): void
    {
        // Sans intl, une devise sans symbole connu affiche le code ISO.
        if (class_exists(\NumberFormatter::class)) {
            self::markTestSkipped('ext-intl présent : le rendu utilise le symbole localisé.');
        }
        self::assertStringContainsString('RON', (new Formatter('fr'))->money(10.0, 'RON'));
    }

    /**
     * Symbole seul, pour les unités composées type « €/kWh » (#229). Vérifié sur
     * plusieurs locales : le symbole suit la DEVISE, pas la langue de l'utilisateur —
     * un contrat en euros reste en euros pour un lecteur néerlandophone.
     */
    public function testCurrencySymbolFollowsTheCurrencyNotTheLocale(): void
    {
        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            $formatter = new Formatter($locale);
            self::assertSame('€', $formatter->currencySymbol('EUR'), 'locale ' . $locale);
            self::assertSame('$', $formatter->currencySymbol('USD'), 'locale ' . $locale);
        }
    }

    /** Devise hors table : le code ISO est plus honnête qu'un symbole approximatif. */
    public function testCurrencySymbolFallsBackToTheIsoCode(): void
    {
        self::assertSame('XYZ', (new Formatter('fr'))->currencySymbol('XYZ'));
    }

    /**
     * `currencySymbol()` partage le cache de formatters avec `money()` : appeler l'un
     * puis l'autre sur deux devises ne doit pas rendre le symbole de la première.
     */
    public function testCurrencySymbolDoesNotLeakAcrossCurrencies(): void
    {
        $formatter = new Formatter('fr');

        $formatter->money(10.0, 'USD');
        self::assertSame('€', $formatter->currencySymbol('EUR'));
        self::assertSame('$', $formatter->currencySymbol('USD'));
    }

    public function testNumberKeepsDecimals(): void
    {
        $out = (new Formatter('fr'))->number(1234.5, 2);
        self::assertStringContainsString('234', $out);
        self::assertMatchesRegularExpression('/50$/', $out); // deux décimales
    }

    public function testDateIsNonEmpty(): void
    {
        $out = (new Formatter('fr'))->date(new \DateTimeImmutable('2026-07-15 10:00:00'));
        self::assertNotSame('', $out);
        self::assertStringContainsString('2026', $out);
    }

    public function testLocaleAccessor(): void
    {
        self::assertSame('nl', (new Formatter('nl'))->locale());
    }

    public function testCachedFormattersStayConsistentAcrossCalls(): void
    {
        // Les formatters intl sont mémoïsés par devise / décimales : des appels
        // répétés (mêmes clés) ou entrelacés (clés différentes) doivent rester
        // idempotents et indépendants.
        $f = new Formatter('fr');

        self::assertSame($f->money(12.5, 'EUR'), $f->money(12.5, 'EUR'));
        self::assertSame($f->money(12.5, 'USD'), $f->money(12.5, 'USD'));
        self::assertSame($f->number(3.14159, 2), $f->number(3.14159, 2));
        self::assertSame($f->number(3.14159, 4), $f->number(3.14159, 4));

        // Le cache par devise ne « contamine » pas une autre devise.
        $eur = $f->money(1.0, 'EUR');
        $usd = $f->money(1.0, 'USD');
        self::assertSame($eur, $f->money(1.0, 'EUR'));
        self::assertNotSame($eur, $usd);
    }
}
