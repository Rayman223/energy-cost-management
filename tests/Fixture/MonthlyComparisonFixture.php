<?php

declare(strict_types=1);

namespace Tests\Fixture;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeLoadProfileRepository;
use Tests\Fake\FakeTariffRepository;

/** Prix RLP 0,25, prix pondéré réel 0,15 et prix horaire 0,20 €/kWh HTVA. */
final class MonthlyComparisonFixture
{
    public FakeLegacyDailyRepository $legacy;
    public FakeDynamicPriceRepository $prices;
    public FakeLoadProfileRepository $profiles;
    public FakeTariffRepository $tariffs;

    public function __construct(string $mode = 'indexed_monthly')
    {
        $deltas = [
            'from' => '2026-06-01 00:00:00', 'to' => '2026-06-02 00:00:00',
            'prelev_jour' => 4.0, 'prelev_nuit' => 0.0,
            'injec_jour' => 1.0, 'injec_nuit' => 2.0, 'solar' => 0.0,
        ];
        $this->legacy = new FakeLegacyDailyRepository(
            monthlyDeltas: $deltas,
            monthlyDeltasForMonth: $deltas,
            hourlyImportDeltas: [['hour' => '2026-06-01 10:00:00', 'import_kwh' => 4.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-01 10:00:00', 'import_kwh' => 3.0, 'measurement_resolution_min' => 15],
                ['quarter' => '2026-06-01 10:15:00', 'import_kwh' => 1.0, 'measurement_resolution_min' => 15],
            ],
        );
        $this->prices = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-01 10:00:00' => 0.20],
        );
        for ($hour = 0; $hour < 24; $hour++) {
            foreach ([0, 15, 30, 45] as $minute) {
                $this->prices->quarterPricesBySlot[sprintf('2026-06-01 %02d:%02d:00', $hour, $minute)] = 0.20;
            }
        }
        $this->prices->quarterPricesBySlot['2026-06-01 10:00:00'] = 0.10;
        $this->prices->quarterPricesBySlot['2026-06-01 10:15:00'] = 0.30;
        $this->profiles = new FakeLoadProfileRepository(weights: [
            '2026-06-01 10:00:00' => 1.0, '2026-06-01 10:15:00' => 3.0,
        ]);
        $this->tariffs = new FakeTariffRepository(grid: $this->grid(1, '2026-01-01', null, $mode));
    }

    public function grid(int $id, string $from, ?string $to, string $mode, float $coefficient = 2.0): TariffGrid
    {
        return new TariffGrid(
            id: $id, energyType: 'electricity', name: 'Belpex ' . $id,
            validFrom: new DateTimeImmutable($from),
            validTo: $to === null ? null : new DateTimeImmutable($to),
            lines: [
                'energy_t1' => new TariffLine('energy_t1', 0.40, ComponentKind::EnergyT1),
                'energy_t2' => new TariffLine('energy_t2', 0.30, ComponentKind::EnergyT2),
                'spot_coefficient' => new TariffLine('spot_coefficient', $coefficient, ComponentKind::SpotCoefficient),
                'spot_offset' => new TariffLine('spot_offset', 0.01, ComponentKind::SpotOffset),
                'subscription' => new TariffLine('subscription', 5.0, ComponentKind::FixedMonthly),
                'distribution' => new TariffLine('distribution', 0.03, ComponentKind::PerKwh),
                'tax' => new TariffLine('tax', 0.02, ComponentKind::PerKwh),
                'injection_t1' => new TariffLine('injection_t1', 0.04, ComponentKind::InjectionT1),
                'injection_t2' => new TariffLine('injection_t2', 0.02, ComponentKind::InjectionT2),
            ],
            country: 'BE', vatRate: 6.0, pricingMode: $mode, loadProfileCode: 'RLP0N',
        );
    }

    public function service(bool $enabled = true): CostCalculationService
    {
        return new CostCalculationService(
            legacyRepo: $this->legacy, tariffRepo: $this->tariffs,
            gasRepo: new FakeGasReadingRepository(), calculator: new TariffCalculatorService(),
            dynamicPriceRepo: $this->prices, dynamicEnabled: $enabled,
            supplierMarkupPerKwh: 99.0, loadProfileRepo: $this->profiles,
        );
    }
}
