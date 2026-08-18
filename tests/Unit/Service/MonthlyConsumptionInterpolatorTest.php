<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\MonthlyConsumptionInterpolator;
use DateTimeImmutable;
use DateTimeZone;
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

    // ── monthlySeries() : graphique mensuel (#238) ────────────────────────────

    private function ts(string $moment): int
    {
        return (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->getTimestamp();
    }

    public function testMonthlySeriesSpreadsOneReadingOverTheMonthsItCovers(): void
    {
        // Cas de l'issue : deux relevés seulement (1er janv. et 12 juin), qui
        // donnaient deux barres. La conso doit être ventilée sur chaque mois.
        $series = $this->interp->monthlySeries(
            $this->readings([['2026-01-01 00:00:00', 100.0], ['2026-06-12 00:00:00', 262.0]]),
            6,
            $this->ts('2026-06-20 10:00:00'),
        );

        self::assertCount(6, $series);
        self::assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'],
            array_column($series, 'month'),
        );

        // 162 m³ sur 162 jours = 1 m³/jour → chaque mois vaut son nombre de jours.
        self::assertEqualsWithDelta(31.0, $series[0]['delta_m3'], 0.001); // janvier
        self::assertEqualsWithDelta(28.0, $series[1]['delta_m3'], 0.001); // février
        self::assertEqualsWithDelta(30.0, $series[3]['delta_m3'], 0.001); // avril

        // Juin : borne de fin clampée sur le dernier relevé (11 jours), pas de projection.
        self::assertEqualsWithDelta(11.0, $series[5]['delta_m3'], 0.001);
        self::assertTrue($series[5]['partial']);
        self::assertFalse($series[0]['partial']);
    }

    public function testMonthlySeriesMergesSeveralReadingsOfTheSameMonth(): void
    {
        // Trois relevés dans le même mois → UNE seule barre, somme des deltas.
        $series = $this->interp->monthlySeries(
            $this->readings([
                ['2026-03-01 00:00:00', 0.0],
                ['2026-03-10 00:00:00', 12.0],
                ['2026-03-20 00:00:00', 20.0],
                ['2026-04-01 00:00:00', 33.0],
            ]),
            1,
            $this->ts('2026-03-25 12:00:00'),
        );

        self::assertCount(1, $series);
        self::assertSame('2026-03', $series[0]['month']);
        self::assertEqualsWithDelta(33.0, $series[0]['delta_m3'], 0.001);
    }

    public function testMonthlySeriesSkipsMonthsBeforeTheFirstReading(): void
    {
        // Fenêtre de 12 mois mais relevés depuis novembre seulement : aucune barre
        // inventée avant le premier relevé.
        $series = $this->interp->monthlySeries(
            $this->readings([['2025-11-15 00:00:00', 0.0], ['2026-01-15 00:00:00', 61.0]]),
            12,
            $this->ts('2026-01-20 08:00:00'),
        );

        self::assertSame(['2025-11', '2025-12', '2026-01'], array_column($series, 'month'));
        // Novembre part du relevé du 15 (mois incomplet côté données) → partiel.
        self::assertTrue($series[0]['partial']);
        self::assertFalse($series[1]['partial']);
        self::assertEqualsWithDelta(31.0, $series[1]['delta_m3'], 0.001); // décembre, 1 m³/jour
    }

    public function testMonthlySeriesCurrentMonthIsNotProjected(): void
    {
        // 10 m³/jour, dernier relevé le 10 du mois : la barre du mois en cours
        // vaut la conso réelle (100) et non la projection à 30 jours (300).
        $series = $this->interp->monthlySeries(
            $this->readings([['2026-06-01 00:00:00', 0.0], ['2026-06-11 00:00:00', 100.0]]),
            1,
            $this->ts('2026-06-25 18:00:00'),
        );

        self::assertCount(1, $series);
        self::assertEqualsWithDelta(100.0, $series[0]['delta_m3'], 0.001);
        self::assertTrue($series[0]['partial']);
    }

    public function testMonthlySeriesReturnsEmptyBelowTwoReadings(): void
    {
        self::assertSame([], $this->interp->monthlySeries([], 12, $this->ts('2026-06-01 00:00:00')));
        self::assertSame(
            [],
            $this->interp->monthlySeries(
                $this->readings([['2026-05-01 00:00:00', 10.0]]),
                12,
                $this->ts('2026-06-01 00:00:00'),
            ),
        );
    }

    public function testMonthlySeriesHandlesYearRollover(): void
    {
        $series = $this->interp->monthlySeries(
            $this->readings([['2025-12-01 00:00:00', 0.0], ['2026-02-01 00:00:00', 62.0]]),
            3,
            $this->ts('2026-02-01 00:00:00'),
        );

        self::assertSame(['2025-12', '2026-01'], array_column($series, 'month'));
        self::assertEqualsWithDelta(31.0, $series[0]['delta_m3'], 0.001);
        self::assertEqualsWithDelta(31.0, $series[1]['delta_m3'], 0.001);
        // Le dernier relevé tombe pile au 1er février : février n'a rien à montrer.
        self::assertFalse($series[0]['partial']);
    }

    // ── interpolateRange : bornes quelconques (#241) ────────────────────────────

    /**
     * Garde-fou du refactoring : `interpolateMonth()` n'est plus qu'un appel à
     * `interpolateRange()` sur les bornes du mois. Les deux doivent donc rendre
     * exactement le même résultat, champ par champ.
     */
    public function testMonthIsARangeBetweenItsOwnBoundaries(): void
    {
        $readings = $this->readings([
            ['2026-03-28 07:54:00', 900.0],
            ['2026-04-14 08:10:00', 1150.0],
            ['2026-05-03 07:30:00', 1420.0],
        ]);

        $byMonth = $this->interp->interpolateMonth($readings, 2026, 4);
        $byRange = $this->interp->interpolateRange(
            $readings,
            new DateTimeImmutable('2026-04-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertEquals($byMonth, $byRange);
    }

    public function testRangeOnArbitraryDatesInterpolatesBothBounds(): void
    {
        // 10 unités/jour entre les deux relevés : du 06/06 au 21/06 → 15 jours × 10.
        $r = $this->interp->interpolateRange(
            $this->readings([['2026-06-01 00:00:00', 100.0], ['2026-07-01 00:00:00', 400.0]]),
            new DateTimeImmutable('2026-06-06 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-21 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertTrue($r->available);
        self::assertEqualsWithDelta(150.0, $r->monthlyDelta, 0.001);
        self::assertSame(15, $r->days);
        self::assertSame('interpolated', $r->startKind);
        self::assertSame('interpolated', $r->endKind);
        self::assertFalse($r->isProjection);
    }

    /**
     * Une période d'acompte couvre plus d'un an : `days` doit porter la vraie
     * longueur de l'intervalle, sinon la proration des forfaits (abonnement
     * mensuel, redevance annuelle) compterait un seul mois.
     */
    public function testRangeSpanningMoreThanAYearReportsItsRealLength(): void
    {
        $r = $this->interp->interpolateRange(
            $this->readings([['2025-06-01 00:00:00', 0.0], ['2026-08-01 00:00:00', 4260.0]]),
            new DateTimeImmutable('2025-06-06 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertTrue($r->available);
        self::assertSame(390, $r->days);
        self::assertSame(390, $r->calendarDays);
    }

    public function testRangeWithEndBeforeStartIsUnavailable(): void
    {
        $r = $this->interp->interpolateRange(
            $this->readings([['2026-06-01 00:00:00', 100.0], ['2026-07-01 00:00:00', 400.0]]),
            new DateTimeImmutable('2026-06-21 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-06 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertFalse($r->available);
    }

    public function testRangeBeyondLastReadingIsFlaggedAsProjection(): void
    {
        $r = $this->interp->interpolateRange(
            $this->readings([['2026-06-01 00:00:00', 100.0], ['2026-06-16 00:00:00', 250.0]]),
            new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertTrue($r->available);
        self::assertTrue($r->isProjection);
        self::assertSame('extrapolated', $r->endKind);
    }

    /**
     * Les bornes doivent être exposées en epoch UTC, et non redérivées des chaînes
     * `monthStart`/`monthEnd` : celles-ci sont formatées sans fuseau alors qu'elles
     * ont été construites en UTC, donc les re-parser décale l'instant du fuseau
     * applicatif. C'est ce que consomme `volumesPerSegment()` (#255).
     */
    public function testRangeExposesItsBoundsAsUtcTimestamps(): void
    {
        $readings = $this->readings([['2026-01-01 00:00:00', 0.0], ['2026-07-01 00:00:00', 600.0]]);

        $range = $this->interp->interpolateRange(
            $readings,
            new DateTimeImmutable('2026-03-05 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-04-11 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(
            (new DateTimeImmutable('2026-03-05 00:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            $range->startTs,
        );
        self::assertSame(
            (new DateTimeImmutable('2026-04-11 00:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            $range->endTs,
        );

        // Un mois calendaire complet : l'écart des bornes vaut exactement ses jours.
        $month = $this->interp->interpolateMonth($readings, 2026, 3);
        self::assertSame(31 * 86400, $month->endTs - $month->startTs);
        self::assertSame($month->days * 86400, $month->endTs - $month->startTs);
    }

    /**
     * Découper la période en sous-périodes contiguës et sommer leurs deltas redonne
     * le delta global : c'est ce qui rend sûre la répartition du volume par segment
     * tarifaire (#255), interpolation linéaire par morceaux oblige.
     */
    public function testSegmentBoundsTelescopeToTheWholeRange(): void
    {
        // Relevés irréguliers : les sous-bornes tombent en plein milieu des segments.
        $readings = $this->readings([
            ['2026-01-01 00:00:00', 0.0],
            ['2026-02-17 00:00:00', 520.0],
            ['2026-05-03 00:00:00', 780.0],
            ['2026-07-01 00:00:00', 900.0],
        ]);

        $start = (new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $end   = (new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')))->getTimestamp();

        // Partition arbitraire, non alignée sur les relevés.
        $cuts = [$start];
        foreach (['2026-02-01', '2026-04-01', '2026-06-14'] as $day) {
            $cuts[] = (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        }
        $cuts[] = $end;

        $sum = 0.0;
        for ($i = 0, $n = count($cuts) - 1; $i < $n; $i++) {
            $a = $this->interp->interpolateValueAt($readings, $cuts[$i]);
            $b = $this->interp->interpolateValueAt($readings, $cuts[$i + 1]);
            self::assertNotNull($a);
            self::assertNotNull($b);
            $sum += $b - $a;
        }

        $indexEnd   = $this->interp->interpolateValueAt($readings, $end);
        $indexStart = $this->interp->interpolateValueAt($readings, $start);
        self::assertNotNull($indexEnd);
        self::assertNotNull($indexStart);

        self::assertEqualsWithDelta($indexEnd - $indexStart, $sum, 0.000001);
        self::assertEqualsWithDelta(900.0, $sum, 0.000001);
    }
}
