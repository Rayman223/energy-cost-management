<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\TariffGrid;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository tarifaire : renvoie une grille fixe (ou null), sans base.
 */
final class FakeTariffRepository implements TariffRepositoryInterface
{
    public function __construct(
        public ?TariffGrid $grid = null,
        public ?float $mostRecentPcs = null,
    ) {
    }

    public function findActiveGrid(string $energyType, ?DateTimeImmutable $on = null): ?TariffGrid
    {
        return $this->grid;
    }

    public function findMostRecentPcs(string $energyType, DateTimeImmutable $before): ?float
    {
        return $this->mostRecentPcs;
    }
}
