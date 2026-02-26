<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\EnergyRepository;
use DateTimeImmutable;

final class GasManualEntryService
{
    public function __construct(private readonly EnergyRepository $repository)
    {
    }

    public function addManualCounterValue(DateTimeImmutable $timestamp, float $counterM3): void
    {
        $this->repository->saveGasManualReading($timestamp, $counterM3);
    }
}
