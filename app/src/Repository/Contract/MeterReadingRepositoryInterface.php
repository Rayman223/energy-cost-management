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

    /** Nombre total de relevés du fluide (pagination, #257). */
    public function countReadings(): int;

    /**
     * Une page de relevés, du plus récent au plus ancien, même forme que
     * getAllReadings(). Le delta de la dernière ligne tient compte du relevé
     * immédiatement plus ancien, hors page (#257).
     *
     * @return list<array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    public function getReadingsPage(int $perPage, int $offset): array;

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

    /**
     * Même fenêtre d'interpolation sur des bornes quelconques (#241) : dernier
     * relevé avant $from, relevés de [$from, $to[, premier relevé à/après $to.
     *
     * @param string $from Borne de début incluse, format DB 'Y-m-d H:i:s' (UTC).
     * @param string $to   Borne de fin exclue, même format.
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForRange(string $from, string $to): array;
}
