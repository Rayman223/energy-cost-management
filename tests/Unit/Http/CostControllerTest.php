<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Http\Controller\CostController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Service\AnnualConsumptionService;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;
use Tests\Fixture\MonthlyComparisonFixture;

/**
 * Vérifie le contrôleur de coûts de bout en bout (Request -> JsonResponse), avec
 * un CostCalculationService réel branché sur les fakes de repository (Phase 0).
 */
final class CostControllerTest extends TestCase
{
    private function controller(FakeLegacyDailyRepository $legacy, FakeTariffRepository $tariff): CostController
    {
        $svc = new CostCalculationService($legacy, $tariff, new FakeGasReadingRepository(), new TariffCalculatorService());

        return new CostController($svc, new AnnualConsumptionService($svc));
    }

    /** @return array<string,mixed> */
    private function deltas(): array
    {
        return [
            'from'        => '2026-06-01 00:00:00',
            'to'          => '2026-06-15 12:00:00',
            'prelev_jour' => 100.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 0.0,
            'injec_nuit'  => 0.0,
            'solar'       => 0.0,
        ];
    }

    private function grid(): TariffGrid
    {
        return new TariffGrid(1, 'electricity', 'Test', new DateTimeImmutable('2026-01-01'), null, [
            'energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
        ]);
    }

    public function testMonthCostHappyPath(): void
    {
        $controller = $this->controller(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->deltas()),
            new FakeTariffRepository(grid: $this->grid()),
        );

        $res = $controller->monthCost(new Request('GET', ['action' => 'month_cost', 'year' => '2026', 'month' => '6'], []));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertTrue($res->data['available']);
        self::assertArrayHasKey('cost', $res->data);
    }

    public function testMonthCostRejectsOutOfRangeYear(): void
    {
        $controller = $this->controller(new FakeLegacyDailyRepository(), new FakeTariffRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid year/month');
        $controller->monthCost(new Request('GET', ['action' => 'month_cost', 'year' => '1999', 'month' => '6'], []));
    }

    public function testMonthCostRejectsOutOfRangeMonth(): void
    {
        $controller = $this->controller(new FakeLegacyDailyRepository(), new FakeTariffRepository());

        $this->expectException(ValidationException::class);
        $controller->monthCost(new Request('GET', ['action' => 'month_cost', 'month' => '13'], []));
    }

    public function testAnnualConsumptionReturnsTheThreeEnergies(): void
    {
        $controller = $this->controller(
            new FakeLegacyDailyRepository(deltasBetween: $this->deltas()),
            new FakeTariffRepository(grid: $this->grid()),
        );

        $res = $controller->annualConsumption(new Request('GET', ['action' => 'annual_consumption', 'year' => '2026'], []));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(2026, $res->data['year']);
        self::assertArrayHasKey('electricity', $res->data);
        self::assertArrayHasKey('gas', $res->data);
        self::assertArrayHasKey('water', $res->data);
    }

    public function testAnnualConsumptionRejectsOutOfRangeYear(): void
    {
        $controller = $this->controller(new FakeLegacyDailyRepository(), new FakeTariffRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid year');
        $controller->annualConsumption(new Request('GET', ['action' => 'annual_consumption', 'year' => '2199'], []));
    }

    public function testCostEstimateUnavailableWhenNoData(): void
    {
        $controller = $this->controller(new FakeLegacyDailyRepository(monthlyDeltas: []), new FakeTariffRepository());

        $res = $controller->costEstimate(new Request('GET', ['action' => 'cost_estimate'], []));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertFalse($res->data['available']);
    }

    public function testBothCostActionsExposeRlpContractAndSeparateDynamicSimulation(): void
    {
        $svc = (new MonthlyComparisonFixture())->service();
        $controller = new CostController($svc, new AnnualConsumptionService($svc));
        $responses = [
            $controller->costEstimate(new Request('GET', ['action' => 'cost_estimate'], [])),
            $controller->monthCost(new Request('GET', ['action' => 'month_cost', 'year' => '2026', 'month' => '6'], [])),
        ];
        foreach ($responses as $response) {
            self::assertSame(200, $response->status);
            $data = $response->data;
            self::assertSame('monthly', $data['resolution']);
            self::assertSame('standard_profile', $data['load_weighting']);
            self::assertSame(2.16, $data['energy_dynamic']);
            self::assertTrue($data['dynamic']['is_simulation']);
            self::assertSame('quarter', $data['dynamic']['resolution']);
            self::assertSame(1.31, $data['dynamic']['energy_dynamic']);
        }
    }

    public function testDisabledDynamicSectionIsOmittedFromBothCostActions(): void
    {
        $svc = (new MonthlyComparisonFixture())->service(false);
        $controller = new CostController($svc, new AnnualConsumptionService($svc), dynamicEnabled: false);
        foreach ([
            $controller->costEstimate(new Request('GET', [], [])),
            $controller->monthCost(new Request('GET', ['year' => '2026', 'month' => '6'], [])),
        ] as $response) {
            self::assertTrue($response->data['available']);
            self::assertArrayNotHasKey('dynamic', $response->data);
            self::assertSame('fixed', $response->data['pricing_mode']);
        }
    }
}
