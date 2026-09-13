<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\FleetCoverageWindow;
use PHPUnit\Framework\TestCase;

/**
 * Fenêtre couverte par un parc de compteurs (#76).
 *
 * Deux exigences se tiennent ensemble, et c'est leur tension qui fait tout
 * l'intérêt de ces cas : un compteur qu'on a CESSÉ DE RELEVER doit continuer à
 * dégrader la couverture — sinon un coût partiel s'annonce complet — alors qu'un
 * compteur FERMÉ ne le doit plus, puisque plus rien n'est attendu de lui. Avant
 * ce correctif les deux étaient traités pareil, et remplacer son compteur figeait
 * la couverture de tous les rapports suivants, indéfiniment.
 *
 * Scénario de référence, décliné ci-dessous : le compteur A est fermé le
 * 15/06/2026, le compteur B lui succède.
 */
final class FleetCoverageWindowTest extends TestCase
{
    private const JUNE  = '2026-06-01 00:00:00';
    private const JULY  = '2026-07-01 00:00:00';

    /**
     * @param string|null $closedAt
     * @return array{end: string, closed_at: string|null}
     */
    private static function meter(string $end, ?string $closedAt = null): array
    {
        return ['end' => $end, 'closed_at' => $closedAt];
    }

    // ── L'intersection, inchangée ────────────────────────────────────────────

    /**
     * Sans aucune fermeture, la règle rend exactement l'intersection d'avant : la
     * fin la plus précoce du parc. Un compteur qui a cessé d'être relevé le 20
     * borne la couverture au 20, quoi que fassent ses voisins.
     */
    public function testWithoutAnyClosureTheEarliestEndStillWins(): void
    {
        $fleet = [
            self::meter('2026-06-30 00:00:00'),
            self::meter('2026-06-20 00:00:00'),
        ];

        self::assertSame('2026-06-20 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /** À un seul compteur ouvert, la couverture est sa propre fin. */
    public function testASingleOpenMeterCoversUntilItsLastReading(): void
    {
        $fleet = [self::meter('2026-06-30 00:00:00')];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /** Aucun compteur ne porte de donnée : il n'y a pas de fenêtre à décrire. */
    public function testAnEmptyFleetHasNoCoverage(): void
    {
        self::assertNull(FleetCoverageWindow::coveredUntil([], self::JUNE));
    }

    // ── Le bug : la fermeture ne doit plus rien figer ────────────────────────

    /**
     * LE cas de l'issue. En juillet, le compteur A est fermé depuis le 15 juin :
     * il n'avait rien à couvrir de ce mois-là, sa dernière date n'apprend donc
     * rien sur lui. Seul B compte. Avant le correctif, `data_to` restait collé au
     * 14 juin et le mois — puis tous les suivants — était annoncé partiel.
     */
    public function testAMeterClosedBeforeThePeriodDoesNotLimitItAtAll(): void
    {
        $fleet = [
            self::meter('2026-06-14 00:00:00', '2026-06-15 00:00:00'),
            self::meter('2026-07-31 00:00:00'),
        ];

        self::assertSame('2026-07-31 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JULY));
    }

    /**
     * Mois du remplacement, relais propre : A est relevé jusqu'à la veille de sa
     * fermeture, B prend la suite. Le parc n'a pas eu de trou, la couverture va
     * jusqu'au bout.
     *
     * La tolérance d'un jour est ce qui rend ce cas possible : la fermeture étant
     * une borne EXCLUE, A ne PEUT pas porter de relevé daté du 15.
     */
    public function testAClosedMeterHandedOverMidPeriodCoversTheWholeWindow(): void
    {
        $fleet = [
            self::meter('2026-06-14 00:00:00', '2026-06-15 00:00:00'),
            self::meter('2026-06-30 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    // ── Ce que la fermeture ne doit PAS blanchir ─────────────────────────────

    /**
     * A a cessé d'être relevé le 1er juin, bien avant sa fermeture du 15 : il y a
     * un vrai trou de deux semaines, que le successeur ne comble pas. La
     * couverture s'arrête au dernier relevé, comme pour n'importe quel flux
     * interrompu — la fermeture ne rétroagit pas sur les jours où le compteur
     * était encore en service.
     */
    public function testAMeterThatStoppedBeingReadBeforeItsClosureStillDegradesCoverage(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-15 00:00:00'),
            self::meter('2026-06-30 00:00:00'),
        ];

        self::assertSame('2026-06-01 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /**
     * Fermeture sans successeur : plus aucun relevé n'est attendu, mais plus aucun
     * n'arrive non plus. La période n'est pas couverte au-delà et doit le dire —
     * un foyer qui ferme son dernier compteur ne doit pas voir ses coûts partiels
     * annoncés complets.
     */
    public function testAClosureWithoutSuccessorStopsTheCoverage(): void
    {
        $fleet = [self::meter('2026-06-14 00:00:00', '2026-06-15 00:00:00')];

        self::assertSame('2026-06-14 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /**
     * Parc entièrement retiré avant la période demandée : on retombe sur
     * l'intersection nue plutôt que de rendre la période couverte. Le rapport ne
     * porte que sur des relevés antérieurs aux fermetures.
     */
    public function testAFleetEntirelyClosedBeforeThePeriodFallsBackOnTheEarliestEnd(): void
    {
        $fleet = [
            self::meter('2026-05-20 00:00:00', '2026-05-21 00:00:00'),
            self::meter('2026-05-30 00:00:00', '2026-05-31 00:00:00'),
        ];

        self::assertSame('2026-05-20 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    // ── Plusieurs époques ────────────────────────────────────────────────────

    /**
     * Deux remplacements dans le même mois : A → B → C, chacun relevé jusqu'à sa
     * fermeture. Le balayage traverse les deux époques et va jusqu'au bout.
     */
    public function testSuccessiveHandoversCoverTheWholePeriod(): void
    {
        $fleet = [
            self::meter('2026-06-09 00:00:00', '2026-06-10 00:00:00'),
            self::meter('2026-06-19 00:00:00', '2026-06-20 00:00:00'),
            self::meter('2026-06-30 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /**
     * Même cascade, mais le dernier maillon lâche : C est bien en service, jamais
     * fermé, et pourtant plus relevé depuis le 22. Passer les deux premières
     * époques ne doit pas blanchir cette fin-là.
     */
    public function testACascadeStillStopsAtTheSurvivorThatWentSilent(): void
    {
        $fleet = [
            self::meter('2026-06-09 00:00:00', '2026-06-10 00:00:00'),
            self::meter('2026-06-19 00:00:00', '2026-06-20 00:00:00'),
            self::meter('2026-06-22 00:00:00'),
        ];

        self::assertSame('2026-06-22 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }

    /**
     * Un compteur fermé EXACTEMENT au début de la période demandée en sort : la
     * borne est exclue, son dernier jour en service est la veille.
     */
    public function testAMeterClosedOnTheFirstInstantOfThePeriodIsAlreadyOut(): void
    {
        $fleet = [
            self::meter('2026-05-31 00:00:00', self::JUNE),
            self::meter('2026-06-30 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE));
    }
}
