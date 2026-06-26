<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\GasMonthInterpolator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GasMonthInterpolatorTest extends TestCase
{
    private GasMonthInterpolator $interp;

    protected function setUp(): void
    {
        $this->interp = new GasMonthInterpolator();
    }

    private function d(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s);
    }

    public function testFullMonthAlignedOnBoundaries(): void
    {
        // Relevés exactement aux bornes d'avril → pas d'interpolation, mois plein.
        $r = $this->interp->interpolate($this->d('2026-04-01 00:00:00'), 1000.0, $this->d('2026-05-01 00:00:00'), 1300.0, 2026, 4);

        self::assertTrue($r->available);
        self::assertTrue($r->isFull);
        self::assertFalse($r->interpolated);
        self::assertSame(30, $r->days);          // avril = 30 jours
        self::assertSame(30, $r->calendarDays);
        self::assertEqualsWithDelta(300.0, $r->monthlyM3, 0.0001);
        self::assertSame('2026-04-01 00:00:00', $r->monthStart);
        self::assertSame('2026-05-01 00:00:00', $r->monthEnd);
    }

    public function testPartialCoverageUsesCoveredDays(): void
    {
        $r = $this->interp->interpolate($this->d('2026-04-01 00:00:00'), 1000.0, $this->d('2026-04-16 00:00:00'), 1150.0, 2026, 4);

        self::assertTrue($r->available);
        self::assertFalse($r->isFull);
        self::assertSame(15, $r->days);
        self::assertEqualsWithDelta(150.0, $r->monthlyM3, 0.0001);
    }

    public function testInterpolatesWhenReadingsBracketBeyondMonth(): void
    {
        // 15-mars → 15-mai, +1000 m³ linéaire. Part d'avril = 1000 × 30/61 ≈ 491.8.
        $r = $this->interp->interpolate($this->d('2026-03-15 00:00:00'), 1000.0, $this->d('2026-05-15 00:00:00'), 2000.0, 2026, 4);

        self::assertTrue($r->available);
        self::assertTrue($r->isFull);          // from ≤ 1er avril ET to ≥ 1er mai
        self::assertSame(30, $r->days);
        self::assertEqualsWithDelta(491.8, $r->monthlyM3, 2.0);
    }

    public function testRejectsIdenticalTimestamps(): void
    {
        $r = $this->interp->interpolate($this->d('2026-04-10 00:00:00'), 1000.0, $this->d('2026-04-10 00:00:00'), 1000.0, 2026, 4);

        self::assertFalse($r->available);
        self::assertSame('Les deux relevés ont le même horodatage.', $r->reason);
    }

    public function testRejectsWhenReadingsDoNotCoverMonth(): void
    {
        // Les deux relevés sont en mars → ne couvrent pas avril.
        $r = $this->interp->interpolate($this->d('2026-03-01 00:00:00'), 1000.0, $this->d('2026-03-20 00:00:00'), 1100.0, 2026, 4);

        self::assertFalse($r->available);
        self::assertSame('Les relevés ne couvrent pas cette période.', $r->reason);
    }

    public function testHandlesDecemberBoundaryRollover(): void
    {
        // Décembre → le mois suivant est janvier de l'année +1.
        $r = $this->interp->interpolate($this->d('2026-12-01 00:00:00'), 500.0, $this->d('2027-01-01 00:00:00'), 800.0, 2026, 12);

        self::assertTrue($r->available);
        self::assertTrue($r->isFull);
        self::assertSame(31, $r->days);
        self::assertSame('2027-01-01 00:00:00', $r->monthEnd);
        self::assertEqualsWithDelta(300.0, $r->monthlyM3, 0.0001);
    }
}
