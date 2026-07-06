<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Référentiel des pays européens (UE-27 + NO, IS, CH, GB) : devise par défaut et
 * taux de TVA standard indicatif. Utilisé pour les listes déroulantes de la page
 * tarifs (pays, devise) et le préremplissage du taux de TVA selon le pays.
 *
 * Les noms de pays sont localisés via ext-intl (\Locale::getDisplayRegion) avec
 * un repli anglais quand l'extension est absente — même stratégie que Formatter.
 *
 * ⚠️ Les taux de TVA sont des défauts indicatifs (taux standard), modifiables par
 * grille. Ils ne se substituent pas à une vérification fiscale à jour.
 */
final class EuropeanCountries
{
    /**
     * ISO 3166-1 alpha-2 → devise ISO 4217 + taux de TVA standard (%).
     *
     * @var array<string, array{currency: string, vat: float}>
     */
    public const COUNTRIES = [
        'AT' => ['currency' => 'EUR', 'vat' => 20.0],
        'BE' => ['currency' => 'EUR', 'vat' => 21.0],
        'BG' => ['currency' => 'BGN', 'vat' => 20.0],
        'HR' => ['currency' => 'EUR', 'vat' => 25.0],
        'CY' => ['currency' => 'EUR', 'vat' => 19.0],
        'CZ' => ['currency' => 'CZK', 'vat' => 21.0],
        'DK' => ['currency' => 'DKK', 'vat' => 25.0],
        'EE' => ['currency' => 'EUR', 'vat' => 24.0],
        'FI' => ['currency' => 'EUR', 'vat' => 25.5],
        'FR' => ['currency' => 'EUR', 'vat' => 20.0],
        'DE' => ['currency' => 'EUR', 'vat' => 19.0],
        'GR' => ['currency' => 'EUR', 'vat' => 24.0],
        'HU' => ['currency' => 'HUF', 'vat' => 27.0],
        'IE' => ['currency' => 'EUR', 'vat' => 23.0],
        'IT' => ['currency' => 'EUR', 'vat' => 22.0],
        'LV' => ['currency' => 'EUR', 'vat' => 21.0],
        'LT' => ['currency' => 'EUR', 'vat' => 21.0],
        'LU' => ['currency' => 'EUR', 'vat' => 17.0],
        'MT' => ['currency' => 'EUR', 'vat' => 18.0],
        'NL' => ['currency' => 'EUR', 'vat' => 21.0],
        'PL' => ['currency' => 'PLN', 'vat' => 23.0],
        'PT' => ['currency' => 'EUR', 'vat' => 23.0],
        'RO' => ['currency' => 'RON', 'vat' => 21.0],
        'SK' => ['currency' => 'EUR', 'vat' => 23.0],
        'SI' => ['currency' => 'EUR', 'vat' => 22.0],
        'ES' => ['currency' => 'EUR', 'vat' => 21.0],
        'SE' => ['currency' => 'SEK', 'vat' => 25.0],
        'NO' => ['currency' => 'NOK', 'vat' => 25.0],
        'IS' => ['currency' => 'ISK', 'vat' => 24.0],
        'CH' => ['currency' => 'CHF', 'vat' => 8.1],
        'GB' => ['currency' => 'GBP', 'vat' => 20.0],
    ];

    /** Noms anglais de repli quand ext-intl est absent. */
    private const FALLBACK_NAMES = [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
        'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia',
        'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
        'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy', 'LV' => 'Latvia',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands',
        'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden', 'NO' => 'Norway',
        'IS' => 'Iceland', 'CH' => 'Switzerland', 'GB' => 'United Kingdom',
    ];

    public static function isValid(string $iso2): bool
    {
        return isset(self::COUNTRIES[strtoupper($iso2)]);
    }

    /** Nom localisé du pays (ext-intl si disponible, sinon repli anglais). */
    public static function name(string $iso2, string $locale): string
    {
        $iso2 = strtoupper($iso2);

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('und-' . $iso2, $locale);
            if (is_string($name) && $name !== '' && $name !== $iso2) {
                return $name;
            }
        }

        return self::FALLBACK_NAMES[$iso2] ?? $iso2;
    }

    /**
     * Pays (ISO2 => nom localisé) triés alphabétiquement par nom pour la locale.
     *
     * @return array<string, string>
     */
    public static function sortedForLocale(string $locale): array
    {
        $out = [];
        foreach (array_keys(self::COUNTRIES) as $iso2) {
            $out[$iso2] = self::name($iso2, $locale);
        }
        uasort($out, static fn (string $a, string $b): int => strcoll($a, $b) ?: strcmp($a, $b));

        return $out;
    }

    public static function vatRate(string $iso2): ?float
    {
        return self::COUNTRIES[strtoupper($iso2)]['vat'] ?? null;
    }

    public static function currencyOf(string $iso2): ?string
    {
        return self::COUNTRIES[strtoupper($iso2)]['currency'] ?? null;
    }

    /**
     * Devises distinctes présentes dans le référentiel, triées alphabétiquement.
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        $currencies = array_values(array_unique(
            array_map(static fn (array $c): string => $c['currency'], self::COUNTRIES)
        ));
        sort($currencies);

        return $currencies;
    }
}
