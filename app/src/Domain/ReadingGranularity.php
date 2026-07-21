<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Granularité de plafonnement des index électricité : au plus un relevé par
 * registre et par créneau aligné. Le créneau dépend du mode de tarification
 * (issue #165) :
 *   - {@see self::Day} quand le tarif dynamique est désactivé (la granularité
 *     horaire n'a pas d'intérêt et alourdit la base) ;
 *   - {@see self::QuarterHour} quand il est activé (périodes de 15 min, alignées
 *     sur les MTU du marché ENTSO-E).
 *
 * Les créneaux sont **alignés** (jour calendaire, ou quart d'heure :00/:15/:30/:45)
 * et calculés dans le fuseau de l'utilisateur, cohérent avec le stockage UTC +
 * classement T1/T2 par fuseau (#172/#174).
 */
enum ReadingGranularity
{
    case Day;
    case QuarterHour;

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
            self::Day => [$local->setTime(0, 0, 0), '+1 day'],
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
            self::Day         => $start->format('d/m/Y'),
            self::QuarterHour => $start->format('d/m/Y H:i'),
        };
    }
}
