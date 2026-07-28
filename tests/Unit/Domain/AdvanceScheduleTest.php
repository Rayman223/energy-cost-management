<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\AdvanceSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Le comptage des échéances décide à lui seul du « total payé » affiché sur la
 * page des acomptes : une échéance de trop ou de moins fausse le solde d'un mois
 * entier de prélèvement.
 */
final class AdvanceScheduleTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function schedule(
        float $amount = 120.0,
        string $validFrom = '2025-01-01',
        ?string $validTo = null,
        int $dueDay = 1,
    ): AdvanceSchedule {
        return new AdvanceSchedule(
            id: 1,
            energyType: 'electricity',
            amountMonthly: $amount,
            validFrom: $this->at($validFrom),
            validTo: $validTo !== null ? $this->at($validTo) : null,
            dueDay: $dueDay,
        );
    }

    /** @return list<string> */
    private function dates(AdvanceSchedule $s, string $from, string $to): array
    {
        return array_map(
            static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $s->dueDatesWithin($this->at($from), $this->at($to)),
        );
    }

    public function testCountsOneDueDatePerMonthWithinThePeriod(): void
    {
        $dates = $this->dates($this->schedule(dueDay: 5), '2026-01-01', '2026-03-31');

        self::assertSame(['2026-01-05', '2026-02-05', '2026-03-05'], $dates);
    }

    /**
     * Exemple de l'issue : du 06/06 au 01/07, prélèvement le 5. Le 5 juin est
     * ANTÉRIEUR au début de période — il n'a pas à être compté — et le 5 juillet
     * est postérieur à la fin. Une seule échéance ne tombe dans la fenêtre : aucune.
     */
    public function testDueDateBeforeThePeriodStartIsNotCounted(): void
    {
        self::assertSame([], $this->dates($this->schedule(dueDay: 5), '2026-06-06', '2026-07-01'));
    }

    /**
     * La fenêtre est `[from, to[`, comme celle du calcul de coût. Une fin incluse
     * compterait treize prélèvements face à douze mois de consommation sur une
     * période « aujourd'hui moins un an → aujourd'hui », et le solde annoncerait un
     * excédent d'un acompte entier qui n'existe pas.
     */
    public function testPeriodEndIsExclusive(): void
    {
        $dates = $this->dates($this->schedule(dueDay: 1), '2026-06-01', '2026-07-01');

        self::assertSame(['2026-06-01'], $dates);
    }

    /** Douze mois de fenêtre ⇒ douze prélèvements, jamais treize. */
    public function testAFullYearCountsTwelveInstalments(): void
    {
        self::assertCount(12, $this->dates($this->schedule(dueDay: 28), '2025-07-28', '2026-07-28'));
    }

    /**
     * Un prélèvement « le 31 » ne saute pas les mois courts : le fournisseur
     * débite le dernier jour du mois.
     */
    public function testDueDayBeyondMonthLengthFallsOnTheLastDay(): void
    {
        $dates = $this->dates($this->schedule(dueDay: 31), '2026-01-01', '2026-05-01');

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $dates);
    }

    public function testDueDayOnLeapFebruaryFallsOnThe29th(): void
    {
        $dates = $this->dates($this->schedule(dueDay: 31), '2028-02-01', '2028-03-01');

        self::assertSame(['2028-02-29'], $dates);
    }

    public function testValidityRangeClipsThePeriod(): void
    {
        $s = $this->schedule(validFrom: '2026-02-01', validTo: '2026-04-30', dueDay: 10);

        self::assertSame(
            ['2026-02-10', '2026-03-10', '2026-04-10'],
            $this->dates($s, '2026-01-01', '2026-12-31'),
        );
    }

    public function testOpenEndedScheduleRunsUntilThePeriodEnd(): void
    {
        $s = $this->schedule(validFrom: '2026-10-01', validTo: null, dueDay: 15);

        self::assertSame(
            ['2026-10-15', '2026-11-15', '2026-12-15'],
            $this->dates($s, '2026-01-01', '2026-12-31'),
        );
    }

    public function testScheduleOutsideThePeriodYieldsNothing(): void
    {
        $s = $this->schedule(validFrom: '2024-01-01', validTo: '2024-12-31');

        self::assertSame([], $this->dates($s, '2026-01-01', '2026-12-31'));
    }

    public function testTotalMultipliesTheMonthlyAmountByTheDueDateCount(): void
    {
        $s = $this->schedule(amount: 120.0, dueDay: 5);

        self::assertEqualsWithDelta(
            1560.0, // 13 échéances (juin 2025 → juin 2026)
            $s->totalWithin($this->at('2025-06-01'), $this->at('2026-06-30')),
            0.001,
        );
    }

    public function testPeriodSpanningAYearAndAHalfCountsEveryMonth(): void
    {
        $dates = $this->dates($this->schedule(dueDay: 1), '2025-06-01', '2026-07-01');

        self::assertCount(13, $dates);
        self::assertSame('2025-06-01', $dates[0]);
        self::assertSame('2026-06-01', $dates[12]);
    }

    // ── Chevauchement de barèmes ────────────────────────────────────────────

    public function testOverlapDetectedOnIntersectingRanges(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: '2026-06-30');

        self::assertTrue($s->overlaps($this->at('2026-06-01'), $this->at('2026-12-31')));
    }

    public function testNoOverlapOnConsecutiveRanges(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: '2026-06-30');

        self::assertFalse($s->overlaps($this->at('2026-07-01'), $this->at('2026-12-31')));
    }

    public function testOpenEndedScheduleOverlapsAnyLaterRange(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: null);

        self::assertTrue($s->overlaps($this->at('2030-01-01'), null));
    }

    public function testOpenEndedScheduleDoesNotOverlapAnEarlierClosedRange(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: null);

        self::assertFalse($s->overlaps($this->at('2025-01-01'), $this->at('2025-12-31')));
    }

    public function testFromRowParsesDatesAndNullableEnd(): void
    {
        $s = AdvanceSchedule::fromRow([
            'id'             => '7',
            'energy_type'    => 'gas',
            'amount_monthly' => '85.5000',
            'valid_from'     => '2026-03-01',
            'valid_to'       => null,
            'due_day'        => '12',
            'note'           => 'contrat 2026',
        ]);

        self::assertSame(7, $s->id);
        self::assertSame('gas', $s->energyType);
        self::assertEqualsWithDelta(85.5, $s->amountMonthly, 0.001);
        self::assertSame('2026-03-01', $s->validFrom->format('Y-m-d'));
        self::assertNull($s->validTo);
        self::assertSame(12, $s->dueDay);
    }
}
