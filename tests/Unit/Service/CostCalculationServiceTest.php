<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\TariffGrid;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Tests d'orchestration : branches available/false et propagation des calculs.
 * Les repositories sont remplacés par des fakes (aucune base de données) ;
 * le vrai TariffCalculatorService est utilisé pour valider l'intégration.
 */
final class CostCalculationServiceTest extends TestCase
{
    private function makeService(
        FakeLegacyDailyRepository $legacy,
        FakeTariffRepository $tariff,
        FakeGasReadingRepository $gas,
    ): CostCalculationService {
        return new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: $tariff,
            gasRepo: $gas,
            calculator: new TariffCalculatorService(),
        );
    }

    /** @return array<string,mixed> */
    private function electricityDeltas(): array
    {
        return [
            'from'        => '2026-06-01 00:00:00',
            'to'          => '2026-06-15 12:00:00',
            'prelev_jour' => 100.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 20.0,
            'injec_nuit'  => 10.0,
            'solar'       => 0.0,
        ];
    }

    private function electricityGrid(): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Elec test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: ['energy_t1' => 0.10, 'energy_t2' => 0.08, 'subscription' => 5.0],
        );
    }

    private function gasGrid(float $pcs): TariffGrid
    {
        return new TariffGrid(
            id: 2,
            energyType: 'gas',
            name: 'Gaz test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: ['energy' => 0.05, 'subscription' => 3.0, 'distribution' => 0.02],
            pcsCoefficient: $pcs,
        );
    }

    // ── Électricité ──────────────────────────────────────────────────────────

    public function testCurrentMonthElectricityUnavailableWhenNoData(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: []),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertFalse($r['available']);
        self::assertSame('No data for current month', $r['reason']);
    }

    public function testCurrentMonthElectricityUnavailableWhenNoTariff(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: $this->electricityDeltas()),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertFalse($r['available']);
        self::assertSame('No active electricity tariff configured', $r['reason']);
    }

    public function testCurrentMonthElectricityHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertTrue($r['available']);
        self::assertSame(15, $r['days']);                 // 14 j 12 h + 1, même mois
        self::assertSame('Elec test', $r['tariff_name']);
        self::assertIsArray($r['cost']);
        self::assertArrayHasKey('total', $r['cost']);
    }

    public function testMonthElectricityUnavailableWhenNoData(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: []),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 3);

        self::assertFalse($r['available']);
        self::assertSame('No data for 2026-03', $r['reason']);
    }

    public function testMonthElectricityHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertSame(15, $r['days']);
        self::assertArrayHasKey('total', $r['cost']);
    }

    // ── Gaz : dernière période ───────────────────────────────────────────────

    public function testLastGasPeriodNeedsTwoReadings(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(lastTwo: ['from' => null, 'to' => null]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertFalse($r['available']);
        self::assertSame('Il faut au moins deux relevés gaz.', $r['reason']);
    }

    public function testLastGasPeriodUnavailableWhenNoTariff(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertFalse($r['available']);
    }

    public function testLastGasPeriodHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertTrue($r['available']);
        self::assertSame(30, $r['days']);
        self::assertEqualsWithDelta(100.0, $r['delta_m3'], 0.0001);
        self::assertEqualsWithDelta(10.0, $r['pcs_coefficient'], 0.0001);
        self::assertEqualsWithDelta(1000.0, $r['kwh'], 0.0001); // 100 m³ × PCS 10.0
    }

    // ── Gaz : mois calendaire (interpolation) ────────────────────────────────

    public function testMonthGasUnavailableWhenNoReadings(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(twoForMonth: ['from' => null, 'to' => null]),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertFalse($r['available']);
    }

    public function testMonthGasFullMonthNoInterpolation(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(twoForMonth: [
                'from' => ['reading_at' => '2026-04-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 1300.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertTrue($r['available']);
        self::assertTrue($r['is_full_month']);
        self::assertFalse($r['interpolated']);
        self::assertSame(30, $r['days']);          // avril = 30 jours
        self::assertSame(30, $r['calendar_days']);
        self::assertEqualsWithDelta(300.0, $r['delta_m3'], 0.0001);
    }

    public function testMonthGasPartialCoverageInterpolates(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(twoForMonth: [
                'from' => ['reading_at' => '2026-04-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-04-16 00:00:00', 'counter_m3' => 1150.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertTrue($r['available']);
        self::assertFalse($r['is_full_month']);
        self::assertSame(15, $r['days']);          // couverture réelle
        self::assertEqualsWithDelta(150.0, $r['delta_m3'], 0.0001);
    }

    public function testMonthGasRejectsIdenticalTimestamps(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(twoForMonth: [
                'from' => ['reading_at' => '2026-04-10 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-04-10 00:00:00', 'counter_m3' => 1000.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertFalse($r['available']);
        self::assertSame('Les deux relevés ont le même horodatage.', $r['reason']);
    }
}
