<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Lecture des deltas journaliers électricité/solaire consommés par
 * CostCalculationService. Seam de test : permet de substituer une fausse
 * implémentation sans base de données.
 *
 * @see \App\Repository\ElectricityReadingRepository implémentation concrète (modèle à registres).
 */
interface LegacyDailyRepositoryInterface
{
    /**
     * Deltas électricité/solaire du mois calendaire courant.
     * Renvoie [] si aucune donnée n'est disponible.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltas(): array;

    /**
     * Deltas électricité/solaire pour un mois calendaire donné.
     * Renvoie [] si aucune donnée n'est disponible pour ce mois.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltasForMonth(int $year, int $month): array;

    /**
     * Consommation IMPORT (T1+T2) ventilée par heure sur [$from, $to], pour
     * croiser avec un prix dynamique horaire (tarif dynamique).
     *
     * @return array<int, array{hour: string, import_kwh: float}>
     */
    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
