<?php

declare(strict_types=1);

namespace App\I18n;

use App\Domain\Currency;
use DateTimeInterface;

/**
 * Formatage localisé (dates, nombres, devises). Utilise ext-intl si disponible ;
 * sinon repli neutre (jamais d'erreur fatale). Installer ext-intl en production
 * pour un rendu conforme à chaque locale.
 */
final class Formatter
{
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

        return number_format($amount, 2, '.', ' ') . ' ' . (Currency::symbol($currency));
    }

    /**
     * Symbole seul d'une devise (« € »), pour les unités composées où `money()` ne
     * convient pas — un tarif « 0,0254 €/kWh » n'est pas un montant formatable.
     * Pendant serveur du `CURRENCY_SYMBOL` de dashboard.js, et même parti pris : on
     * n'écrit jamais « € » en dur, le symbole suit la devise de la grille.
     *
     * Repli sur le code ISO (« PLN ») pour une devise hors table : plus lisible qu'un
     * symbole approximatif, et toujours compréhensible.
     */
    public function currencySymbol(string $currency = 'EUR'): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $fmt  = $this->currencyFmt[$currency] ??= new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
            $part = $fmt->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
            // intl rend le symbole du formatter, pas celui de $currency : on ne l'utilise
            // que si la devise demandée est bien celle par défaut du formatter.
            if ($part !== '' && $fmt->getTextAttribute(\NumberFormatter::CURRENCY_CODE) === $currency) {
                return $part;
            }
        }

        return Currency::symbol($currency);
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
