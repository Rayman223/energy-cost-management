<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\TariffGrid;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository tarifaire : renvoie une grille fixe (ou null), sans base.
 * Mémorise le dernier saveGrid pour vérification dans les tests.
 */
final class FakeTariffRepository implements TariffRepositoryInterface
{
    /** @var TariffGrid[] */
    public array $allGrids = [];

    public int $nextId = 42;

    /** @var array<string, mixed>|null Arguments du dernier saveGrid. */
    public ?array $savedGrid = null;

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

    /** @return TariffGrid[] */
    public function findAll(string $energyType): array
    {
        return $this->allGrids;
    }

    /** @param list<array{key: string, amount: float, kind: string, label: ?string}> $lines */
    public function saveGrid(
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
        ?string $country = null,
        string $currency = 'EUR',
        bool $shared = false,
        float $vatRate = 21.0,
    ): int {
        $this->savedGrid = [
            'energy_type' => $energyType,
            'name'        => $name,
            'valid_from'  => $validFrom,
            'valid_to'    => $validTo,
            'lines'       => $lines,
            'pcs'         => $pcsCoefficient,
            'country'     => $country,
            'currency'    => $currency,
            'shared'      => $shared,
            'vat_rate'    => $vatRate,
        ];

        return $this->nextId;
    }
}
