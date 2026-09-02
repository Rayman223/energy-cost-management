<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Lecture des séries d'index d'une batterie (#26), pour le calcul du bilan.
 * Seam de test de {@see \App\Service\BatterySavingsService}, sans base de données.
 *
 * Distinct de {@see BatteryIngestionInterface}, qui ne porte que l'écriture : un
 * service de calcul ne doit pas hériter du droit d'écrire des index.
 *
 * @see \App\Repository\BatteryReadingRepository implémentation concrète (PDO/MySQL).
 */
interface BatteryReadingsInterface
{
    /**
     * Séries d'index CUMULÉS, triées par horodatage croissant, une par compteur.
     *
     * Les relevés où le compteur est NUL sont omis de SA série : les deux compteurs
     * se relèvent indépendamment, et une ligne sans décharge ne doit pas y injecter
     * un point à zéro — qui produirait une consommation négative puis un rattrapage
     * fantôme au relevé suivant.
     *
     * La forme `{ts, value}` est celle qu'attend
     * {@see \App\Service\MonthlyConsumptionInterpolator}, déjà utilisée pour
     * mensualiser les relevés gaz et eau.
     *
     * @return array{charge: list<array{ts:int,value:float}>, discharge: list<array{ts:int,value:float}>}
     */
    public function indexSeries(): array;
}
