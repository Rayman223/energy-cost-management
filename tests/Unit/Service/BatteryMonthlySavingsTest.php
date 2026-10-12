<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Repository\Contract\DynamicPriceRepositoryInterface;
use App\Service\BatteryPaybackService;
use App\Service\BatterySavingsService;
use App\Service\CostCalculationService;
use App\Service\DashboardCardsService;
use App\Service\TariffCalculatorService;
use App\View\ViewFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeBatteryReadings;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeLoadProfileRepository;
use Tests\Fake\FakeTariffRepository;

final class BatteryMonthlySavingsTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private function grid(
        string $mode = 'indexed_monthly',
        bool $withEnergy = false,
        float $coefficient = 1.08,
        ?float $offset = 0.03,
        string $from = '2026-01-01',
        ?string $to = null,
        int $id = 1,
        ?string $profile = null,
    ): TariffGrid {
        $lines = [
            'coefficient' => new TariffLine('coefficient', $coefficient, ComponentKind::SpotCoefficient),
            'network' => new TariffLine('network', 0.05, ComponentKind::PerKwh),
            'injection' => new TariffLine('injection', 0.04, ComponentKind::InjectionT1),
            'subscription' => new TariffLine('subscription', 50.0, ComponentKind::FixedMonthly),
        ];
        if ($offset !== null) {
            $lines['offset'] = new TariffLine('offset', $offset, ComponentKind::SpotOffset);
        }
        if ($withEnergy) {
            // Deliberately incompatible with the indexed rate: these must be replaced.
            $lines['flat'] = new TariffLine('flat', 1.0, ComponentKind::EnergyFlat);
            $lines['t1'] = new TariffLine('t1', 2.0, ComponentKind::EnergyT1);
            $lines['t2'] = new TariffLine('t2', 3.0, ComponentKind::EnergyT2);
        }

        return new TariffGrid(
            id: $id, energyType: 'electricity', name: 'Monthly', validFrom: $this->at($from),
            validTo: $to === null ? null : $this->at($to), lines: $lines,
            country: 'BE', vatRate: 6.0, pricingMode: $mode, loadProfileCode: $profile,
        );
    }

    private function battery(int $id = 1, int $pvShare = 100): Battery
    {
        return new Battery(
            id: $id, brand: 'BYD', model: 'HVS', capacityKwh: 10.0,
            commissionedOn: $this->at('2026-01-01'), pvChargeShare: $pvShare,
            dischargeProfile: BatteryDischargeProfile::T1,
        );
    }

    /** @return array<string, float> */
    private function prices(string $end = '2026-02-01', float $rate = 0.20): array
    {
        $prices = [];
        for ($at = $this->at('2026-01-01'); $at < $this->at($end); $at = $at->modify('+1 hour')) {
            $prices[$at->format('Y-m-d H:i:s')] = $rate;
        }
        return $prices;
    }

    /**
     * @param list<TariffGrid> $grids
     * @return array{BatterySavingsService, CostCalculationService, FakeLegacyDailyRepository}
     */
    private function services(
        array $grids,
        ?DynamicPriceRepositoryInterface $prices = null,
        bool $enabled = true,
        ?FakeLegacyDailyRepository $legacy = null,
        ?FakeLoadProfileRepository $profiles = null,
        float $markup = 0.0,
    ): array {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $grids;
        $legacy ??= new FakeLegacyDailyRepository();
        $cost = new CostCalculationService(
            legacyRepo: $legacy, tariffRepo: $tariffs, gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(), dynamicPriceRepo: $prices,
            dynamicEnabled: $enabled, supplierMarkupPerKwh: $markup, loadProfileRepo: $profiles,
        );

        return [new BatterySavingsService($tariffs, $legacy, costService: $cost), $cost, $legacy];
    }

    private function readings(string $end = '2026-02-01'): FakeBatteryReadings
    {
        return new FakeBatteryReadings(
            ['2026-01-01' => 0.0, $end => 120.0],
            ['2026-01-01' => 0.0, $end => 100.0],
        );
    }

    /** @return array<string, mixed> */
    private function month(BatterySavingsService $service, ?Battery $battery = null, string $asOf = '2026-02-01'): array
    {
        return $service->fleetMonth(
            [['battery' => $battery ?? $this->battery(), 'readings' => $this->readings()]],
            $this->at('2026-01-01'), $this->at($asOf),
        );
    }

    public static function energyLines(): iterable
    {
        yield 'absent' => [false];
        yield 'present' => [true];
    }

    #[DataProvider('energyLines')]
    public function testIndexedEnergyReplacesSupplierLinesWithoutHourlyReadings(bool $withEnergy): void
    {
        [$service, , $legacy] = $this->services(
            [$this->grid(withEnergy: $withEnergy)],
            new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()),
        );
        $month = $this->month($service);

        self::assertSame(0.30896, $month['avoided_rate']);
        self::assertSame(30.90, $month['gross_savings']);
        self::assertSame(4.80, $month['opportunity_cost']);
        self::assertSame(26.10, $month['net_savings']);
        self::assertSame(0.04, $month['injection_rate']);
        self::assertSame('baseload', $month['monthly_prices'][0]['weighting']);
        self::assertSame(0.20, $month['monthly_prices'][0]['price_htva']);
        self::assertFalse($month['monthly_prices'][0]['coefficient_rejected']);
        self::assertFalse($month['partial']);
        self::assertFalse($month['unsupported_mode']);
        self::assertSame([], $legacy->hourlyWindowsRequested);
    }

    public function testGridRechargeUsesTheSameIndexedRate(): void
    {
        [$service] = $this->services([$this->grid()], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()));
        $month = $this->month($service, $this->battery(pvShare: 25));
        self::assertSame(round(120 * (0.25 * 0.04 + 0.75 * 0.30896), 2), $month['opportunity_cost']);
    }

    public function testNetworkRatesKeepTheirDayNightWeightingAndNoAdditionalVat(): void
    {
        $tariff = ['vat_rate' => 21.0, 'lines' => [
            ['key' => 'energy', 'kind' => 'energy_t1', 'amount' => 99.0, 'label' => null],
            ['key' => 'day', 'kind' => 'per_kwh_t1', 'amount' => 0.10, 'label' => null],
            ['key' => 'night', 'kind' => 'per_kwh_t2', 'amount' => 0.02, 'label' => null],
            ['key' => 'annual', 'kind' => 'fixed_annual', 'amount' => 365.0, 'label' => null],
        ]];
        self::assertEqualsWithDelta(0.29, (new TariffCalculatorService())->avoidedImportRate($tariff, 0.25, 0.25), 1e-9);
        self::assertEqualsWithDelta(0.04, (new TariffCalculatorService())->avoidedImportRate($tariff, 0.25, 0.0), 1e-9);
    }

    public function testMissingOffsetUsesProfileMarkupButExplicitZeroOverridesIt(): void
    {
        foreach (['missing' => 0.07, 'zero' => 0.0] as $case => $expectedOffset) {
            $grid = $this->grid(offset: $case === 'zero' ? 0.0 : null);
            [, $cost] = $this->services([$grid], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()), markup: 0.07);
            $rates = $cost->monthlyIndexedRates($grid, $this->at('2026-01-01'), $this->at('2026-02-01'));
            self::assertEqualsWithDelta(0.20 * 1.08 * 1.06 + $expectedOffset, $rates['2026-01']['energy_rate_ttc'], 1e-9);
        }
    }

    public function testActualLoadWeightingIsSharedWithTheMonthlyCalculator(): void
    {
        $prices = $this->prices();
        $prices['2026-01-01 00:00:00'] = 0.10;
        $prices['2026-01-01 01:00:00'] = 0.30;
        $legacy = new FakeLegacyDailyRepository(quarterImportDeltas: [
            ['quarter' => '2026-01-01 00:00:00', 'import_kwh' => 1.0, 'native' => true],
            ['quarter' => '2026-01-01 01:00:00', 'import_kwh' => 9.0, 'native' => true],
        ]);
        [$service] = $this->services([$this->grid()], new FakeDynamicPriceRepository(hourlyPricesByHour: $prices), legacy: $legacy);
        $month = $this->month($service);
        self::assertSame('actual_load', $month['monthly_prices'][0]['weighting']);
        self::assertSame(0.28, $month['monthly_prices'][0]['price_htva']);
    }

    public function testStandardProfileWorksWithoutNetworkReadingsAndIsCachedAcrossBatteries(): void
    {
        $prices = $this->prices();
        $prices['2026-01-01 00:00:00'] = 0.10;
        $prices['2026-01-01 01:00:00'] = 0.30;
        $profiles = new FakeLoadProfileRepository(weights: [
            '2026-01-01 00:00:00' => 8.0, '2026-01-01 01:00:00' => 2.0,
        ]);
        [$service] = $this->services([$this->grid(profile: 'RLP0N')], new FakeDynamicPriceRepository(hourlyPricesByHour: $prices), profiles: $profiles);
        $fleet = [
            ['battery' => $this->battery(), 'readings' => $this->readings()],
            ['battery' => $this->battery(2), 'readings' => $this->readings()],
        ];
        $month = $service->fleetMonth($fleet, $this->at('2026-01-01'), $this->at('2026-02-01'));
        self::assertSame(1, count($profiles->calls));
        self::assertSame(['code' => 'RLP0N', 'country' => 'BE', 'resolution' => 15], $profiles->calls[0]);
        self::assertCount(1, $month['monthly_prices']);
        self::assertSame('standard_profile', $month['monthly_prices'][0]['weighting']);
        self::assertSame(0.14, $month['monthly_prices'][0]['price_htva']);
        self::assertSame(200.0, $month['discharge_kwh']);
        self::assertSame(48.06, $month['gross_savings']);

        $balance = $service->balance($fleet, $this->at('2026-02-01'));
        self::assertSame($month, $balance['fleet']['months'][0]);
        self::assertCount(2, $profiles->calls, 'The cache is scoped to a balance call.');
    }

    public function testCurrentPriceIsProvisionalAndExcludedFromThePaybackRate(): void
    {
        [$service, $cost, $legacy] = $this->services([$this->grid()], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices('2026-01-16')));
        $balance = $service->balance(
            [['battery' => $this->battery(), 'readings' => $this->readings('2026-01-16')]],
            $this->at('2026-01-16'),
        );
        $month = $balance['fleet']['months'][0];
        self::assertSame(30.90, $month['gross_savings']);
        self::assertTrue($month['monthly_partial']);
        self::assertTrue($month['partial']);
        $payback = (new BatteryPaybackService())->project($this->battery(), $balance['batteries'][0], $this->at('2026-01-16'));
        self::assertNull($payback['monthly_rate']);
        self::assertSame(30.90, $payback['savings']);

        $cards = (new DashboardCardsService($legacy, $cost))->build([], 2026, 1, $this->at('2026-01-16'), $month);
        $card = array_values(array_filter($cards, static fn (array $card): bool => $card['key'] === 'battery_savings'))[0];
        self::assertSame('battery.price_provisional', $card['note_key']);
    }

    public static function unavailablePrices(): iterable
    {
        yield 'disabled' => [false, true, false];
        yield 'no repository' => [true, false, false];
        yield 'no prices' => [true, true, true];
        yield 'too sparse' => [true, true, false, true];
    }

    #[DataProvider('unavailablePrices')]
    public function testMissingMonthlyPricesLeaveAmountsAbsent(bool $enabled, bool $withRepo, bool $empty, bool $sparse = false): void
    {
        $prices = $empty ? [] : ($sparse ? $this->prices('2026-01-02') : $this->prices());
        [$service] = $this->services(
            [$this->grid(withEnergy: true)],
            $withRepo ? new FakeDynamicPriceRepository(hourlyPricesByHour: $prices) : null,
            $enabled,
        );
        $balance = $service->balance([['battery' => $this->battery(), 'readings' => $this->readings()]], $this->at('2026-02-01'));
        $month = $balance['fleet']['months'][0];
        self::assertNull($month['gross_savings']);
        self::assertNull($month['opportunity_cost']);
        self::assertNull($month['net_savings']);
        self::assertTrue($month['missing_monthly_price']);
        self::assertFalse($month['unsupported_mode']);
        self::assertFalse($month['no_tariff']);
        self::assertSame(0.8333, $month['efficiency']);
        self::assertTrue($balance['fleet']['has_missing_monthly_prices']);
    }

    public function testEachMonthlyGridUsesItsOwnFormula(): void
    {
        [$service] = $this->services([
            $this->grid(to: '2026-01-16'),
            $this->grid(coefficient: 2.0, from: '2026-01-16', id: 2),
        ], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()));
        $month = $this->month($service);
        $expected = (0.30896 * 15 + (0.20 * 2 * 1.06 + 0.03 + 0.05) * 16) / 31;
        self::assertEqualsWithDelta($expected, $month['avoided_rate'], 1e-6);
        self::assertSame(round(100 * $expected, 2), $month['gross_savings']);
        self::assertSame([1, 2], array_column($month['monthly_prices'], 'grid_id'));
    }

    public function testFixedAndMonthlySegmentsCanBeValuedTogether(): void
    {
        [$service] = $this->services([
            $this->grid(mode: 'fixed', withEnergy: true, to: '2026-01-16'),
            $this->grid(from: '2026-01-16', id: 2),
        ], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()));
        $month = $this->month($service);
        self::assertEqualsWithDelta((3.05 * 15 + 0.30896 * 16) / 31, $month['avoided_rate'], 1e-6);
        self::assertFalse($month['unsupported_mode']);
        self::assertCount(1, $month['monthly_prices']);
    }

    public function testOneTimeVaryingSegmentStillDisablesTheWholeMonth(): void
    {
        foreach (['dynamic_hourly', 'dynamic_quarter'] as $mode) {
            [$service] = $this->services([
                $this->grid(to: '2026-01-16'), $this->grid(mode: $mode, from: '2026-01-16', id: 2),
            ], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()));
            $month = $this->month($service);
            self::assertTrue($month['unsupported_mode']);
            self::assertNull($month['gross_savings']);
            self::assertFalse($month['missing_monthly_price']);
        }
    }

    public function testNegativeIndexedRatesRemainNegative(): void
    {
        [$service, $cost, $legacy] = $this->services([$this->grid(coefficient: 1.0, offset: 0.0)], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices(rate: -0.20)));
        $month = $this->month($service);
        self::assertSame(-0.162, $month['avoided_rate']);
        self::assertSame(-16.20, $month['gross_savings']);

        $cards = (new DashboardCardsService($legacy, $cost))->build([], 2026, 1, $this->at('2026-02-01'), $month);
        $savings = array_values(array_filter($cards, static fn (array $card): bool => $card['key'] === 'battery_savings'));
        self::assertCount(1, $savings, 'A monetary loss must remain visible.');
        self::assertSame(-16.20, $savings[0]['value']);
        self::assertSame('amber', $savings[0]['tone']);
    }

    public function testRejectedFormulaCoefficientRemainsVisibleInBatteryResults(): void
    {
        foreach ([0.0, -1.0, 108.0] as $coefficient) {
            $grid = $this->grid(coefficient: $coefficient);
            [$service, $cost, $legacy] = $this->services([$grid], new FakeDynamicPriceRepository(hourlyPricesByHour: $this->prices()));
            $rates = $cost->monthlyIndexedRates($grid, $this->at('2026-01-01'), $this->at('2026-02-01'));
            self::assertTrue($rates['2026-01']['coefficient_rejected'] ?? false);
            $month = $this->month($service);
            self::assertSame(0.292, $month['avoided_rate']);
            self::assertTrue($month['monthly_prices'][0]['coefficient_rejected'] ?? false);
            $cards = (new DashboardCardsService($legacy, $cost))->build([], 2026, 1, $this->at('2026-02-01'), $month);
            $savings = array_values(array_filter($cards, static fn (array $card): bool => $card['key'] === 'battery_savings'));
            self::assertSame('battery.formula_rejected', $savings[0]['warning_key'] ?? null);
            self::assertSame('battery.price_estimated', $savings[0]['note_key']);
        }
    }

    public function testPricesAreRequestedForTheCivilMonthAndStopAtAsOf(): void
    {
        $repo = new class($this->prices('2026-03-01')) implements DynamicPriceRepositoryInterface {
            public array $windows = [];

            public function __construct(private array $prices) {}

            public function getHourlyPrices(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                $this->windows[] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
                return array_filter($this->prices, static fn (float $price, string $slot): bool =>
                    $slot >= $from->format('Y-m-d H:i:s') && $slot < $to->format('Y-m-d H:i:s'), ARRAY_FILTER_USE_BOTH);
            }

            public function getQuarterPrices(DateTimeImmutable $from, DateTimeImmutable $to): array { return []; }
            public function getAveragePriceByHour(DateTimeImmutable $from, DateTimeImmutable $to): array { return []; }
            public function latestPeriodEnd(): ?DateTimeImmutable { return null; }
        };
        [$service] = $this->services([$this->grid()], $repo);
        $fleet = [['battery' => $this->battery(), 'readings' => new FakeBatteryReadings(
            ['2026-01-10' => 0.0, '2026-02-10' => 120.0],
            ['2026-01-10' => 0.0, '2026-02-10' => 100.0],
        )]];
        $balance = $service->balance($fleet, $this->at('2026-02-15'));
        self::assertSame([
            ['2026-01-01 00:00:00', '2026-02-01 00:00:00'],
            ['2026-02-01 00:00:00', '2026-02-15 00:00:00'],
        ], $repo->windows);
        self::assertFalse($balance['fleet']['months'][0]['monthly_partial']);
        self::assertTrue($balance['fleet']['months'][1]['monthly_partial']);
    }

    public function testEachCivilMonthReceivesItsOwnPrice(): void
    {
        $prices = $this->prices('2026-03-01');
        foreach ($prices as $slot => $price) {
            if (str_starts_with($slot, '2026-02')) {
                $prices[$slot] = 0.30;
            }
        }
        $grid = $this->grid();
        [, $cost] = $this->services([$grid], new FakeDynamicPriceRepository(hourlyPricesByHour: $prices));
        $rates = $cost->monthlyIndexedRates($grid, $this->at('2026-01-01'), $this->at('2026-03-01'));
        self::assertSame(['2026-01', '2026-02'], array_keys($rates));
        self::assertEqualsWithDelta(0.20, $rates['2026-01']['price']->priceHtva, 1e-9);
        self::assertEqualsWithDelta(0.30, $rates['2026-02']['price']->priceHtva, 1e-9);
    }

    public function testNoPriceDependencyDoesNotSilentlyUseSupplierEnergy(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [$this->grid(withEnergy: true)];
        $month = $this->month(new BatterySavingsService($tariffs, new FakeLegacyDailyRepository()));
        self::assertTrue($month['missing_monthly_price']);
        self::assertNull($month['avoided_rate']);
        self::assertNull($month['gross_savings']);
    }

    public static function locales(): iterable
    {
        foreach (['fr', 'en', 'de', 'nl'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testPagesExplainEstimatedProvisionalAndMissingPrices(string $locale): void
    {
        $catalog = require dirname(__DIR__, 3) . '/app/translations/' . $locale . '.php';
        $view = ViewFactory::create(dirname(__DIR__, 3) . '/app/templates', $locale);
        foreach (['estimated', 'provisional', 'missing', 'rejected', 'negative'] as $case) {
            $end = $case === 'provisional' ? '2026-01-16' : '2026-02-01';
            [$service, $cost, $legacy] = $this->services(
                [$this->grid(coefficient: $case === 'rejected' ? 108.0 : 1.08)],
                new FakeDynamicPriceRepository(hourlyPricesByHour: $case === 'missing' ? [] : $this->prices($end, $case === 'negative' ? -0.20 : 0.20)),
            );
            $balance = $service->balance([['battery' => $this->battery(), 'readings' => $this->readings($end)]], $this->at($end));
            $month = $balance['fleet']['months'][0];
            $html = $view->render('batteries', [
                'balance' => $balance, 'batteries' => [$this->battery()], 'paybacks' => [],
                'error' => null, 'success' => null, 'editing' => null, 'profiles' => BatteryDischargeProfile::cases(),
                'today' => $this->at($end), 'available' => ['fr', 'en', 'de', 'nl'], 'currency' => 'EUR',
                'maxCapacity' => Battery::MAX_CAPACITY_KWH, 'maxPrice' => Battery::MAX_PURCHASE_PRICE,
                'maxCycles' => Battery::MAX_RATED_CYCLES,
            ]);
            $key = match ($case) {
                'estimated', 'negative' => 'battery.weighting_baseload',
                'provisional' => 'battery.price_provisional',
                'missing' => 'battery.month_no_price',
                'rejected' => 'battery.formula_rejected',
            };
            self::assertStringContainsString(htmlspecialchars($catalog[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            self::assertStringContainsString(htmlspecialchars($catalog['battery.injection_fixed'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            self::assertStringNotContainsString($catalog['battery.month_dynamic'], $html);

            $cards = (new DashboardCardsService($legacy, $cost))->build([], 2026, 1, $this->at($end), $month);
            $html = $view->render('dashboard', [
                'cards' => $cards, 'dbError' => null, 'deltas' => null,
                'available' => ['fr', 'en', 'de', 'nl'], 'initYear' => 2026, 'initMonth' => 1,
                'gasInitYear' => 2026, 'gasInitMonth' => 1, 'waterInitYear' => 2026, 'waterInitMonth' => 1,
            ]);
            if ($case === 'missing') {
                self::assertSame([], array_filter($cards, static fn (array $card): bool => $card['key'] === 'battery_savings'));
            } else {
                $key = $case === 'provisional' ? 'battery.price_provisional' : 'battery.price_estimated';
                self::assertStringContainsString(htmlspecialchars($catalog[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
                if ($case === 'rejected') {
                    self::assertStringContainsString(htmlspecialchars($catalog['battery.formula_rejected'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
                } else {
                    self::assertStringNotContainsString(htmlspecialchars($catalog['battery.formula_rejected'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
                }
                if ($case === 'negative') {
                    self::assertStringContainsString('class="card amber"', $html);
                }
            }
        }
    }
}
