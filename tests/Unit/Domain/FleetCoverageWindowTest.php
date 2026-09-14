<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\FleetCoverageWindow;
use PHPUnit\Framework\TestCase;

/**
 * Fenêtre couverte par un parc de compteurs (#76 côté fin, #81 côté début).
 *
 * Deux exigences se tiennent ensemble, et c'est leur tension qui fait tout
 * l'intérêt de ces cas : un compteur qu'on a CESSÉ DE RELEVER — ou qu'on n'a PAS
 * ENCORE RELEVÉ — doit continuer à dégrader la couverture, sinon un coût partiel
 * s'annonce complet ; alors qu'un compteur FERMÉ, ou PAS ENCORE POSÉ, ne le doit
 * plus, puisque rien n'est attendu de lui. Avant ces correctifs les deux étaient
 * traités pareil : remplacer son compteur figeait la couverture de tous les
 * rapports suivants, en ajouter un la figeait pour tous les rapports antérieurs.
 *
 * Les deux côtés sont le même balayage dans un miroir temporel : chaque cas de
 * fin a donc son jumeau de début, et c'est voulu — si le miroir se fêle, un seul
 * des deux tombe.
 *
 * Scénario de référence : le compteur A est fermé le 15/06/2026, le compteur B
 * est posé le même jour et lui succède.
 */
final class FleetCoverageWindowTest extends TestCase
{
    private const MARCH_START = '2026-03-01 00:00:00';
    private const MARCH_END   = '2026-04-01 00:00:00';
    private const JUNE_START  = '2026-06-01 00:00:00';
    private const JUNE_END    = '2026-07-01 00:00:00';
    private const JULY_START  = '2026-07-01 00:00:00';
    private const JULY_END    = '2026-08-01 00:00:00';

    /**
     * @return array{start: string, end: string, opened_at: string|null, closed_at: string|null}
     */
    private static function meter(
        string $start,
        string $end,
        ?string $openedAt = null,
        ?string $closedAt = null,
    ): array {
        return ['start' => $start, 'end' => $end, 'opened_at' => $openedAt, 'closed_at' => $closedAt];
    }

    // ── L'intersection, inchangée ────────────────────────────────────────────

    /**
     * Sans aucune date de cycle de vie, la règle rend exactement l'intersection
     * d'avant : fin la plus précoce, début le plus tardif. Un compteur relevé du
     * 10 au 20 borne la couverture des deux côtés, quoi que fassent ses voisins.
     */
    public function testWithoutAnyLifecycleDateTheIntersectionStillWins(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-30 00:00:00'),
            self::meter('2026-06-10 00:00:00', '2026-06-20 00:00:00'),
        ];

        self::assertSame('2026-06-20 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
        self::assertSame('2026-06-10 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::JUNE_START, self::JUNE_END));
    }

    /** À un seul compteur sans borne, la couverture est sa propre fenêtre. */
    public function testASingleUnboundedMeterCoversItsOwnWindow(): void
    {
        $fleet = [self::meter('2026-06-02 00:00:00', '2026-06-30 00:00:00')];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
        self::assertSame('2026-06-02 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::JUNE_START, self::JUNE_END));
    }

    /** Aucun compteur ne porte de donnée : il n'y a pas de fenêtre à décrire. */
    public function testAnEmptyFleetHasNoCoverage(): void
    {
        self::assertNull(FleetCoverageWindow::coveredUntil([], self::JUNE_START, self::JUNE_END));
        self::assertNull(FleetCoverageWindow::coveredFrom([], self::JUNE_START, self::JUNE_END));
    }

    // ── Côté fin : la fermeture ne doit plus rien figer (#76) ────────────────

    /**
     * En juillet, le compteur A est fermé depuis le 15 juin : il n'avait rien à
     * couvrir de ce mois-là. Avant #76, `data_to` restait collé au 14 juin et tous
     * les mois suivants s'annonçaient partiels.
     */
    public function testAMeterClosedBeforeThePeriodDoesNotLimitItsEnd(): void
    {
        $fleet = [
            self::meter('2026-06-14 00:00:00', '2026-06-14 00:00:00', null, '2026-06-15 00:00:00'),
            self::meter('2026-07-01 00:00:00', '2026-07-31 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-07-31 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JULY_START, self::JULY_END));
    }

    /**
     * Mois du remplacement, relais propre : A est relevé jusqu'à la veille de sa
     * fermeture, B prend la suite. La tolérance d'un jour est ce qui rend ce cas
     * possible : la fermeture étant une borne EXCLUE, A ne PEUT pas porter de
     * relevé daté du 15.
     */
    public function testAClosedMeterHandedOverMidPeriodCoversTheWholeWindow(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-14 00:00:00', null, '2026-06-15 00:00:00'),
            self::meter('2026-06-15 00:00:00', '2026-06-30 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * A a cessé d'être relevé le 1er juin, deux semaines avant sa fermeture : vrai
     * trou, que le successeur ne comble pas. La fermeture ne rétroagit pas sur les
     * jours où le compteur était encore en service.
     */
    public function testAMeterThatStoppedBeingReadBeforeItsClosureStillDegradesTheEnd(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-01 00:00:00', null, '2026-06-15 00:00:00'),
            self::meter('2026-06-15 00:00:00', '2026-06-30 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-06-01 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * Fermeture sans successeur : plus rien n'est attendu, mais plus rien n'arrive
     * non plus. Un foyer qui ferme son dernier compteur ne doit pas voir ses coûts
     * partiels annoncés complets.
     */
    public function testAClosureWithoutSuccessorStopsTheCoverage(): void
    {
        $fleet = [self::meter('2026-06-01 00:00:00', '2026-06-14 00:00:00', null, '2026-06-15 00:00:00')];

        self::assertSame('2026-06-14 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /** Deux remplacements dans le même mois : le balayage traverse les époques. */
    public function testSuccessiveHandoversCoverTheWholePeriod(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-09 00:00:00', null, '2026-06-10 00:00:00'),
            self::meter('2026-06-10 00:00:00', '2026-06-19 00:00:00', '2026-06-10 00:00:00', '2026-06-20 00:00:00'),
            self::meter('2026-06-20 00:00:00', '2026-06-30 00:00:00', '2026-06-20 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * Même cascade, mais le dernier maillon lâche : C est en service, jamais fermé,
     * et pourtant plus relevé depuis le 22. Passer les deux premières époques ne
     * doit pas blanchir cette fin-là.
     */
    public function testACascadeStillStopsAtTheSurvivorThatWentSilent(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-09 00:00:00', null, '2026-06-10 00:00:00'),
            self::meter('2026-06-10 00:00:00', '2026-06-19 00:00:00', '2026-06-10 00:00:00', '2026-06-20 00:00:00'),
            self::meter('2026-06-20 00:00:00', '2026-06-22 00:00:00', '2026-06-20 00:00:00'),
        ];

        self::assertSame('2026-06-22 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    // ── Côté début : la pose ne doit plus rien figer non plus (#81) ──────────

    /**
     * LE cas de #81. Un compteur posé le 15 juin ne peut rien avoir couvert de
     * mars : il ne doit pas borner le début de ce mois-là. Avant, son premier
     * relevé (juin) devenait `data_from` et marquait partiels TOUS les rapports
     * antérieurs à sa pose — des années entières, sans qu'aucune fermeture ne soit
     * en jeu.
     */
    public function testAMeterCommissionedAfterThePeriodDoesNotLimitItsStart(): void
    {
        $fleet = [
            self::meter('2026-03-01 00:00:00', '2026-04-01 00:00:00'),
            self::meter('2026-06-15 00:00:00', '2026-06-15 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-03-01 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
    }

    /**
     * Mois du remplacement, vu du début : B posé le 15 ne borne pas le début de
     * juin, puisque A le couvrait jusqu'à sa fermeture. Jumeau exact de
     * {@see testAClosedMeterHandedOverMidPeriodCoversTheWholeWindow()}.
     */
    public function testAMeterCommissionedMidPeriodDoesNotLimitAStartCoveredByItsPredecessor(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-14 00:00:00', null, '2026-06-15 00:00:00'),
            self::meter('2026-06-15 00:00:00', '2026-06-30 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-06-01 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * B est posé le 15 mais n'est relevé qu'à partir du 25 : dix jours pendant
     * lesquels il était en service sans être mesuré. La pose ne blanchit pas ce
     * trou — jumeau du compteur qui cesse d'être relevé avant sa fermeture.
     */
    public function testAMeterReadLongAfterItsCommissioningStillDegradesTheStart(): void
    {
        $fleet = [
            self::meter('2026-06-01 00:00:00', '2026-06-14 00:00:00', null, '2026-06-15 00:00:00'),
            self::meter('2026-06-25 00:00:00', '2026-06-30 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-06-25 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * Le cas conservateur, et la raison d'être de la colonne : SANS date de mise
     * en service saisie, un compteur dont le premier relevé tombe en juin borne
     * toujours le début de mars. On ne peut pas distinguer « pas encore posé » de
     * « pas encore relevé », et la seconde lecture rend bien le rapport incomplet.
     */
    public function testWithoutACommissioningDateALateFirstReadingStillDegradesTheStart(): void
    {
        $fleet = [
            self::meter('2026-03-01 00:00:00', '2026-04-01 00:00:00'),
            self::meter('2026-06-15 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame('2026-06-15 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
    }

    /**
     * Parc entièrement posé après la période demandée : on retombe sur
     * l'intersection nue plutôt que de rendre la période couverte. Jumeau du parc
     * entièrement fermé avant elle.
     */
    public function testAFleetEntirelyCommissionedAfterThePeriodFallsBackOnTheLatestStart(): void
    {
        $fleet = [
            self::meter('2026-06-15 00:00:00', '2026-06-20 00:00:00', '2026-06-15 00:00:00'),
            self::meter('2026-06-25 00:00:00', '2026-06-30 00:00:00', '2026-06-25 00:00:00'),
        ];

        self::assertSame('2026-06-25 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
    }

    /** Parc entièrement fermé avant la période : le jumeau, côté fin. */
    public function testAFleetEntirelyClosedBeforeThePeriodFallsBackOnTheEarliestEnd(): void
    {
        $fleet = [
            self::meter('2026-05-01 00:00:00', '2026-05-20 00:00:00', null, '2026-05-21 00:00:00'),
            self::meter('2026-05-01 00:00:00', '2026-05-30 00:00:00', null, '2026-05-31 00:00:00'),
        ];

        self::assertSame('2026-05-20 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * Un compteur posé EXACTEMENT à la fin de la période demandée en sort : la
     * borne de fin d'une période est exclue, il n'en a pas couvert un instant.
     */
    public function testAMeterCommissionedOnTheLastInstantOfThePeriodIsAlreadyOut(): void
    {
        $fleet = [
            self::meter('2026-03-01 00:00:00', '2026-04-01 00:00:00'),
            self::meter('2026-04-01 00:00:00', '2026-04-30 00:00:00', self::MARCH_END),
        ];

        self::assertSame('2026-03-01 00:00:00', FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
    }

    /**
     * Et son jumeau : un compteur fermé exactement au premier instant de la période
     * en sort aussi — la fermeture est une borne EXCLUE, son dernier jour en
     * service est la veille.
     */
    public function testAMeterClosedOnTheFirstInstantOfThePeriodIsAlreadyOut(): void
    {
        $fleet = [
            self::meter('2026-05-01 00:00:00', '2026-05-31 00:00:00', null, self::JUNE_START),
            self::meter('2026-06-01 00:00:00', '2026-06-30 00:00:00'),
        ];

        self::assertSame('2026-06-30 00:00:00', FleetCoverageWindow::coveredUntil($fleet, self::JUNE_START, self::JUNE_END));
    }

    /**
     * Extension du parc, le cas le plus courant de #81 : un atelier ajouté en juin
     * ne dit rien de mars, et mars ne doit pas s'en trouver dégradé — ni côté
     * début, ni côté fin.
     */
    public function testAddingAMeterLeavesEarlierPeriodsEntirelyIntact(): void
    {
        $fleet = [
            self::meter(self::MARCH_START, self::MARCH_END),
            self::meter('2026-06-15 00:00:00', '2026-06-15 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame(self::MARCH_START, FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
        self::assertSame(self::MARCH_END, FleetCoverageWindow::coveredUntil($fleet, self::MARCH_START, self::MARCH_END));
    }

    /**
     * Même extension du parc, mais le compteur déclaré à l'avance porte des relevés
     * ANTIDATÉS — l'historique du compteur précédent recopié dessus, que rien
     * n'interdit (cf. api-contract.md). Sa fenêtre de relevés s'arrête alors AVANT
     * la période, et ne doit pas plus en borner la fin que le début : il n'était pas
     * en service, dans un sens de balayage comme dans l'autre.
     */
    public function testAMeterCommissionedAfterThePeriodDoesNotLimitItsEndEither(): void
    {
        $fleet = [
            self::meter(self::MARCH_START, self::MARCH_END),
            // Carnet recopié en janvier, pose prévue le 15 juin : aucun relevé entre
            // les deux, donc des bornes clampées sur le relevé de janvier.
            self::meter('2026-01-10 00:00:00', '2026-01-10 00:00:00', '2026-06-15 00:00:00'),
        ];

        self::assertSame(self::MARCH_END, FleetCoverageWindow::coveredUntil($fleet, self::MARCH_START, self::MARCH_END));
        self::assertSame(self::MARCH_START, FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
    }

    /**
     * Le jumeau, côté fermeture : un compteur fermé avant la période ne borne pas
     * son DÉBUT non plus. Cas plus théorique — la fermeture interdit les écritures
     * postérieures — mais le miroir doit rester intact des deux côtés.
     */
    public function testAMeterClosedBeforeThePeriodDoesNotLimitItsStartEither(): void
    {
        $fleet = [
            self::meter(self::MARCH_START, self::MARCH_END),
            self::meter('2026-03-20 00:00:00', '2026-03-25 00:00:00', null, '2026-03-01 00:00:00'),
        ];

        self::assertSame(self::MARCH_START, FleetCoverageWindow::coveredFrom($fleet, self::MARCH_START, self::MARCH_END));
        self::assertSame(self::MARCH_END, FleetCoverageWindow::coveredUntil($fleet, self::MARCH_START, self::MARCH_END));
    }
}
