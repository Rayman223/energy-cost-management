<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Granularité de plafonnement des index électricité : au plus un relevé par
 * registre et par créneau aligné. Le créneau suit la **résolution de facturation**
 * de la grille tarifaire active à la date du relevé (issue #10, cf.
 * {@see self::forPricingMode()}) :
 *   - {@see self::Day} en tarif fixe — aucune résolution intra-journalière n'entre
 *     dans le calcul, un index par jour suffit et n'alourdit pas la base ;
 *   - {@see self::Hour} en 'dynamic_hourly' — un index par MTU horaire ENTSO-E ;
 *   - {@see self::QuarterHour} en 'dynamic_quarter' — un index par MTU de 15 min.
 *
 * Le mode étant porté par la grille, donc versionné par valid_from/valid_to (#245),
 * la granularité se résout relevé par relevé : c'est le rôle de
 * {@see \App\Service\ReadingGranularityPolicy}, seul point d'entrée des appelants.
 *
 * Les créneaux sont **alignés** (jour calendaire, heure pleine, ou quart d'heure
 * :00/:15/:30/:45) et calculés dans le fuseau de l'utilisateur, cohérent avec le
 * stockage UTC + classement T1/T2 par fuseau (#172/#174).
 */
enum ReadingGranularity
{
    case Day;
    case Hour;
    case QuarterHour;

    /**
     * Granularité imposée par un mode de tarification (`tariff_grids.pricing_mode`).
     *
     * Source unique du mapping mode → créneau. Tout mode inconnu retombe sur le
     * plafond le plus strict ({@see self::Day}), comme
     * {@see TariffGrid::normalizePricingMode()} retombe sur 'fixed'.
     */
    public static function forPricingMode(string $pricingMode): self
    {
        return match ($pricingMode) {
            'dynamic_quarter' => self::QuarterHour,
            'dynamic_hourly'  => self::Hour,
            default           => self::Day,
        };
    }

    /**
     * Bornes `[start, end)` du créneau aligné contenant $moment, exprimées dans
     * $tz. `modify()` gère les transitions d'heure (DST).
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function bucket(DateTimeImmutable $moment, DateTimeZone $tz): array
    {
        $local = $moment->setTimezone($tz);

        [$start, $step] = match ($this) {
            self::Day  => [$local->setTime(0, 0, 0), '+1 day'],
            self::Hour => [$local->setTime((int) $local->format('G'), 0, 0), '+1 hour'],
            self::QuarterHour => [
                $local->setTime((int) $local->format('G'), intdiv((int) $local->format('i'), 15) * 15, 0),
                '+15 minutes',
            ],
        };

        return [$start, $start->modify($step)];
    }

    /** Libellé français de la limite, pour le message de rejet en saisie manuelle. */
    public function limitLabelFr(): string
    {
        return match ($this) {
            self::Day         => 'un seul index par jour',
            self::Hour        => 'un seul index par heure',
            self::QuarterHour => 'un seul index par tranche de 15 minutes',
        };
    }

    /**
     * Créneau en conflit (début du bucket aligné), formaté dans le fuseau
     * utilisateur pour le message de rejet — pas l'instant de la tentative.
     */
    public function formatBucketFr(DateTimeImmutable $moment, DateTimeZone $tz): string
    {
        [$start] = $this->bucket($moment, $tz);

        return match ($this) {
            self::Day                    => $start->format('d/m/Y'),
            self::Hour, self::QuarterHour => $start->format('d/m/Y H:i'),
        };
    }
}
