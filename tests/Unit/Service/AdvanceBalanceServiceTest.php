<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\AdvanceSchedule;
use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\AdvanceBalanceService;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeAdvanceScheduleRepository;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeMeterReadingRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Le solde affiché décide si l'utilisateur croit devoir un complément ou attendre
 * un remboursement : les cas où le coût n'est PAS calculable comptent donc autant
 * que les cas nominaux — un coût manquant traité comme 0 € annoncerait un
 * remboursement imaginaire.
 */
final class AdvanceBalanceServiceTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function schedule(
        string $energyType = 'electricity',
        float $amount = 120.0,
        string $validFrom = '2025-01-01',
        ?string $validTo = null,
        int $dueDay = 1,
        int $id = 1,
    ): AdvanceSchedule {
        return new AdvanceSchedule(
            id: $id,
            energyType: $energyType,
            amountMonthly: $amount,
            validFrom: $this->at($validFrom),
            validTo: $validTo !== null ? $this->at($validTo) : null,
            dueDay: $dueDay,
        );
    }

    /** Grille élec simple : 0,10 €/kWh T1, sans poste fixe, pour un coût lisible. */
    private function electricityGrid(string $pricingMode = TariffGrid::PRICING_MODE_DEFAULT): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Elec test',
            validFrom: new DateTimeImmutable('2020-01-01'),
            validTo: null,
            lines: ['energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1)],
            pricingMode: $pricingMode,
        );
    }

    /**
     * `data_from`/`data_to` = fenêtre réellement couverte par des relevés. Par
     * défaut elle épouse la période, comme un flux de relevés à jour ; les tests de
     * couverture partielle les écrasent.
     *
     * @return array<string,mixed>
     */
    private function elecDeltas(string $from, string $to, float $kwh, ?string $dataTo = null): array
    {
        return [
            'from'        => $from,
            'to'          => $to,
            'prelev_jour' => $kwh,
            'prelev_nuit' => 0.0,
            'injec_jour'  => 0.0,
            'injec_nuit'  => 0.0,
            'solar'       => 0.0,
            'data_from'   => $from,
            'data_to'     => $dataTo ?? $to,
        ];
    }

    private function costService(
        FakeLegacyDailyRepository $legacy,
        FakeTariffRepository $tariffs,
        ?FakeGasReadingRepository $gas = null,
        ?FakeMeterReadingRepository $water = null,
    ): CostCalculationService {
        return new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: $tariffs,
            gasRepo: $gas ?? new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            waterRepo: $water,
        );
    }

    public function testSurplusIsReportedAsAPositiveBalance(): void
    {
        // 12 échéances de 120 € = 1 440 € payés ; 10 000 kWh × 0,10 € = 1 000 € dus.
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertEqualsWithDelta(1440.0, $r['total_paid'], 0.01);
        self::assertEqualsWithDelta(1000.0, $r['total_cost'], 0.01);
        self::assertEqualsWithDelta(440.0, $r['total_balance'], 0.01);
        self::assertFalse($r['has_gaps']);
    }

    public function testShortfallIsReportedAsANegativeBalance(): void
    {
        // 12 × 50 € = 600 € payés ; 10 000 kWh × 0,10 € = 1 000 € dus.
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule(amount: 50.0)]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertEqualsWithDelta(-400.0, $r['total_balance'], 0.01);
        self::assertEqualsWithDelta(-400.0, (float) $r['balances'][0]->balance(), 0.01);
    }

    /**
     * Sans relevés, le coût est inconnu — pas nul. La ligne doit sortir avec sa
     * raison, rester hors du total, et lever le drapeau `has_gaps`.
     */
    public function testEnergyWithoutDataIsFlaggedAndExcludedFromTheTotal(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: []),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertCount(1, $r['balances']);
        self::assertNull($r['balances'][0]->cost);
        self::assertNull($r['balances'][0]->balance());
        self::assertNotNull($r['balances'][0]->unavailable);
        self::assertEqualsWithDelta(0.0, $r['total_cost'], 0.01);
        self::assertEqualsWithDelta(0.0, $r['total_balance'], 0.01);
        self::assertTrue($r['has_gaps']);
    }

    public function testEnergyWithoutTariffGridIsFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0)),
                new FakeTariffRepository(), // aucune grille active
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertTrue($r['has_gaps']);
        self::assertNull($r['balances'][0]->cost);
    }

    /** Une énergie sans acompte ni coût n'a rien à dire : pas de ligne vide. */
    public function testEnergiesWithoutAdvanceNorCostAreOmitted(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertCount(1, $r['balances']);
        self::assertSame('electricity', $r['balances'][0]->energyType);
    }

    public function testSeveralEnergiesAreSummedTogether(): void
    {
        $gasGrid = new TariffGrid(
            id: 2,
            energyType: 'gas',
            name: 'Gaz test',
            validFrom: new DateTimeImmutable('2020-01-01'),
            validTo: null,
            lines: ['energy' => new TariffLine('energy', 0.05, ComponentKind::EnergyFlat)],
            pcsCoefficient: 10.0,
        );

        $tariffs = new FakeTariffRepository();
        // findActiveGridsBetween ignore l'énergie dans le fake : on ne peut pas
        // servir deux grilles distinctes, donc le gaz est testé seul ici.
        $tariffs->grid = $gasGrid;

        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(energyType: 'gas', amount: 80.0, dueDay: 1, id: 2),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: []),
                $tariffs,
                new FakeGasReadingRepository(forInterpolation: [
                    ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 0.0],
                    ['reading_at' => '2026-12-31 00:00:00', 'counter_m3' => 1000.0],
                ]),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        // 12 × 80 € = 960 € payés ; 1 000 m³ × 10 kWh × 0,05 € = 500 € dus.
        self::assertEqualsWithDelta(960.0, $r['total_paid'], 0.01);
        self::assertEqualsWithDelta(500.0, $r['total_cost'], 0.01);
        self::assertEqualsWithDelta(460.0, $r['total_balance'], 0.01);
    }

    /** Deux barèmes successifs sur la même énergie s'additionnent sans doublon. */
    public function testConsecutiveSchedulesAreSummed(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(amount: 100.0, validFrom: '2026-01-01', validTo: '2026-07-01', id: 1),
                $this->schedule(amount: 150.0, validFrom: '2026-07-01', validTo: null, id: 2),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        // 6 × 100 € + 6 × 150 € = 1 500 €.
        self::assertEqualsWithDelta(1500.0, $r['total_paid'], 0.01);
        self::assertSame(12, $r['balances'][0]->dueCount);
    }

    /**
     * Une énergie chiffrée sans acompte est AFFICHÉE — la taire donnerait
     * l'impression que ses index et sa grille ne sont pas lus — mais elle reste
     * hors du total : l'y inclure retrancherait son coût de 0 € payé et
     * annoncerait une dette imaginaire.
     */
    public function testEnergyWithACostButNoScheduleIsShownYetExcludedFromTheTotal(): void
    {
        $gasGrid = new TariffGrid(
            id: 2,
            energyType: 'gas',
            name: 'Gaz test',
            validFrom: new DateTimeImmutable('2020-01-01'),
            validTo: null,
            lines: ['energy' => new TariffLine('energy', 0.05, ComponentKind::EnergyFlat)],
            pcsCoefficient: 10.0,
        );

        // Barème électricité uniquement, mais le gaz est parfaitement chiffrable.
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: []),
                new FakeTariffRepository($gasGrid),
                new FakeGasReadingRepository(forInterpolation: [
                    ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 0.0],
                    ['reading_at' => '2026-12-31 00:00:00', 'counter_m3' => 1000.0],
                ]),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        // Le gaz est bien rendu…
        self::assertSame(['electricity', 'gas'], array_map(
            static fn ($b): string => $b->energyType,
            $r['balances'],
        ));

        $gas = $r['balances'][1];
        self::assertNotNull($gas->cost);
        self::assertSame(0, $gas->dueCount);

        // … mais son coût n'entre pas dans le total : pas de dette inventée.
        self::assertEqualsWithDelta(0.0, $r['total_cost'], 0.01);
        self::assertEqualsWithDelta(0.0, $r['total_balance'], 0.01);
        self::assertTrue($r['has_cost_without_advance']);

        // Aucun barème gaz n'existe : le conseil « ajoutez-lui un barème » est ici
        // le bon, et le drapeau de la fenêtre sans échéance ne doit pas se lever.
        self::assertFalse($gas->hasSchedule);
        self::assertFalse($r['has_advance_without_instalment']);
        self::assertFalse($r['has_short_advance_without_instalment']);
    }

    /**
     * #254 — Un barème court sur toute la fenêtre, mais son jour de prélèvement
     * n'y tombe pas : sur deux jours de fin de mois, un acompte prélevé le 1er ne
     * produit aucune échéance. Le montant payé est nul SANS qu'aucun acompte ne
     * manque, et l'écran ne doit pas réclamer un barème déjà saisi.
     */
    public function testScheduleWithoutADueDateInTheWindowIsNotReportedAsMissing(): void
    {
        $svc = new AdvanceBalanceService(
            // 166,36 €/mois prélevés le 1er, plage ouverte : le contrat court.
            new FakeAdvanceScheduleRepository([$this->schedule(amount: 166.36, validFrom: '2026-07-01')]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-07-28 00:00:00', '2026-07-29 00:00:00', 40.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-07-28'), $this->at('2026-07-29'));

        $elec = $r['balances'][0];
        self::assertSame(0, $elec->dueCount);
        self::assertEqualsWithDelta(0.0, $elec->paid, 0.01);
        self::assertEqualsWithDelta(4.0, (float) $elec->cost, 0.01);

        // Le barème est reconnu : c'est la fenêtre qui est trop étroite, pas
        // l'acompte qui manque.
        self::assertTrue($elec->hasSchedule);
        self::assertTrue($elec->hasScheduleWithoutInstalment());
        self::assertTrue($r['has_advance_without_instalment']);
        self::assertFalse($r['has_cost_without_advance']);

        // Le barème couvre toute la fenêtre : c'est bien elle qu'il faut élargir,
        // et non la plage de validité qu'il faut corriger.
        self::assertFalse($r['has_short_advance_without_instalment']);

        // Le calcul, lui, ne bouge pas : la ligne reste hors du solde.
        self::assertEqualsWithDelta(0.0, $r['total_cost'], 0.01);
        self::assertEqualsWithDelta(0.0, $r['total_balance'], 0.01);
    }

    /**
     * Un barème échu avant la période n'est pas « un barème qui couvre la
     * fenêtre » : le conseil d'en ajouter un redevient légitime.
     */
    public function testScheduleExpiredBeforeTheWindowStillCountsAsMissing(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(validFrom: '2025-01-01', validTo: '2026-07-28'),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-07-28 00:00:00', '2026-07-29 00:00:00', 40.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-07-28'), $this->at('2026-07-29'));

        self::assertFalse($r['balances'][0]->hasSchedule);
        self::assertTrue($r['has_cost_without_advance']);
        self::assertFalse($r['has_advance_without_instalment']);
    }

    /**
     * Fenêtre et validité sont désormais toutes deux `[from, to[` (#1), et
     * `hasSchedule` se mesure sur les bornes telles quelles. Un barème valable le
     * seul 29/07 commence là où la fenêtre `[28/07, 29/07[` s'arrête : il en est
     * dehors. Le compter comme couvrant ferait promettre à l'écran un
     * élargissement de période inutile. Fixture volontairement adjacente :
     * disjointe d'un jour franc, elle passerait quelle que soit la convention.
     */
    public function testScheduleStartingOnTheExcludedEndBoundIsOutsideTheWindow(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(validFrom: '2026-07-29', validTo: '2026-07-30'),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-07-28 00:00:00', '2026-07-29 00:00:00', 40.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-07-28'), $this->at('2026-07-29'));

        self::assertFalse($r['balances'][0]->hasSchedule);
        self::assertTrue($r['has_cost_without_advance']);
    }

    /**
     * #254 — Le conseil « élargissez la période » n'a de sens que si le barème
     * couvre DÉJÀ toute la fenêtre : c'est alors elle, et elle seule, qui est trop
     * étroite. Quand le barème n'en couvre qu'une fraction, c'est sa plage de
     * validité qui borne l'intersection, et l'élargir ne fera apparaître aucune
     * échéance — cas d'un barème saisi le 30 alors que le prélèvement tombe le 1er.
     */
    public function testScheduleCoveringOnlyPartOfTheWindowGetsItsOwnAdvice(): void
    {
        $svc = new AdvanceBalanceService(
            // Créé le 30/07, prélevé le 1er : la première échéance sera le 1er août.
            new FakeAdvanceScheduleRepository([$this->schedule(validFrom: '2026-07-30')]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-07-01 00:00:00', '2026-08-01 00:00:00', 40.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-07-01'), $this->at('2026-08-01'));

        $elec = $r['balances'][0];
        self::assertSame(0, $elec->dueCount);
        self::assertTrue($elec->hasSchedule);

        // Le barème est reconnu, mais on ne promet pas qu'élargir la fenêtre aidera.
        self::assertTrue($r['has_short_advance_without_instalment']);
        self::assertFalse($r['has_advance_without_instalment']);
        self::assertFalse($r['has_cost_without_advance']);
    }

    /**
     * Même impasse par l'autre bout : la plage de validité est plus courte qu'un
     * mois et ne contient pas le jour de prélèvement. Aucune fenêtre, si large
     * soit-elle, ne fera tomber une échéance dans cet intervalle — le barème
     * lui-même est à corriger.
     */
    public function testScheduleTooShortToEverHoldItsDueDayGetsItsOwnAdvice(): void
    {
        $svc = new AdvanceBalanceService(
            // Valable du 1er au 10 juillet, mais prélevé le 15 : jamais d'échéance.
            new FakeAdvanceScheduleRepository([
                $this->schedule(validFrom: '2026-07-01', validTo: '2026-07-11', dueDay: 15),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas('2026-07-01 00:00:00', '2026-08-01 00:00:00', 40.0)),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-07-01'), $this->at('2026-08-01'));

        self::assertSame(0, $r['balances'][0]->dueCount);
        self::assertTrue($r['has_short_advance_without_instalment']);
        self::assertFalse($r['has_advance_without_instalment']);
    }

    /**
     * Le message d'indisponibilité doit remonter jusqu'à l'écran : « non
     * calculable » sans motif est un mur muet, impossible à diagnostiquer.
     */
    public function testUnavailableReasonIsCarriedToTheCaller(): void
    {
        $r = (new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: []),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        ))->balanceFor($this->at('2026-01-01'), $this->at('2026-06-30'));

        self::assertNotNull($r['balances'][0]->unavailable);
        self::assertStringContainsString('2026-01-01', (string) $r['balances'][0]->unavailable);
    }

    /**
     * Relevés arrêtés à mi-parcours : le coût ne porte que sur la fraction mesurée
     * alors que les acomptes couvrent toute la période. Le solde penche
     * artificiellement — il doit être signalé, pas présenté comme un remboursement.
     */
    public function testPartialDataCoverageIsFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-06-30 00:00:00',
                    5000.0,
                    dataTo: '2026-06-30 00:00:00', // le flux s'arrête fin juin
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertTrue($r['has_partial_data']);
        self::assertTrue($r['balances'][0]->partialData);
        // Le montant reste affiché : c'est une consommation réelle, seulement incomplète.
        self::assertNotNull($r['balances'][0]->cost);
    }

    /**
     * Symétrique du cas « données partielles » : un barème qui ne couvre que sept
     * des douze mois est comparé au coût de la période entière et annonce une dette
     * inexistante. La ligne reste affichée — le bilan garde du sens — mais l'écart
     * de fenêtre doit être signalé.
     */
    public function testScheduleCoveringPartOfThePeriodIsFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(validFrom: '2026-06-01', validTo: '2027-01-01'),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-12-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertSame(7, $r['balances'][0]->dueCount);
        self::assertTrue($r['balances'][0]->partialAdvances);
        self::assertTrue($r['has_partial_advances']);
        // La couverture des DONNÉES, elle, est complète : les deux causes restent
        // distinctes, sous deux messages différents.
        self::assertFalse($r['has_partial_data']);
    }

    /**
     * Fenêtre NON alignée sur les mois — celle que la page propose par défaut
     * (« il y a un an → aujourd'hui »). Elle traverse treize mois calendaires pour
     * douze prélèvements : compter les mois au lieu de mesurer la couverture
     * ferait crier au loup à chaque visite, sur un barème pourtant complet.
     */
    public function testUnalignedDefaultWindowDoesNotFlagACompleteSchedule(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule(validFrom: '2020-01-01')]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2025-07-31 00:00:00',
                    '2026-07-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2025-07-31'), $this->at('2026-07-31'));

        self::assertSame(12, $r['balances'][0]->dueCount);
        self::assertFalse($r['balances'][0]->partialAdvances);
        self::assertFalse($r['has_partial_advances']);
    }

    /** Deux barèmes qui se recollent couvrent la fenêtre : aucune lacune. */
    public function testConsecutiveSchedulesTogetherCoverTheWindow(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(amount: 100.0, validFrom: '2026-01-01', validTo: '2026-07-01', id: 1),
                $this->schedule(amount: 150.0, validFrom: '2026-07-01', validTo: null, id: 2),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-12-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertFalse($r['has_partial_advances']);
    }

    /** Un trou entre deux barèmes est une lacune, même si les deux existent. */
    public function testGapBetweenTwoSchedulesIsFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([
                $this->schedule(amount: 100.0, validFrom: '2026-01-01', validTo: '2026-04-01', id: 1),
                // Rien en avril : trou d'un mois.
                $this->schedule(amount: 150.0, validFrom: '2026-05-01', validTo: null, id: 2),
            ]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-12-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertTrue($r['has_partial_advances']);
    }

    public function testScheduleCoveringTheWholePeriodIsNotFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-12-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertFalse($r['has_partial_advances']);
    }

    public function testCompleteDataCoverageIsNotFlagged(): void
    {
        $svc = new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService(
                new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas(
                    '2026-01-01 00:00:00',
                    '2026-12-31 00:00:00',
                    10000.0,
                )),
                new FakeTariffRepository($this->electricityGrid()),
            ),
        );

        $r = $svc->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertFalse($r['has_partial_data']);
        self::assertFalse($r['balances'][0]->partialData);
    }

    /**
     * Grille dynamique mais aucun prix de marché importé (#245) : le bilan reste DÛ,
     * donc rendu — la période retombe sur le tarif fournisseur de la grille plutôt que
     * de disparaître le temps qu'un cron rattrape les prix.
     *
     * C'est le changement de contrat introduit par le mode-en-grille : l'ancien
     * aiguillage renvoyait ici une indisponibilité, en pleine page d'acomptes, pour un
     * utilisateur qui a bel et bien consommé.
     */
    public function testDynamicGridWithoutPricesFallsBackToTheSupplierTariff(): void
    {
        $costSvc = new CostCalculationService(
            legacyRepo: new FakeLegacyDailyRepository(
                deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0),
            ),
            tariffRepo: new FakeTariffRepository($this->electricityGrid('dynamic_hourly')),
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: new FakeDynamicPriceRepository(),
            dynamicEnabled: true,
        );

        $r = (new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $costSvc,
        ))->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        // 10 000 kWh × 0,10 € = 1 000 € au tarif fournisseur, contre 1 440 € versés.
        self::assertFalse($r['has_gaps']);
        self::assertNotNull($r['balances'][0]->cost);
        self::assertEqualsWithDelta(1000.0, $r['balances'][0]->cost, 0.01);
        self::assertEqualsWithDelta(1440.0, $r['balances'][0]->paid, 0.01);
    }

    /**
     * Le kill-switch serveur prime sur le mode de la grille : prix dynamiques coupés,
     * une grille dynamique est facturée exactement comme une grille fixe, sans que
     * rien ne soit réécrit en base.
     */
    public function testServerKillSwitchNeutralisesADynamicGrid(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0),
        );

        $withKillSwitch = (new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService($legacy, new FakeTariffRepository($this->electricityGrid('dynamic_quarter'))),
        ))->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        $fixed = (new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $this->costService($legacy, new FakeTariffRepository($this->electricityGrid())),
        ))->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        self::assertEqualsWithDelta(
            (float) $fixed['balances'][0]->cost,
            (float) $withKillSwitch['balances'][0]->cost,
            0.001,
        );
    }
}
