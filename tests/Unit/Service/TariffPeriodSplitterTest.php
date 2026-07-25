<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\TariffPeriodSplitter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Découpage d'une période en sous-périodes tarifaires (#196) : bornes incluses,
 * priorité identique à findActiveGrid, et conservation du nombre de jours.
 */
final class TariffPeriodSplitterTest extends TestCase
{
    private function grid(
        int $id,
        string $name,
        string $validFrom,
        ?string $validTo = null,
        ?int $userId = null,
    ): TariffGrid {
        return new TariffGrid(
            id: $id,
            energyType: 'electricity',
            name: $name,
            validFrom: new DateTimeImmutable($validFrom),
            validTo: $validTo !== null ? new DateTimeImmutable($validTo) : null,
            lines: ['energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1)],
            userId: $userId,
        );
    }

    public function testSingleGridProducesOneSegmentCoveringThePeriod(): void
    {
        $grid = $this->grid(1, 'A', '2026-01-01');

        $segments = (new TariffPeriodSplitter())->split([$grid], new DateTimeImmutable('2026-06-01 08:30:00'), 30);

        $this->assertCount(1, $segments);
        $this->assertSame(30, $segments[0]->days);
        $this->assertSame('2026-06-01', $segments[0]->from->format('Y-m-d'));
        $this->assertSame('2026-06-30', $segments[0]->to->format('Y-m-d'));
        $this->assertSame($grid, $segments[0]->grid);
    }

    public function testSupplierSwitchMidMonthSplitsThePeriod(): void
    {
        // B démarre le 16 ; A est fermée au 15 (borne incluse).
        $b = $this->grid(2, 'B', '2026-01-16');
        $a = $this->grid(1, 'A', '2026-01-01', '2026-01-15');

        $segments = (new TariffPeriodSplitter())->split([$b, $a], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertCount(2, $segments);
        $this->assertSame('A', $segments[0]->grid->name);
        $this->assertSame(15, $segments[0]->days);
        $this->assertSame('2026-01-15', $segments[0]->to->format('Y-m-d'));
        $this->assertSame('B', $segments[1]->grid->name);
        $this->assertSame(16, $segments[1]->days);
        $this->assertSame('2026-01-16', $segments[1]->from->format('Y-m-d'));
        $this->assertSame(31, $segments[0]->days + $segments[1]->days);
    }

    public function testOverlappingDayIsBilledOnce(): void
    {
        // Chevauchement : A valide jusqu'au 15 inclus, B démarre le 15.
        // La liste est triée par priorité (valid_from décroissant) → B gagne le 15.
        $b = $this->grid(2, 'B', '2026-01-15');
        $a = $this->grid(1, 'A', '2026-01-01', '2026-01-15');

        $segments = (new TariffPeriodSplitter())->split([$b, $a], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertCount(2, $segments);
        $this->assertSame(14, $segments[0]->days);          // A : 1 → 14
        $this->assertSame('2026-01-14', $segments[0]->to->format('Y-m-d'));
        $this->assertSame('2026-01-15', $segments[1]->from->format('Y-m-d')); // B : 15 → 31
        $this->assertSame(31, $segments[0]->days + $segments[1]->days);
    }

    public function testPersonalGridWinsOverSharedCatalogue(): void
    {
        $personal = $this->grid(2, 'Perso', '2026-01-01', null, 7);
        $shared   = $this->grid(1, 'Catalogue', '2026-01-01');

        $segments = (new TariffPeriodSplitter())->split([$personal, $shared], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertCount(1, $segments);
        $this->assertSame('Perso', $segments[0]->grid->name);
    }

    public function testUncoveredDaysAreAttachedToTheNeighbouringSegment(): void
    {
        // Grille active seulement du 10 au 20 : les jours de tête et de queue
        // lui sont rattachés pour ne perdre aucune consommation.
        $grid = $this->grid(1, 'A', '2026-01-10', '2026-01-20');

        $segments = (new TariffPeriodSplitter())->split([$grid], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertCount(1, $segments);
        $this->assertSame(31, $segments[0]->days);
        $this->assertSame('A', $segments[0]->grid->name);
    }

    public function testReturnsNoSegmentWhenNoGridCoversThePeriod(): void
    {
        $grid = $this->grid(1, 'A', '2027-01-01');

        $segments = (new TariffPeriodSplitter())->split([$grid], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertSame([], $segments);
    }

    public function testReturnsNoSegmentWithoutGrids(): void
    {
        $this->assertSame([], (new TariffPeriodSplitter())->split([], new DateTimeImmutable('2026-01-01'), 31));
    }

    public function testFractionSplitsTheQuantitiesProRataDays(): void
    {
        $b = $this->grid(2, 'B', '2026-01-16');
        $a = $this->grid(1, 'A', '2026-01-01', '2026-01-15');

        $segments = (new TariffPeriodSplitter())->split([$b, $a], new DateTimeImmutable('2026-01-01'), 31);

        $this->assertEqualsWithDelta(15 / 31, $segments[0]->fraction(31), 1e-9);
        $this->assertEqualsWithDelta(16 / 31, $segments[1]->fraction(31), 1e-9);
        $this->assertEqualsWithDelta(1.0, $segments[0]->fraction(31) + $segments[1]->fraction(31), 1e-9);
    }
}
