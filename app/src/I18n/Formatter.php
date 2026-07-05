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

    /**
     * Formatters intl mémoïsés (construction coûteuse) : un `NumberFormatter`
     * CURRENCY par devise, un DECIMAL par nombre de décimales, un
     * `IntlDateFormatter` unique. Réutilisés d'un appel à l'autre.
     *
     * @var array<string, \NumberFormatter>
     */
    private array $currencyFmt = [];

    /** @var array<int, \NumberFormatter> */
    private array $decimalFmt = [];

    private ?\IntlDateFormatter $dateFmt = null;

    public function __construct(private readonly string $locale)
    {
    }

    public function money(float $amount, string $currency = 'EUR'): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $fmt = $this->currencyFmt[$currency] ??= new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
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
            $fmt = $this->decimalFmt[$decimals] ??= (function () use ($decimals): \NumberFormatter {
                $f = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
                $f->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                return $f;
            })();
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
            $fmt = $this->dateFmt ??= new \IntlDateFormatter($this->locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
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
