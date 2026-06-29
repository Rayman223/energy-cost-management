<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\MonthlyConsumptionInterpolator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MonthlyConsumptionInterpolatorTest extends TestCase
{
    private MonthlyConsumptionInterpolator $interp;

    protected function setUp(): void
    {
        $this->interp = new MonthlyConsumptionInterpolator();
    }

    /**
     * @param array<int, array{0:string,1:float}> $pairs
     * @return list<array{ts:int,value:float}>
     */
    private function readings(array $pairs): array
    {
        return array_map(
            static fn (array $p): array => [
                'ts'    => (new DateTimeImmutable($p[0]))->getTimestamp(),
                'value' => (float) $p[1],
            ],
            $pairs,
        );
    }

    public function testFullMonthAlignedOnBoundaries(): void
    {
        // Relevés pile aux bornes d'avril → bornes exactes, mois plein.
        $r = $this->interp->interpolateMonth(
            $this->readings([['2026-04-01 00:00:00', 1000.0], ['2026-05-01 00:00:00', 1300.0]]),
            2026,
            4,
        );

        self::assertTrue($r->available);
        self::assertSame('exact', $r->startKind);
        self::assertSame('exact', $r->endKind);
        self::assertFalse($r->isProjection);
        self::assertSame(30, $r->days);
        self::assertSame(30, $r->calendarDays);
        self::assertEqualsWithDelta(300.0, $r->monthlyDelta, 0.0001);
        self::assertSame('2026-04-01 00:00:00', $r->monthStart);
        self::assertSame('2026-05-01 00:00:00', $r->monthEnd);
    }

    public function testIssueExampleBackwardExtrapolationToMidnight(): void
    {
        // Exemple chiffré de l'issue #34 : relevés à 07:54 / 08:05, mais on veut
        // la conso de janvier minuit→minuit. Extrapolation arrière à minuit.
        $r = $this->interp->interpolateMonth(
            $this->readings([['2026-01-01 07:54:00', 74000.0], ['2026-02-01 08:05:00', 75000.0]]),
            2026,
            1,
        );

        self::assertTrue($r->available);
        self::assertEqualsWithDelta(999.754, $r->monthlyDelta, 0.001);
        self::assertSame('extrapolated', $r->startKind);   // minuit 1er janv. avant le 1er relevé
        self::assertSame('interpolated', $r->endKind);     // minuit 1er févr. encadré
        self::assertFalse($r->isProjection);
    }

    public function testReadingExactlyAtMidnightTakenAsIs(): void
    {
        // Un relevé tombe pile à minuit aux deux bornes → valeurs prises telles quelles.
        $r = $this->interp->interpolateMonth(
            $this->readings([
                ['2026-03-20 09:00:00', 50.0],
                ['2026-04-01 00:00:00', 100.0],
                ['2026-05-01 00:00:00', 200.0],
                ['2026-05-08 11:00:00', 240.0],
            ]),
            2026,
            4,
        );

        self::assertTrue($r->available);
        self::assertSame('exact', $r->startKind);
        self::assertSame('exact', $r->endKind);
        self::assertEqualsWithDelta(100.0, $r->monthlyDelta, 0.0001);
    }

    public function testIntermediateReadingsActAsAnchors(): void
    {
        // Pentes différentes de part et d'autre → les relevés intermédiaires
        // changent le résultat (500 avec ancrages vs ~420 avec une pente unique).
        $r = $this->interp->interpolateMonth(
            $this->readings([
                ['2026-03-22 00:00:00', 0.0],
                ['2026-04-11 00:00:00', 200.0],   // Apr-01 interpolé = 100
                ['2026-04-21 00:00:00', 500.0],
                ['2026-05-11 00:00:00', 700.0],   // May-01 interpolé = 600
            ]),
            2026,
            4,
        );

        self::assertTrue($r->available);
        self::assertEqualsWithDelta(500.0, $r->monthlyDelta, 0.0001);
        self::assertSame('interpolated', $r->startKind);
        self::assertSame('interpolated', $r->endKind);
        self::assertFalse($r->isProjection);
    }

    public function testCurrentMonthForwardExtrapolationProjects(): void
    {
        // Mois en cours : aucun relevé après la fin du mois, mais ≥2 relevés →
        // extrapolation avant jusqu'à fin de mois (projection).
        $r = $this->interp->interpolateMonth(
            $this->readings([['2026-06-01 00:00:00', 1000.0], ['2026-06-15 00:00:00', 1700.0]]),
            2026,
            6,
        );

        self::assertTrue($r->available);
        self::assertSame('exact', $r->startKind);
        self::assertSame('extrapolated', $r->endKind);
        self::assertTrue($r->isProjection);
        self::assertEqualsWithDelta(1500.0, $r->monthlyDelta, 0.0001); // 50/jour × 30 jours
    }

    public function testCurrentMonthSingleReadingIsUnavailable(): void
    {
        $r = $this->interp->interpolateMonth(
            $this->readings([['2026-06-10 00:00:00', 1000.0]]),
            2026,
            6,
        );

        self::assertFalse($r->available);
        self::assertSame('Relevé manquant : le calcul se fera dès le prochain relevé.', $r->reason);
    }

    public function testNoReadingsIsUnavailable(): void
    {
        $r = $this->interp->interpolateMonth([], 2026, 6);

        self::assertFalse($r->available);
        self::assertSame('Aucun relevé disponible pour cette période.', $r->reason);
    }

    public function testHandlesDecemberBoundaryRollover(): void
    {
        $r = $this->interp->interpolateMonth(
            $this->readings([['2026-12-01 00:00:00', 500.0], ['2027-01-01 00:00:00', 800.0]]),
            2026,
            12,
        );

        self::assertTrue($r->available);
        self::assertSame(31, $r->days);
        self::assertSame('2027-01-01 00:00:00', $r->monthEnd);
        self::assertEqualsWithDelta(300.0, $r->monthlyDelta, 0.0001);
    }

    public function testInterpolateValueAtExactReadingReturnsItsValue(): void
    {
        $r = $this->readings([['2026-04-01 00:00:00', 100.0], ['2026-05-01 00:00:00', 200.0]]);

        self::assertEqualsWithDelta(
            100.0,
            (float) $this->interp->interpolateValueAt($r, (new DateTimeImmutable('2026-04-01 00:00:00'))->getTimestamp()),
            0.0001,
        );
    }

    public function testInterpolateValueAtReturnsNullWhenSlopeUnknowable(): void
    {
        $r = $this->readings([['2026-04-10 00:00:00', 100.0]]);

        // Un seul relevé : impossible d'extrapoler hors de son timestamp.
        self::assertNull(
            $this->interp->interpolateValueAt($r, (new DateTimeImmutable('2026-04-01 00:00:00'))->getTimestamp()),
        );
    }
}
