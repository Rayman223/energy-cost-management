<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Lecture des relevés gaz manuels consommés par CostCalculationService.
 * Seam de test (sans base de données).
 *
 * @see \App\Repository\GasRepository implémentation concrète (PDO/MySQL).
 */
interface GasReadingRepositoryInterface
{
    /**
     * Les deux relevés les plus récents (pour la dernière période).
     *
     * @return array{from:array|null, to:array|null}
     */
    public function getLastTwoReadings(): array;

    /**
     * Les deux relevés encadrant un mois calendaire (interpolation linéaire).
     *
     * @return array{from: array|null, to: array|null}
     */
    public function getTwoReadingsForMonth(int $year, int $month): array;
}
