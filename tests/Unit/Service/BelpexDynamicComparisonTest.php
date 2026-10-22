<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MonthlyComparisonFixture;

final class BelpexDynamicComparisonTest extends TestCase
{
    public function testContractFollowsRlpAndComparisonPricesEachQuarter(): void
    {
        $fixture = new MonthlyComparisonFixture();
        $svc = $fixture->service();
        $contract = $svc->estimateMonthElectricity(2026, 6);
        $comparison = $svc->estimateMonthElectricityComparison(2026, 6);

        // Variable : 4 × (2 × 0,25 × 1,06 + 0,01) = 2,16 €.
        self::assertSame('standard_profile', $contract['load_weighting']);
        self::assertSame('RLP0N', $contract['monthly_prices'][0]['profile_code']);
        self::assertSame(0.25, $contract['monthly_prices'][0]['price_htva']);
        self::assertSame(2.16, $contract['energy_dynamic']);
        self::assertTrue($contract['monthly_partial']);

        // Simulation : (3 × 0,10 + 1 × 0,30) × 2 × 1,06 + 4 × 0,01 = 1,312 €.
        self::assertSame(1.31, $comparison['energy_dynamic']);
        self::assertTrue($comparison['is_simulation']);
        self::assertSame('dynamic_quarter', $comparison['pricing_mode']);
        self::assertSame(['dynamic_quarter'], array_column($comparison['pricing_modes'], 'mode'));
        self::assertSame('quarter', $comparison['resolution']);
        self::assertSame('quarter', $comparison['resolution_requested']);
        self::assertNull($comparison['resolution_fallback']);
        self::assertNull($comparison['load_weighting']);
        self::assertSame([], $comparison['monthly_prices']);
        self::assertSame($contract['formula'], $comparison['formula']);

        // Les lignes non-énergie ne changent pas : distribution, taxes, abonnement et injection.
        $nonEnergy = static fn (array $line): bool => $line['group'] !== 'energy';
        self::assertSame(
            array_values(array_filter($contract['cost']['lines'], $nonEnergy)),
            array_values(array_filter($comparison['cost']['lines'], $nonEnergy)),
        );
        // La voie utilisée par le rapprochement garde le prix contractuel mensuel.
        self::assertSame(2.16, $svc->estimateMonthElectricityDynamic(2026, 6)['energy_dynamic']);
        self::assertSame('monthly', $svc->estimateCurrentMonthElectricityDynamic()['resolution']);
        self::assertSame('indexed_monthly', $fixture->tariffs->grid->pricingMode);
        self::assertSame($comparison, $svc->estimateCurrentMonthElectricityComparison());
        self::assertSame($contract, $svc->estimateCurrentMonthElectricity());
    }

    /** @return iterable<string, array{string}> */
    public static function fallbackReasons(): iterable
    {
        yield 'mesures insuffisantes' => ['readings_not_quarter'];
        yield 'prix insuffisants' => ['no_quarter_prices'];
    }

    #[DataProvider('fallbackReasons')]
    public function testComparisonUsesExistingHourlyFallback(string $reason): void
    {
        $fixture = new MonthlyComparisonFixture();
        if ($reason === 'readings_not_quarter') {
            $fixture->legacy->quarterImportDeltas[0]['measurement_resolution_min'] = 60;
        } else {
            // Ne couvre que 25 % des kWh, malgré la présence de cotations 15 min.
            unset($fixture->prices->quarterPricesBySlot['2026-06-01 10:00:00']);
        }
        $r = $fixture->service()->estimateMonthElectricityComparison(2026, 6);

        self::assertTrue($r['is_simulation']);
        self::assertSame('quarter', $r['resolution_requested']);
        self::assertSame('hourly', $r['resolution']);
        self::assertSame($reason, $r['resolution_fallback']);
        self::assertSame('native_hourly', $r['price_source']);
        // 4 × (2 × 0,20 × 1,06 + 0,01) = 1,736 €.
        self::assertSame(1.74, $r['energy_dynamic']);
    }

    public function testMissingRlpFallsBackToActualLoadThenPlainAverage(): void
    {
        $fixture = new MonthlyComparisonFixture();
        $fixture->profiles->weights = [];
        $svc = $fixture->service();
        $actual = $svc->estimateMonthElectricity(2026, 6);
        self::assertSame('actual_load', $actual['load_weighting']);
        self::assertSame(0.15, $actual['monthly_prices'][0]['price_htva']);
        // Une égalité avec la simulation est possible et n'est jamais forcée à diverger.
        self::assertSame($actual['energy_dynamic'], $svc->estimateMonthElectricityComparison(2026, 6)['energy_dynamic']);

        $fixture->legacy->quarterImportDeltas = [];
        $plain = $svc->estimateMonthElectricity(2026, 6);
        self::assertSame('baseload', $plain['load_weighting']);
        self::assertSame(0.20, $plain['monthly_prices'][0]['price_htva']);
    }

    public function testUnavailableAndDisabledComparisonStillAnnouncesSimulation(): void
    {
        $fixture = new MonthlyComparisonFixture();
        $fixture->prices->quarterPricesBySlot = [];
        $fixture->prices->hourlyPricesByHour = [];
        foreach ([true, false] as $enabled) {
            $svc = $fixture->service($enabled);
            foreach ([$svc->estimateCurrentMonthElectricityComparison(), $svc->estimateMonthElectricityComparison(2026, 6)] as $r) {
                self::assertFalse($r['available']);
                self::assertTrue($r['is_simulation']);
            }
            self::assertTrue($svc->estimateMonthElectricity(2026, 6)['available']);
        }
    }

    /** @return iterable<string, array{string, string, float, bool}> */
    public static function unchangedModes(): iterable
    {
        yield 'fixe simulé à l’heure' => ['fixed', 'hourly', 1.74, true];
        yield 'dynamique horaire' => ['dynamic_hourly', 'hourly', 1.74, false];
        yield 'dynamique 15 min' => ['dynamic_quarter', 'quarter', 1.31, false];
    }

    #[DataProvider('unchangedModes')]
    public function testOtherModesKeepTheirResolution(string $mode, string $resolution, float $amount, bool $simulated): void
    {
        $r = (new MonthlyComparisonFixture($mode))->service()->estimateMonthElectricityComparison(2026, 6);
        self::assertSame($resolution, $r['resolution']);
        self::assertSame($amount, $r['energy_dynamic']);
        self::assertSame($simulated, $r['is_simulation']);
    }

    public function testSimulationIncludesBothMonthlyAndTimeVaryingGrids(): void
    {
        $fixture = new MonthlyComparisonFixture();
        $fixture->legacy->monthlyDeltasForMonth['to'] = '2026-06-03 00:00:00';
        $fixture->tariffs->gridsBetween = [
            $fixture->grid(2, '2026-06-02', null, 'dynamic_quarter', 3.0),
            $fixture->grid(1, '2026-06-01', '2026-06-02', 'indexed_monthly'),
        ];
        $fixture->legacy->quarterImportDeltas[] = [
            'quarter' => '2026-06-02 10:00:00', 'import_kwh' => 2.0, 'measurement_resolution_min' => 15,
        ];
        $fixture->prices->quarterPricesBySlot['2026-06-02 10:00:00'] = 0.50;
        $r = $fixture->service()->estimateMonthElectricityComparison(2026, 6);

        // Premier jour : 1,312 € ; second : 2 × (3 × 0,50 × 1,06 + 0,01) = 3,20 €.
        self::assertSame(4.51, $r['energy_dynamic']);
        self::assertSame(2, $r['dynamic_days']);
        self::assertFalse($r['is_mixed']);
        self::assertTrue($r['is_simulation']);
        self::assertSame(['dynamic_quarter', 'dynamic_quarter'], array_column($r['pricing_modes'], 'mode'));
        self::assertFalse($r['spot_base']['formula_uniform']);
    }

    public function testOneMonthWithoutRlpRemainsVisibleAcrossMultipleMonths(): void
    {
        $fixture = new MonthlyComparisonFixture();
        $fixture->legacy->monthlyDeltasForMonth['to'] = '2026-07-02 00:00:00';
        $fixture->legacy->hourlyImportDeltas[] = ['hour' => '2026-07-01 10:00:00', 'import_kwh' => 4.0];
        $fixture->legacy->quarterImportDeltas[] = [
            'quarter' => '2026-07-01 10:00:00', 'import_kwh' => 4.0, 'measurement_resolution_min' => 15,
        ];
        for ($day = 1; $day <= 30; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $fixture->prices->hourlyPricesByHour[sprintf('2026-06-%02d %02d:00:00', $day, $hour)] = 0.20;
            }
        }
        // Même profil en juin, mais aucun poids RLP en juillet.
        for ($hour = 0; $hour < 24; $hour++) {
            $fixture->prices->hourlyPricesByHour[sprintf('2026-07-01 %02d:00:00', $hour)] = 0.30;
        }
        $r = $fixture->service()->estimatePeriodElectricity(
            new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-07-02'),
        );

        self::assertSame('actual_load', $r['load_weighting']);
        self::assertSame(['standard_profile', 'actual_load'], array_column($r['monthly_prices'], 'weighting'));
        self::assertSame([false, true], array_column($r['monthly_prices'], 'partial'));
        self::assertTrue($r['monthly_partial']);
    }
}
