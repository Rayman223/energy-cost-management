<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Relevés de compteur manuels (gaz / eau) : lecture et écriture. Implémenté par
 * UtilityReadingRepository (table unifiée utility_readings) ; seam de test des
 * contrôleurs (MeterEntryController, ReadingsController) sans base de données.
 */
interface MeterReadingRepositoryInterface
{
    public function save(DateTimeImmutable $readingAt, float $counterM3): void;

    /** @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> */
    public function getAllReadings(): array;

    /** @return array<string, mixed>|null */
    public function getLatest(): ?array;

    /**
     * Dernier relevé à ou avant $ts (borne inclusive : permet au contrôleur de
     * détecter un doublon d'horodatage). null si aucun.
     *
     * @return array<string, mixed>|null
     */
    public function getReadingBefore(DateTimeImmutable $ts): ?array;

    /**
     * Premier relevé à ou après $ts (borne inclusive). null si aucun.
     *
     * @return array<string, mixed>|null
     */
    public function getReadingAfter(DateTimeImmutable $ts): ?array;

    /**
     * Fenêtre de relevés nécessaire à l'interpolation à minuit d'un mois (le
     * dernier relevé avant le mois, ceux du mois, le premier après le mois),
     * triés par horodatage croissant.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForInterpolation(int $year, int $month): array;
}
