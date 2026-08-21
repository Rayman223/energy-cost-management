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
        $s = $this->schedule(validFrom: '2026-02-01', validTo: '2026-05-01', dueDay: 10);

        self::assertSame(
            ['2026-02-10', '2026-03-10', '2026-04-10'],
            $this->dates($s, '2026-01-01', '2026-12-31'),
        );
    }

    /**
     * La borne `valid_to` du barème est EXCLUE au même titre que celle de la
     * fenêtre (#1) : un barème s'arrêtant le jour même du prélèvement ne le compte
     * pas. C'est ce qui permet à deux barèmes successifs de partager cette date
     * sans qu'aucun mois ne soit facturé deux fois.
     */
    public function testScheduleEndBoundExcludesADueDateFallingOnIt(): void
    {
        $endsOnTheDueDay = $this->schedule(validFrom: '2026-01-01', validTo: '2026-03-10', dueDay: 10);

        self::assertSame(
            ['2026-01-10', '2026-02-10'],
            $this->dates($endsOnTheDueDay, '2026-01-01', '2026-12-31'),
        );
    }

    /**
     * Deux barèmes qui se RECOLLENT (révision du montant en cours d'année) : le
     * mois de bascule revient au nouveau barème et à lui seul. Avec une borne
     * incluse, le 01/07 aurait été prélevé par les deux.
     */
    public function testConsecutiveSchedulesShareTheirBoundWithoutDoubleCounting(): void
    {
        $before = $this->schedule(validFrom: '2026-01-01', validTo: '2026-07-01', dueDay: 1);
        $after  = $this->schedule(validFrom: '2026-07-01', validTo: null, dueDay: 1);

        self::assertFalse($before->overlaps($after->validFrom, $after->validTo));

        $window = ['2026-01-01', '2027-01-01'];
        self::assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'],
            $this->dates($before, ...$window),
        );
        self::assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'],
            $this->dates($after, ...$window),
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
        $s = $this->schedule(validFrom: '2024-01-01', validTo: '2025-01-01');

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
        $s = $this->schedule(validFrom: '2026-01-01', validTo: '2026-07-01');

        self::assertTrue($s->overlaps($this->at('2026-06-01'), $this->at('2027-01-01')));
    }

    /**
     * Bornes semi-ouvertes (#1) : la plage suivante démarre le jour même où
     * celle-ci s'arrête, et les deux ne se chevauchent pas pour autant.
     */
    public function testNoOverlapOnConsecutiveRanges(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: '2026-07-01');

        self::assertFalse($s->overlaps($this->at('2026-07-01'), $this->at('2027-01-01')));
    }

    public function testOpenEndedScheduleOverlapsAnyLaterRange(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: null);

        self::assertTrue($s->overlaps($this->at('2030-01-01'), null));
    }

    public function testOpenEndedScheduleDoesNotOverlapAnEarlierClosedRange(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: null);

        self::assertFalse($s->overlaps($this->at('2025-01-01'), $this->at('2026-01-01')));
    }

    /**
     * Marquage du barème en cours sur /advances (#252). Début inclus, fin EXCLUE
     * (#1) : `valid_to` est le premier jour NON couvert, donc un barème affiché
     * comme encore en cours ce jour-là serait faux — c'est déjà celui d'après.
     */
    public function testIsActiveOnIncludesTheStartAndExcludesTheEnd(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: '2027-01-01');

        self::assertTrue($s->isActiveOn($this->at('2026-01-01')));
        self::assertTrue($s->isActiveOn($this->at('2026-06-15')));
        self::assertTrue($s->isActiveOn($this->at('2026-12-31')));
        self::assertFalse($s->isActiveOn($this->at('2025-12-31')));
        self::assertFalse($s->isActiveOn($this->at('2027-01-01')));
    }

    public function testOpenEndedScheduleStaysActiveIndefinitely(): void
    {
        $s = $this->schedule(validFrom: '2026-01-01', validTo: null);

        self::assertTrue($s->isActiveOn($this->at('2026-01-01')));
        self::assertTrue($s->isActiveOn($this->at('2099-12-31')));
        self::assertFalse($s->isActiveOn($this->at('2025-12-31')));
    }

    /** L'heure du jour ne doit pas décider : seule la date civile compte. */
    public function testIsActiveOnIgnoresTimeOfDay(): void
    {
        $s   = $this->schedule(validFrom: '2026-01-01', validTo: '2027-01-01');
        $end = new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('UTC'));

        self::assertTrue($s->isActiveOn($end));
    }

    /**
     * « Pas actif » ne veut pas dire « échu » : un barème à venir n'est ni l'un ni
     * l'autre, et ne doit pas être affiché en retrait comme un contrat terminé.
     */
    public function testIsExpiredOnSeparatesPastFromFutureSchedules(): void
    {
        $past   = $this->schedule(validFrom: '2025-01-01', validTo: '2026-01-01');
        $future = $this->schedule(validFrom: '2027-01-01', validTo: '2028-01-01');
        $open   = $this->schedule(validFrom: '2020-01-01', validTo: null);
        $today  = $this->at('2026-06-15');

        self::assertTrue($past->isExpiredOn($today));
        self::assertFalse($future->isExpiredOn($today));
        self::assertFalse($open->isExpiredOn($today));

        // `valid_to` étant le premier jour non couvert (#1), le barème est échu dès
        // ce jour-là, et encore en cours la veille.
        self::assertTrue($past->isExpiredOn($this->at('2026-01-01')));
        self::assertFalse($past->isExpiredOn($this->at('2025-12-31')));
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
