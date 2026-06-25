<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Lecture des deltas journaliers électricité/solaire consommés par
 * CostCalculationService. Seam de test : permet de substituer une fausse
 * implémentation sans base de données.
 *
 * @see \App\Repository\LegacyDailyRepository implémentation concrète (PDO/MySQL).
 */
interface LegacyDailyRepositoryInterface
{
    /**
     * Deltas électricité/solaire du mois calendaire courant.
     * Renvoie [] si aucune donnée n'est disponible.
     */
    public function getMonthlyDeltas(): array;

    /**
     * Deltas électricité/solaire pour un mois calendaire donné.
     * Renvoie [] si aucune donnée n'est disponible pour ce mois.
     */
    public function getMonthlyDeltasForMonth(int $year, int $month): array;
}
