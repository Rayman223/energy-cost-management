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
     * @return array{from: array<string, mixed>|null, to: array<string, mixed>|null}
     */
    public function getLastTwoReadings(): array;

    /**
     * Fenêtre de relevés nécessaire à l'interpolation à minuit d'un mois (le
     * dernier relevé avant le mois, ceux du mois, le premier après le mois),
     * triés par horodatage croissant.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForInterpolation(int $year, int $month): array;
}
