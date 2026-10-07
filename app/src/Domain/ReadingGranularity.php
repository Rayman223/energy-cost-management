<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Granularité de plafonnement des index électricité : au plus un relevé par
 * registre et par créneau aligné.
 *
 * Le créneau ne dépend **pas** du contrat (issue #93). Le pas de relevé est une
 * propriété du COMPTEUR — un compteur communicant produit du quart-horaire quel que
 * soit le tarif souscrit — alors que `tariff_grids.pricing_mode` décrit la façon de
 * FACTURER. Les lier rejetait la courbe de charge de tout utilisateur en tarif fixe
 * (ou indexé au mois, #93) : la saisie web refusait le deuxième index du jour et
 * l'API d'ingestion jetait le surplus en silence. L'électricité est donc plafonnée
 * au quart d'heure en permanence, cf.
 * {@see \App\Service\ReadingGranularityPolicy::electricityDefault()}.
 *
 * Les trois cas restent utilisés :
 *   - {@see self::QuarterHour} — plafond des index électricité, un par MTU de 15 min ;
 *   - {@see self::Hour} — un index par MTU horaire ENTSO-E ;
 *   - {@see self::Day} — plafond journalier des index de batterie (#26) et
 *     délimitation du jour civil dans les agrégations.
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
     * Bornes `[start, end)` du créneau aligné contenant $moment, exprimées dans
     * $tz. Les transitions d'heure (DST) sont gérées, mais pas de la même façon
     * selon l'unité (cf. la fin de la méthode).
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function bucket(DateTimeImmutable $moment, DateTimeZone $tz): array
    {
        $local = $moment->setTimezone($tz);

        // `setTime()` conserve l'offset du relevé quand l'heure de mur ne change pas,
        // si bien que les deux passages de 02:xx au retour à l'heure d'hiver gardent
        // chacun leur propre début de créneau.
        $start = match ($this) {
            self::Day  => $local->setTime(0, 0, 0),
            self::Hour => $local->setTime((int) $local->format('G'), 0, 0),
            self::QuarterHour => $local->setTime((int) $local->format('G'), intdiv((int) $local->format('i'), 15) * 15, 0),
        };

        // Le jour se termine en heure de MUR (`modify()`) : un jour de bascule dure
        // bien 23 h ou 25 h, et reste un jour calendaire.
        //
        // L'heure et le quart d'heure, eux, valent un MTU ENTSO-E : leur durée est
        // ABSOLUE. `modify('+1 hour')` raisonnerait aussi en heure de mur — au retour
        // à l'heure d'hiver, « 02:00 + 1 heure » vaut 03:00 locale, soit DEUX heures
        // réelles. Le créneau du premier passage de 02:xx (CEST) avalait alors le
        // second (CET), c'est-à-dire deux MTU distincts, chacun avec son prix
        // ({@see \App\Service\EntsoePriceParser}) : un index légitime du premier
        // passage était refusé au motif qu'un index existait déjà dans le second.
        $end = match ($this) {
            self::Day         => $start->modify('+1 day'),
            self::Hour        => $start->setTimestamp($start->getTimestamp() + 3600),
            self::QuarterHour => $start->setTimestamp($start->getTimestamp() + 900),
        };

        return [$start, $end];
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
