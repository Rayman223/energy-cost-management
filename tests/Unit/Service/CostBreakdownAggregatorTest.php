<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\CostBreakdownAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Agrégation des décomptes de coût de sous-périodes tarifaires (#196).
 */
final class CostBreakdownAggregatorTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function breakdown(array $lines, float $total, float $htva, float $vatRate = 21.0): array
    {
        return [
            'lines'        => $lines,
            'total'        => $total,
            'htva'         => $htva,
            'vat_included' => round($total - $htva, 2),
            'vat_rate'     => $vatRate,
            'energy_total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $key, string $kind, float $quantity, float $rate): array
    {
        return [
            'key'      => $key,
            'kind'     => $kind,
            'group'    => 'energy',
            'label'    => null,
            'quantity' => $quantity,
            'unit'     => '€/kWh',
            'rate'     => $rate,
            'amount'   => $quantity * $rate,
        ];
    }

    public function testSingleBreakdownIsReturnedUnchanged(): void
    {
        $only = $this->breakdown([$this->line('energy_t1', 'energy_t1', 100.0, 0.10)], 10.0, 8.26);

        self::assertSame($only, (new CostBreakdownAggregator())->aggregate([$only]));
    }

    public function testLinesAreMergedByKeyWithEffectiveRate(): void
    {
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.10)], 5.0, 4.13),
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.20)], 10.0, 8.26),
        ]);

        self::assertCount(1, $r['lines']);
        self::assertSame('energy_t1', $r['lines'][0]['key']);
        self::assertEqualsWithDelta(100.0, $r['lines'][0]['quantity'], 0.0001);
        self::assertEqualsWithDelta(15.0, $r['lines'][0]['amount'], 0.0001);
        self::assertEqualsWithDelta(0.15, $r['lines'][0]['rate'], 0.0001); // taux effectif pondéré
        self::assertEqualsWithDelta(15.0, $r['total'], 0.0001);
    }

    public function testLinesPresentInOneGridOnlyAreKept(): void
    {
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.10)], 5.0, 4.13),
            $this->breakdown([
                $this->line('energy_t1', 'energy_t1', 50.0, 0.20),
                $this->line('green_certificates', 'per_kwh', 50.0, 0.02),
            ], 11.0, 9.09),
        ]);

        $keys = array_column($r['lines'], 'key');
        self::assertSame(['energy_t1', 'green_certificates'], $keys);
    }

    /**
     * Le taux affiché reste dans l'unité de la grille : pour fixed_annual la
     * quantité est un nombre de JOURS et le taux un €/an — le dériver de
     * amount / quantity donnerait un €/jour.
     */
    public function testAnnualRateStaysAnnual(): void
    {
        $annual = static function (float $rate, int $days): array {
            return [
                'key'      => 'management_annual',
                'kind'     => 'fixed_annual',
                'group'    => 'fixed',
                'label'    => null,
                'quantity' => (float) $days,
                'unit'     => '€/an',
                'rate'     => $rate,
                'amount'   => $days * ($rate / 365.0),
            ];
        };

        // Même forfait annuel des deux côtés → le taux doit rester 73 €/an.
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$annual(73.0, 15)], 3.0, 2.48),
            $this->breakdown([$annual(73.0, 15)], 3.0, 2.48),
        ]);

        self::assertSame('fixed_annual', $r['lines'][0]['kind']);
        self::assertEqualsWithDelta(30.0, $r['lines'][0]['quantity'], 0.0001);
        self::assertEqualsWithDelta(73.0, $r['lines'][0]['rate'], 0.0001);

        // Forfaits différents → moyenne pondérée par les jours, toujours en €/an.
        $mixed = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$annual(73.0, 15)], 3.0, 2.48),
            $this->breakdown([$annual(146.0, 15)], 6.0, 4.96),
        ]);

        self::assertEqualsWithDelta(109.5, $mixed['lines'][0]['rate'], 0.0001);
    }

    /** Les crédits d'injection gardent un taux positif (le montant est négatif). */
    public function testInjectionRateStaysPositive(): void
    {
        $injection = static fn (float $rate): array => [
            'key'      => 'injection_t1',
            'kind'     => 'injection_t1',
            'group'    => 'injection',
            'label'    => null,
            'quantity' => 10.0,
            'unit'     => '€/kWh',
            'rate'     => $rate,
            'amount'   => -(10.0 * $rate),
        ];

        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$injection(0.05)], -0.5, -0.41),
            $this->breakdown([$injection(0.05)], -0.5, -0.41),
        ]);

        self::assertEqualsWithDelta(0.05, $r['lines'][0]['rate'], 0.0001);
        self::assertEqualsWithDelta(-1.0, $r['lines'][0]['amount'], 0.0001);
    }

    public function testSameVatRateIsPreserved(): void
    {
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.10)], 5.0, 4.13),
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.20)], 10.0, 8.26),
        ]);

        self::assertSame(21.0, $r['vat_rate']);
        self::assertEqualsWithDelta(12.39, $r['htva'], 0.0001);
        self::assertEqualsWithDelta(2.61, $r['vat_included'], 0.0001);
    }

    public function testMixedVatRatesYieldNullRateAndSummedHtva(): void
    {
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.10)], 5.0, 4.13, 21.0),
            $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, 0.20)], 10.0, 9.43, 6.0),
        ]);

        self::assertNull($r['vat_rate']);
        self::assertEqualsWithDelta(13.56, $r['htva'], 0.0001);
        self::assertEqualsWithDelta(1.44, $r['vat_included'], 0.0001);
    }

    public function testSolarBlockIsSummedWithEffectiveSavingsRate(): void
    {
        $withSolar = function (float $savings, float $rate): array {
            $b = $this->breakdown([$this->line('energy_t1', 'energy_t1', 50.0, $rate)], 50.0 * $rate, 0.0);

            return $b + [
                'import_kwh'           => 50.0,
                'export_kwh'           => 10.0,
                'solar_produced'       => 40.0,
                'solar_consumed'       => 30.0,
                'self_consumption_pct' => 75.0,
                'solar_savings_rate'   => $rate,
                'solar_savings'        => $savings,
            ];
        };

        $r = (new CostBreakdownAggregator())->aggregate([$withSolar(3.0, 0.10), $withSolar(6.0, 0.20)]);

        self::assertEqualsWithDelta(100.0, $r['import_kwh'], 0.0001);
        self::assertEqualsWithDelta(80.0, $r['solar_produced'], 0.0001);
        self::assertEqualsWithDelta(60.0, $r['solar_consumed'], 0.0001);
        self::assertEqualsWithDelta(75.0, $r['self_consumption_pct'], 0.0001);
        self::assertEqualsWithDelta(9.0, $r['solar_savings'], 0.0001);
        self::assertEqualsWithDelta(0.15, $r['solar_savings_rate'], 0.0001);
    }

    /**
     * Le prix unitaire est recalculé sur les totaux agrégés (#9) : la moyenne des
     * valeurs par sous-période donnerait ici 0.4725 €/kWh, en traitant à égalité
     * une sous-période de 100 kWh et une de 20 kWh.
     */
    public function testNetCostPerKwhIsRecomputedOnTheAggregateNotAveraged(): void
    {
        $injection = static fn (float $amount): array => [
            'key'      => 'injection_t1',
            'kind'     => 'injection_t1',
            'group'    => 'injection',
            'label'    => null,
            'quantity' => 10.0,
            'unit'     => '€/kWh',
            'rate'     => 0.05,
            'amount'   => $amount,
        ];

        $segment = function (float $importKwh, float $total, float $injectionAmount) use ($injection): array {
            $b = $this->breakdown(
                [$this->line('energy_t1', 'energy_t1', $importKwh, 0.10), $injection($injectionAmount)],
                $total,
                round($total / 1.21, 2)
            );

            return $b + [
                'import_kwh'           => $importKwh,
                'export_kwh'           => 10.0,
                'solar_produced'       => null,
                'solar_consumed'       => null,
                'self_consumption_pct' => null,
                'solar_savings_rate'   => null,
                'solar_savings'        => null,
                'net_cost_per_kwh'     => round(($total - $injectionAmount) / $importKwh, 6),
            ];
        };

        $r = (new CostBreakdownAggregator())->aggregate([
            $segment(100.0, 30.0, -2.0),   // 32.00 / 100 = 0.32
            $segment(20.0, 12.0, -0.5),    // 12.50 /  20 = 0.625
        ]);

        // (42.00 + 2.50) / 120 kWh
        self::assertEqualsWithDelta(0.370833, $r['net_cost_per_kwh'], 0.0001);
    }

    /** Gaz et eau n'ont pas d'`import_kwh` : la clé ne doit pas apparaître. */
    public function testNetCostPerKwhIsAbsentForNonElectricity(): void
    {
        $r = (new CostBreakdownAggregator())->aggregate([
            $this->breakdown([$this->line('energy', 'energy_flat', 500.0, 0.05)], 25.0, 20.66) + ['kwh' => 500.0],
            $this->breakdown([$this->line('energy', 'energy_flat', 550.0, 0.10)], 55.0, 45.45) + ['kwh' => 550.0],
        ]);

        self::assertArrayNotHasKey('net_cost_per_kwh', $r);
    }

    public function testGasQuantityIsSummed(): void
    {
        $a = $this->breakdown([$this->line('energy', 'energy_flat', 500.0, 0.05)], 25.0, 20.66) + ['kwh' => 500.0];
        $b = $this->breakdown([$this->line('energy', 'energy_flat', 550.0, 0.10)], 55.0, 45.45) + ['kwh' => 550.0];

        $r = (new CostBreakdownAggregator())->aggregate([$a, $b]);

        self::assertEqualsWithDelta(1050.0, $r['kwh'], 0.0001);
        self::assertEqualsWithDelta(80.0, $r['total'], 0.0001);
    }
}
