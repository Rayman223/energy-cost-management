<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Domain\AdvanceSchedule;
use App\View\ViewFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Surlignage des barèmes d'acompte (#4). La liste marque « sur la période » les
 * barèmes qui participent au bilan affiché — et non ceux qui courent aujourd'hui,
 * qui n'entrent pour rien dans un bilan consulté sur une année passée.
 *
 * Le test porte sur le rendu et non sur `AdvanceSchedule` (déjà couvert par
 * {@see \Tests\Unit\Domain\AdvanceScheduleTest}) : c'est le CÂBLAGE qui portait le
 * bug, la vue recevant un jour là où il fallait une fenêtre. Rien à l'exécution ne
 * signalerait un retour au jour courant, d'où l'assertion sur les classes CSS.
 */
final class AdvancesHighlightTest extends TestCase
{
    /** Période consultée : une année passée, qui ne contient PAS le jour courant. */
    private const PERIOD_FROM = '2025-01-01';
    private const PERIOD_TO   = '2026-01-01';

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function schedule(
        int $id,
        string $note,
        string $validFrom,
        ?string $validTo = null,
    ): AdvanceSchedule {
        return new AdvanceSchedule(
            id: $id,
            energyType: 'electricity',
            amountMonthly: 120.0,
            validFrom: $this->at($validFrom),
            validTo: $validTo !== null ? $this->at($validTo) : null,
            dueDay: 5,
            note: $note,
        );
    }

    /**
     * @param list<AdvanceSchedule> $schedules
     */
    private function render(
        array $schedules,
        string $highlightFrom = self::PERIOD_FROM,
        string $highlightTo = self::PERIOD_TO,
    ): string {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        // `balance` à null laisse le tableau du bilan hors du rendu : les seules
        // lignes `<tr class="…">` de la page sont alors celles des barèmes.
        return $view->render('advances', [
            'oidcEnabled'   => false,
            'discordUrl'    => null,
            'adsenseClient' => null,
            'error'         => null,
            'success'       => null,
            'isAdmin'       => false,
            'schedules'     => $schedules,
            'editing'       => null,
            'balance'       => null,
            'periodError'   => null,
            'futureClamped' => false,
            'periodFrom'    => $highlightFrom,
            'periodTo'      => $highlightTo,
            'highlightFrom' => $this->at($highlightFrom),
            'highlightTo'   => $this->at($highlightTo),
            'energyTypes'   => AdvanceSchedule::ENERGY_TYPES,
            'maxAmount'     => AdvanceSchedule::MAX_AMOUNT,
            'currency'      => 'EUR',
            'available'     => ['fr', 'en', 'nl', 'de'],
            'timezone'      => 'Europe/Brussels',
        ]);
    }

    /** Fragment `<tr …>…</tr>` de la ligne portant cette annotation. */
    private function rowOf(string $html, string $note): string
    {
        self::assertSame(
            1,
            preg_match('#<tr class="[^"]*">(?:(?!</tr>).)*?' . preg_quote($note, '#') . '.*?</tr>#s', $html, $m),
            "Ligne introuvable pour le barème « $note »",
        );

        return $m[0];
    }

    public function testScheduleOverlappingThePeriodIsHighlighted(): void
    {
        $html = $this->render([$this->schedule(1, 'pendant', '2025-03-01', '2025-09-01')]);
        $row  = $this->rowOf($html, 'pendant');

        self::assertStringContainsString('is-current', $row);
        self::assertStringNotContainsString('is-expired', $row);
        self::assertStringContainsString('Sur la période', $row);
    }

    public function testPartialOverlapStillCounts(): void
    {
        // Le barème ne couvre que le dernier trimestre de la période : il pèse tout
        // de même dans le montant payé, donc il est marqué.
        $html = $this->render([$this->schedule(1, 'chevauchant', '2025-10-01')]);

        self::assertStringContainsString('is-current', $this->rowOf($html, 'chevauchant'));
    }

    public function testScheduleClosedBeforeThePeriodIsDimmed(): void
    {
        $html = $this->render([$this->schedule(1, 'avant', '2024-01-01', '2025-01-01')]);
        $row  = $this->rowOf($html, 'avant');

        self::assertStringContainsString('is-expired', $row);
        self::assertStringNotContainsString('is-current', $row);
        self::assertStringNotContainsString('Sur la période', $row);
    }

    public function testScheduleStartingAfterThePeriodStaysNeutral(): void
    {
        // Ni actif ni échu : il n'a pas commencé.
        $html = $this->render([$this->schedule(1, 'apres', '2026-06-01')]);
        $row  = $this->rowOf($html, 'apres');

        self::assertStringNotContainsString('is-current', $row);
        self::assertStringNotContainsString('is-expired', $row);
    }

    public function testTodaysScheduleIsNotHighlightedOnAPastPeriod(): void
    {
        // La régression de l'issue : un barème ouvert qui court aujourd'hui mais
        // démarre après la fin de la période consultée n'a rien à y faire.
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $html  = $this->render([
            $this->schedule(1, 'ancien', '2025-01-01', '2026-01-01'),
            $this->schedule(2, 'courant', $today->modify('-1 month')->format('Y-m-d')),
        ]);

        self::assertStringContainsString('is-current', $this->rowOf($html, 'ancien'));
        self::assertStringNotContainsString('is-current', $this->rowOf($html, 'courant'));
    }

    public function testFallbackWindowMarksTheScheduleRunningToday(): void
    {
        // Repli de la route quand la période est refusée : `[aujourd'hui, demain[`
        // doit redonner le comportement d'origine, « actif aujourd'hui ».
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $html  = $this->render(
            [
                $this->schedule(1, 'ancien', '2025-01-01', '2026-01-01'),
                $this->schedule(2, 'courant', $today->modify('-1 month')->format('Y-m-d')),
            ],
            $today->format('Y-m-d'),
            $today->modify('+1 day')->format('Y-m-d'),
        );

        self::assertStringContainsString('is-current', $this->rowOf($html, 'courant'));
        self::assertStringNotContainsString('is-current', $this->rowOf($html, 'ancien'));
    }
}
