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

    /** @var list<TariffGrid> Grilles renvoyées par findActiveGridsBetween ; vide → repli sur $grid. */
    public array $gridsBetween = [];

    /**
     * Grilles parmi lesquelles findActiveGrid() choisit selon la DATE demandée
     * (première dont `isActiveOn()` répond vrai) ; vide → repli sur $grid, insensible
     * à la date. Permet de simuler une bascule fixe → dynamique dans l'historique.
     *
     * @var list<TariffGrid>
     */
    public array $activeGrids = [];

    /** Nombre d'appels à findActiveGrid() — sert à vérifier la mémoïsation des appelants. */
    public int $findActiveGridCalls = 0;

    public function findActiveGrid(string $energyType, ?DateTimeImmutable $on = null): ?TariffGrid
    {
        $this->findActiveGridCalls++;

        if ($this->activeGrids === []) {
            return $this->grid;
        }

        $on ??= new DateTimeImmutable('now');
        foreach ($this->activeGrids as $grid) {
            if ($grid->energyType === $energyType && $grid->isActiveOn($on)) {
                return $grid;
            }
        }

        return null;
    }

    /** @return list<TariffGrid> */
    public function findActiveGridsBetween(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($this->gridsBetween !== []) {
            return $this->gridsBetween;
        }

        return $this->grid !== null ? [$this->grid] : [];
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
        string $pricingMode = TariffGrid::PRICING_MODE_DEFAULT,
    ): int {
        $this->savedGrid = [
            'energy_type'  => $energyType,
            'name'         => $name,
            'valid_from'   => $validFrom,
            'valid_to'     => $validTo,
            'lines'        => $lines,
            'pcs'          => $pcsCoefficient,
            'country'      => $country,
            'currency'     => $currency,
            'shared'       => $shared,
            'vat_rate'     => $vatRate,
            'pricing_mode' => $pricingMode,
        ];

        return $this->nextId;
    }

    public function hasDynamicGrid(string $energyType = 'electricity'): bool
    {
        foreach ($this->allGrids !== [] ? $this->allGrids : array_filter([$this->grid]) as $g) {
            if ($g->energyType === $energyType && $g->isDynamic()) {
                return true;
            }
        }

        return false;
    }
}
