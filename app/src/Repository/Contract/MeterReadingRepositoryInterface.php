<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Relevés de compteur manuels (gaz / eau) : lecture et écriture. Implémenté à
 * l'identique par GasRepository et WaterRepository ; seam de test des contrôleurs
 * (MeterEntryController, ReadingsController) sans base de données.
 */
interface MeterReadingRepositoryInterface
{
    public function save(DateTimeImmutable $readingAt, float $counterM3): void;

    /** @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> */
    public function getAllReadings(): array;

    /** @return array<string, mixed>|null */
    public function getLatest(): ?array;
}
