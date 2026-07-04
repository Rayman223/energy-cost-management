<?php

declare(strict_types=1);

namespace App\I18n;

use DateTimeInterface;

/**
 * Formatage localisé (dates, nombres, devises). Utilise ext-intl si disponible ;
 * sinon repli neutre (jamais d'erreur fatale). Installer ext-intl en production
 * pour un rendu conforme à chaque locale.
 */
final class Formatter
{
    /** Symboles de repli quand ext-intl est absent. */
    private const SYMBOLS = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF',
        'PLN' => 'zł', 'SEK' => 'kr', 'DKK' => 'kr', 'NOK' => 'kr', 'CZK' => 'Kč', 'HUF' => 'Ft',
    ];

    public function __construct(private readonly string $locale)
    {
    }

    public function money(float $amount, string $currency = 'EUR'): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
            $out = $fmt->formatCurrency($amount, $currency);
            if (is_string($out)) {
                return $out;
            }
        }

        return number_format($amount, 2, '.', ' ') . ' ' . (self::SYMBOLS[$currency] ?? $currency);
    }

    public function number(float $value, int $decimals = 2): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $out = $fmt->format($value);
            if (is_string($out)) {
                return $out;
            }
        }

        return number_format($value, $decimals, '.', ' ');
    }

    public function date(DateTimeInterface $date): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $fmt = new \IntlDateFormatter($this->locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
            $out = $fmt->format($date);
            if (is_string($out)) {
                return $out;
            }
        }

        return $date->format('Y-m-d');
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
