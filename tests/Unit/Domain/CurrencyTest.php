<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Source unique du symbole d'une devise, partagée par les unités du domaine et
 * le formatage localisé.
 */
final class CurrencyTest extends TestCase
{
    public function testKnownCurrenciesMapToTheirSymbol(): void
    {
        self::assertSame('€', Currency::symbol('EUR'));
        self::assertSame('$', Currency::symbol('USD'));
        self::assertSame('£', Currency::symbol('GBP'));
        self::assertSame('CHF', Currency::symbol('CHF'));
        self::assertSame('zł', Currency::symbol('PLN'));
    }

    /**
     * Une devise hors table doit rester lisible : le code ISO est préférable à un
     * symbole approximatif — et jamais au symbole d'une AUTRE devise.
     */
    public function testUnknownCurrencyFallsBackToItsIsoCode(): void
    {
        self::assertSame('RON', Currency::symbol('RON'));
        self::assertSame('JPY', Currency::symbol('JPY'));
        self::assertSame('', Currency::symbol(''));
    }

    public function testDefaultIsTheDatabaseDefault(): void
    {
        self::assertSame('EUR', Currency::DEFAULT);
        self::assertSame('€', Currency::symbol());
    }
}
