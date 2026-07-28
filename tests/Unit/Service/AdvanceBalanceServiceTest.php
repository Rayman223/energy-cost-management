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
    private function electricityGrid(): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Elec test',
            validFrom: new DateTimeImmutable('2020-01-01'),
            validTo: null,
            lines: ['energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1)],
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
                $this->schedule(amount: 100.0, validFrom: '2026-01-01', validTo: '2026-06-30', id: 1),
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
    public function testEnergyWithACostButNoInstalmentIsShownYetExcludedFromTheTotal(): void
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
                $this->schedule(validFrom: '2026-06-01', validTo: '2026-12-31'),
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
     * En tarif dynamique, la part énergie ne peut pas être calculée au prix fixe de
     * la grille : le service doit emprunter la voie dynamique, quitte à signaler une
     * indisponibilité quand les prix de marché manquent.
     */
    public function testDynamicPricingUsesTheDynamicCostPath(): void
    {
        $costSvc = new CostCalculationService(
            legacyRepo: new FakeLegacyDailyRepository(
                deltasBetween: $this->elecDeltas('2026-01-01 00:00:00', '2026-12-31 00:00:00', 10000.0),
            ),
            tariffRepo: new FakeTariffRepository($this->electricityGrid()),
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: new FakeDynamicPriceRepository(),
            dynamicEnabled: true,
            pricingMode: 'dynamic_hourly',
        );

        $r = (new AdvanceBalanceService(
            new FakeAdvanceScheduleRepository([$this->schedule()]),
            $costSvc,
            dynamicPricing: true,
        ))->balanceFor($this->at('2026-01-01'), $this->at('2026-12-31'));

        // Aucun prix ni relevé horaire dans le fake : le coût dynamique est
        // indisponible, ce qui doit être DIT plutôt que remplacé par le calcul fixe.
        self::assertTrue($r['has_gaps']);
        self::assertNull($r['balances'][0]->cost);
        self::assertEqualsWithDelta(1440.0, $r['balances'][0]->paid, 0.01);
    }
}
