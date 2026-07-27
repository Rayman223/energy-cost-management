<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\EnergyBill;
use App\Domain\SpotFormulaFit;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\BillReconciliationService;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeEnergyBillRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Tests d'orchestration du rapprochement facture (#229) : ce qui est écarté, pourquoi, et
 * ce qui remonte à la vue. L'algèbre elle-même est couverte par SpotFormulaFitterTest.
 *
 * Repositories remplacés par des fakes ; le vrai CostCalculationService est utilisé, pour
 * que le contrat `spot_base` soit validé de bout en bout plutôt que simulé.
 */
final class BillReconciliationServiceTest extends TestCase
{
    private function gridWithSpot(
        float $coefficient,
        float $offsetTtc,
        int $id = 1,
        string $validFrom = '2026-01-01',
        ?string $validTo = null,
        float $vatRate = 21.0,
    ): TariffGrid {
        return new TariffGrid(
            id: $id,
            energyType: 'electricity',
            name: 'Elec spot ' . $id,
            validFrom: new DateTimeImmutable($validFrom),
            validTo: $validTo !== null ? new DateTimeImmutable($validTo) : null,
            lines: [
                'energy_t1'        => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
                'energy_t2'        => new TariffLine('energy_t2', 0.08, ComponentKind::EnergyT2),
                'spot_coefficient' => new TariffLine('spot_coefficient', $coefficient, ComponentKind::SpotCoefficient),
                'spot_offset'      => new TariffLine('spot_offset', $offsetTtc, ComponentKind::SpotOffset),
            ],
            vatRate: $vatRate,
        );
    }

    /** @return array<string,mixed> */
    private function deltasFor(string $from, string $to): array
    {
        return [
            'from'        => $from,
            'to'          => $to,
            'prelev_jour' => 100.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 0.0,
            'injec_nuit'  => 0.0,
            'solar'       => 0.0,
        ];
    }

    /**
     * @param array<string, float>                    $prices
     * @param list<array{hour: string, import_kwh: float}> $hourly
     */
    private function service(
        FakeEnergyBillRepository $bills,
        array $hourly,
        array $prices,
        float $coefficient = 1.0,
        float $offsetTtc = 0.0,
        ?FakeTariffRepository $tariffs = null,
        string $from = '2026-06-01 00:00:00',
        string $to = '2026-06-30 00:00:00',
    ): BillReconciliationService {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->deltasFor($from, $to),
            hourlyImportDeltas: $hourly,
        );

        $cost = new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: $tariffs ?? new FakeTariffRepository(grid: $this->gridWithSpot($coefficient, $offsetTtc)),
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: new FakeDynamicPriceRepository(pricesByHour: $prices),
            dynamicEnabled: true,
        );

        return new BillReconciliationService($bills, $cost);
    }

    public function testNoBillYieldsUndeterminedFit(): void
    {
        $result = $this->service(new FakeEnergyBillRepository(), [], [])->reconcile();

        self::assertSame([], $result['rows']);
        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $result['fit']->mode);
        self::assertNull($result['current']);
    }

    /**
     * Cas nominal à un mois : l'écart est affiché, et la correction d'offset à coefficient
     * figé annule exactement cet écart. C'est la sortie utile du cas « une seule facture ».
     */
    public function testSingleBillReportsGapAndOneUnknownCorrection(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: 5.00),
        ]);

        // 10 kWh à 0,20 € spot, coefficient 1,0, offset 0 → calculé = 10 × 0,20 × 1,21 = 2,42 €.
        $result = $this->service(
            $bills,
            [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            ['2026-06-10 10:00:00' => 0.20],
        )->reconcile();

        self::assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        self::assertSame('2026-06', $row['period']);
        self::assertNull($row['skipped']);
        self::assertEqualsWithDelta(5.00, $row['billed_ttc'], 0.0001);
        self::assertEqualsWithDelta(2.42, $row['computed_ttc'], 0.0001);
        self::assertEqualsWithDelta(2.58, $row['gap'], 0.0001);

        self::assertSame(SpotFormulaFit::MODE_SINGLE_PERIOD, $result['fit']->mode);
        // (5,00 − 1,0 × 2,42) / 10 = 0,258 €/kWh.
        self::assertEqualsWithDelta(0.258, $result['fit']->offsetAtCurrentCoefficient, 0.0001);
    }

    /**
     * Le HTVA seul doit suffire : la conversion utilise le taux TVA de la grille de la
     * période, et non un taux figé en base au moment de la saisie.
     */
    public function testHtvaOnlyBillIsConvertedWithTheGridVatRate(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: 100.0, amountTtc: null),
        ]);

        $result = $this->service(
            $bills,
            [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            ['2026-06-10 10:00:00' => 0.20],
        )->reconcile();

        // 100 € HTVA × 1,21 = 121 € TTC (grille à 21 %).
        self::assertEqualsWithDelta(121.0, $result['rows'][0]['billed_ttc'], 0.0001);
    }

    /** Une facture sans aucun montant saisi est listée, mais écartée de la résolution. */
    public function testBillWithoutAmountIsSkipped(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: null),
        ]);

        $result = $this->service(
            $bills,
            [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            ['2026-06-10 10:00:00' => 0.20],
        )->reconcile();

        self::assertSame(BillReconciliationService::SKIP_NO_AMOUNT, $result['rows'][0]['skipped']);
        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $result['fit']->mode);
    }

    /**
     * Un mois sans relevé horaire ne peut pas être rapproché. Il doit rester VISIBLE avec
     * sa raison : le taire donnerait un couple déduit d'un sous-ensemble sans que
     * l'utilisateur puisse s'en rendre compte.
     */
    public function testMonthWithoutReadingsIsListedWithItsReason(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: 120.0),
        ]);

        // Aucun relevé horaire → buildDynamicResponse renvoie available: false.
        $result = $this->service($bills, [], [])->reconcile();

        self::assertCount(1, $result['rows']);
        self::assertSame(BillReconciliationService::SKIP_NO_ESTIMATE, $result['rows'][0]['skipped']);
        self::assertNull($result['rows'][0]['billed_ttc']);
        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $result['fit']->mode);
    }

    /**
     * Mois à cheval sur deux contrats aux formules différentes : son équation mêlerait
     * deux couples. Il doit être listé avec `mixed_grids` et exclu de la résolution —
     * l'inclure attribuerait la moyenne des deux contrats à celui en cours.
     */
    public function testMonthSpanningTwoFormulasIsSkippedEndToEnd(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 1, amountHtva: null, amountTtc: 120.0),
        ]);

        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->gridWithSpot(1.05, 0.03, id: 2, validFrom: '2026-01-11'),
            $this->gridWithSpot(1.08, 0.02, id: 1, validFrom: '2026-01-01', validTo: '2026-01-10'),
        ];

        $result = $this->service(
            $bills,
            [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0],
            ],
            ['2026-01-05 10:00:00' => 0.20, '2026-01-20 10:00:00' => 0.20],
            tariffs: $tariffs,
            from: '2026-01-01 00:00:00',
            to: '2026-01-31 00:00:00',
        )->reconcile();

        self::assertSame(BillReconciliationService::SKIP_MIXED_GRIDS, $result['rows'][0]['skipped']);
        self::assertNull($result['rows'][0]['billed_ttc']);
        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $result['fit']->mode);
    }

    /**
     * Deux sous-périodes de MÊME formule mais de TVA différente (21 % → 6 %) : la
     * résolution resterait exacte, mais la conversion d'un montant HTVA en TTC serait
     * ambiguë. Le mois est donc écarté lui aussi (suivi de revue #236).
     */
    public function testMonthSpanningTwoVatRatesIsSkipped(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 1, amountHtva: 100.0, amountTtc: null),
        ]);

        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->gridWithSpot(1.08, 0.02, id: 2, validFrom: '2026-01-11', vatRate: 6.0),
            $this->gridWithSpot(1.08, 0.02, id: 1, validFrom: '2026-01-01', validTo: '2026-01-10', vatRate: 21.0),
        ];

        $result = $this->service(
            $bills,
            [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0],
            ],
            ['2026-01-05 10:00:00' => 0.20, '2026-01-20 10:00:00' => 0.20],
            tariffs: $tariffs,
            from: '2026-01-01 00:00:00',
            to: '2026-01-31 00:00:00',
        )->reconcile();

        self::assertSame(BillReconciliationService::SKIP_MIXED_GRIDS, $result['rows'][0]['skipped']);
    }

    /**
     * Des prix de marché existent pour le mois, mais aucun ne tombe sur une heure de
     * consommation : le mois n'apporte aucune équation. Il est listé avec ses montants
     * (l'écart reste informatif) mais marqué `no_coverage` et exclu de la résolution.
     */
    public function testMonthWithoutCoveredHourIsListedButExcluded(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: 5.0),
        ]);

        $result = $this->service(
            $bills,
            [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            // Prix connu sur une heure SANS consommation → couverture nulle.
            ['2026-06-11 03:00:00' => 0.20],
        )->reconcile();

        $row = $result['rows'][0];
        self::assertSame(BillReconciliationService::SKIP_NO_COVERAGE, $row['skipped']);
        self::assertEqualsWithDelta(5.0, $row['billed_ttc'], 0.0001);
        self::assertEqualsWithDelta(0.0, $row['covered_kwh'], 0.0001);
        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $result['fit']->mode);
    }

    /**
     * Pagination (suivi de revue #236) : chaque facture affichée coûte un recalcul complet
     * du mois, la page est donc bornée. La résolution ne porte que sur la page affichée —
     * mêler un contrat antérieur au contrat en cours produirait un couple moyen.
     */
    public function testPaginationBoundsThePageAndReportsTotals(): void
    {
        $bills = new FakeEnergyBillRepository();
        for ($i = 0; $i < 15; $i++) {
            $bills->upsert('electricity', 2025 + intdiv($i, 12), ($i % 12) + 1, null, 100.0 + $i);
        }

        $service = $this->service($bills, [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]], ['2026-06-10 10:00:00' => 0.20]);

        $first = $service->reconcile('electricity', 1);
        self::assertCount(BillReconciliationService::PAGE_SIZE, $first['rows']);
        self::assertSame(15, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame(1, $first['page']);

        $second = $service->reconcile('electricity', 2);
        self::assertCount(3, $second['rows']);
        self::assertSame(2, $second['page']);
    }

    /** Une page hors bornes est ramenée dans l'intervalle, pas rendue vide sans explication. */
    public function testOutOfRangePageIsClamped(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: 5.0),
        ]);

        $service = $this->service($bills, [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]], ['2026-06-10 10:00:00' => 0.20]);

        self::assertSame(1, $service->reconcile('electricity', 99)['page']);
        self::assertCount(1, $service->reconcile('electricity', 99)['rows']);
    }

    /** Sans aucune facture, la pagination reste cohérente (une page vide, pas zéro page). */
    public function testPaginationOnEmptyListStaysCoherent(): void
    {
        $result = $this->service(new FakeEnergyBillRepository(), [], [])->reconcile();

        self::assertSame(0, $result['total']);
        self::assertSame(1, $result['pages']);
        self::assertSame(1, $result['page']);
    }

    /**
     * La formule courante remonte de la grille : les corrections proposées partent de ce
     * qui est réellement appliqué, pas d'une formule neutre.
     */
    public function testCurrentFormulaIsTakenFromTheGrid(): void
    {
        $bills = new FakeEnergyBillRepository([
            new EnergyBill(1, 'electricity', 2026, 6, amountHtva: null, amountTtc: 5.00),
        ]);

        $result = $this->service(
            $bills,
            [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            ['2026-06-10 10:00:00' => 0.20],
            coefficient: 1.08,
            offsetTtc: 0.02,
        )->reconcile();

        self::assertNotNull($result['current']);
        self::assertEqualsWithDelta(1.08, $result['current']->coefficient, 0.0001);
        self::assertEqualsWithDelta(0.02, $result['current']->offsetTtc, 0.0001);

        // Base indexée 2,42 € : (5,00 − 1,08 × 2,42) / 10 = 0,23864 €/kWh.
        self::assertEqualsWithDelta(0.23864, $result['fit']->offsetAtCurrentCoefficient, 0.0001);
    }
}
