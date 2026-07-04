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
}
