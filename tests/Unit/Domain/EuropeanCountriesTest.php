<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\EuropeanCountries;
use PHPUnit\Framework\TestCase;

final class EuropeanCountriesTest extends TestCase
{
    public function testIsValidAcceptsKnownIso2CaseInsensitive(): void
    {
        self::assertTrue(EuropeanCountries::isValid('FR'));
        self::assertTrue(EuropeanCountries::isValid('be'));
        self::assertTrue(EuropeanCountries::isValid('Gb'));
    }

    public function testIsValidRejectsUnknownOrMalformedCodes(): void
    {
        self::assertFalse(EuropeanCountries::isValid('US'));
        self::assertFalse(EuropeanCountries::isValid('ZZ'));
        self::assertFalse(EuropeanCountries::isValid(''));
        self::assertFalse(EuropeanCountries::isValid('FRA'));
    }

    public function testCurrenciesAreNonEmptySortedUniqueAndContainEur(): void
    {
        $currencies = EuropeanCountries::currencies();

        self::assertNotEmpty($currencies);
        self::assertContains('EUR', $currencies);
        self::assertSame(array_values(array_unique($currencies)), $currencies, 'devises dédupliquées');

        $sorted = $currencies;
        sort($sorted);
        self::assertSame($sorted, $currencies, 'devises triées alphabétiquement');
    }

    public function testSortedForLocaleKeyedByIso2AndSortedByName(): void
    {
        $countries = EuropeanCountries::sortedForLocale('fr');

        self::assertNotEmpty($countries);
        self::assertArrayHasKey('FR', $countries);
        foreach (array_keys($countries) as $iso2) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $iso2);
            self::assertTrue(EuropeanCountries::isValid($iso2));
        }

        $names = array_values($countries);
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => strcoll($a, $b) ?: strcmp($a, $b));
        self::assertSame($sorted, $names, 'pays triés par nom localisé');
    }
}
