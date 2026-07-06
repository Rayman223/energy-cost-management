<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\TariffLineCatalog;
use App\Service\TariffCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cœur de calcul tarifaire (service pur, sans dépendance).
 *
 * Les valeurs attendues sont calculées à la main à partir des règles métier :
 * tous les tarifs sont TTC, total = somme des lignes, htva = total / 1.21,
 * tva = total − htva, forfaits annuels prorata-temporis sur 365 jours,
 * abonnement = forfait mensuel fixe (nb de mois entiers, plancher 1).
 *
 * Le moteur est générique : la grille est une liste de lignes typées (kind) et
 * le détail est renvoyé sous `lines` (par clé), plutôt qu'en clés plates.
 */
final class TariffCalculatorServiceTest extends TestCase
{
    private const DELTA = 0.0001;

    private TariffCalculatorService $calc;

    protected function setUp(): void
    {
        $this->calc = new TariffCalculatorService();
    }

    /** @return array<string,float> */
    private function electricityTariff(): array
    {
        return [
            'energy_t1'             => 0.10,
            'energy_t2'             => 0.08,
            'subscription'          => 5.00,   // €/mois
            'distribution_t1'       => 0.05,
            'distribution_t2'       => 0.04,
            'transport'             => 0.01,
            'management_annual'     => 36.5,   // €/an  -> 0.1 €/jour
            'prosumer_annual'       => 73.0,   // €/an  -> 0.2 €/jour
            'excise_duty'           => 0.02,
            'energy_contribution'   => 0.001,
            'green_contribution'    => 0.003,
            'public_service_annual' => 36.5,   // €/an  -> 0.1 €/jour
            'injection_t1'          => 0.05,
            'injection_t2'          => 0.04,
        ];
    }

    /**
     * Convertit une grille plate `clé => montant` au format attendu par le
     * moteur (vat_rate + lignes typées via TariffLineCatalog::kindFor).
     *
     * @param  array<string,float> $flat
     * @return array{vat_rate: float, lines: list<array{key: string, kind: string, amount: float, label: null}>}
     */
    private function calcTariff(array $flat, string $energy = 'electricity', float $vat = 21.0): array
    {
        $lines = [];
        foreach ($flat as $key => $amount) {
            $lines[] = [
                'key'    => $key,
                'kind'   => TariffLineCatalog::kindFor($energy, $key)->value,
                'amount' => (float) $amount,
                'label'  => null,
            ];
        }

        return ['vat_rate' => $vat, 'lines' => $lines];
    }

    /**
     * Montant calculé d'une ligne du breakdown, par clé.
     *
     * @param array<string,mixed> $result
     */
    private function lineAmount(array $result, string $key): ?float
    {
        foreach ($result['lines'] as $line) {
            if ($line['key'] === $key) {
                return (float) $line['amount'];
            }
        }

        return null;
    }

    public function testElectricityFullBreakdown(): void
    {
        $r = $this->calc->calculateElectricityCost(
            kwhT1: 100.0,
            kwhT2: 50.0,
            kwhExportT1: 20.0,
            kwhExportT2: 10.0,
            days: 30,
            tariff: $this->calcTariff($this->electricityTariff()),
        );

        // Parts variables fournisseur / distribution
        self::assertEqualsWithDelta(10.0, $this->lineAmount($r, 'energy_t1'), self::DELTA);
        self::assertEqualsWithDelta(4.0, $this->lineAmount($r, 'energy_t2'), self::DELTA);
        self::assertEqualsWithDelta(5.0, $this->lineAmount($r, 'subscription'), self::DELTA);   // 1 mois entier
        self::assertEqualsWithDelta(5.0, $this->lineAmount($r, 'distribution_t1'), self::DELTA);
        self::assertEqualsWithDelta(2.0, $this->lineAmount($r, 'distribution_t2'), self::DELTA);
        self::assertEqualsWithDelta(1.5, $this->lineAmount($r, 'transport'), self::DELTA);

        // Forfaits annuels proratisés (30 jours)
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'management_annual'), self::DELTA);
        self::assertEqualsWithDelta(6.0, $this->lineAmount($r, 'prosumer_annual'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'public_service_annual'), self::DELTA);

        // Taxes proportionnelles
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'excise_duty'), self::DELTA);
        self::assertEqualsWithDelta(0.15, $this->lineAmount($r, 'energy_contribution'), self::DELTA);
        self::assertEqualsWithDelta(0.45, $this->lineAmount($r, 'green_contribution'), self::DELTA);

        // Crédits d'injection : négatifs (réduisent la facture)
        self::assertEqualsWithDelta(-1.0, $this->lineAmount($r, 'injection_t1'), self::DELTA);
        self::assertEqualsWithDelta(-0.4, $this->lineAmount($r, 'injection_t2'), self::DELTA);

        // Totaux : 41.70 TTC, split TVA 21 %
        self::assertEqualsWithDelta(41.70, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(34.46, $r['htva'], 0.01);
        self::assertEqualsWithDelta(7.24, $r['vat_included'], 0.01);

        self::assertEqualsWithDelta(150.0, $r['import_kwh'], self::DELTA);
        self::assertEqualsWithDelta(30.0, $r['export_kwh'], self::DELTA);
    }

    public function testInjectionCreditsLowerTheTotal(): void
    {
        $tariff = $this->calcTariff($this->electricityTariff());

        $withoutExport = $this->calc->calculateElectricityCost(100.0, 50.0, 0.0, 0.0, 30, $tariff);
        $withExport    = $this->calc->calculateElectricityCost(100.0, 50.0, 20.0, 10.0, 30, $tariff);

        // 20 kWh @0.05 + 10 kWh @0.04 = 1.40 € de crédit
        self::assertEqualsWithDelta($withoutExport['total'] - 1.40, $withExport['total'], self::DELTA);
    }

    public function testSolarSelfConsumption(): void
    {
        $r = $this->calc->calculateElectricityCost(
            kwhT1: 100.0,
            kwhT2: 50.0,
            kwhExportT1: 20.0,
            kwhExportT2: 10.0,
            days: 30,
            tariff: $this->calcTariff($this->electricityTariff()),
            kwhSolar: 100.0,
        );

        // consommé = production − injection = 100 − 30 = 70
        self::assertEqualsWithDelta(100.0, $r['solar_produced'], self::DELTA);
        self::assertEqualsWithDelta(70.0, $r['solar_consumed'], self::DELTA);
        self::assertEqualsWithDelta(70.0, $r['self_consumption_pct'], self::DELTA);

        // taux = energy_t1 + distribution_t1 + transport + excise + energy_contrib + green
        //      = 0.10 + 0.05 + 0.01 + 0.02 + 0.001 + 0.003 = 0.184
        self::assertEqualsWithDelta(0.184, $r['solar_savings_rate'], self::DELTA);
        self::assertEqualsWithDelta(70.0 * 0.184, $r['solar_savings'], self::DELTA);
    }

    public function testNoSolarDataYieldsNullFields(): void
    {
        $r = $this->calc->calculateElectricityCost(100.0, 50.0, 0.0, 0.0, 30, $this->calcTariff($this->electricityTariff()));

        self::assertNull($r['solar_produced']);
        self::assertNull($r['solar_consumed']);
        self::assertNull($r['self_consumption_pct']);
        self::assertNull($r['solar_savings_rate']);
        self::assertNull($r['solar_savings']);
    }

    public function testEmptyTariffYieldsZeroTotal(): void
    {
        $r = $this->calc->calculateElectricityCost(100.0, 50.0, 20.0, 10.0, 30, ['vat_rate' => 21.0, 'lines' => []]);

        self::assertEqualsWithDelta(0.0, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(0.0, $r['htva'], self::DELTA);
        self::assertEqualsWithDelta(0.0, $r['vat_included'], self::DELTA);
    }

    public function testSubscriptionFlooredToOneWholeMonth(): void
    {
        // days = 0 -> nb de mois entiers plancher à 1 -> abonnement plein
        $r = $this->calc->calculateElectricityCost(0.0, 0.0, 0.0, 0.0, 0, $this->calcTariff($this->electricityTariff()));
        self::assertEqualsWithDelta(5.0, $this->lineAmount($r, 'subscription'), self::DELTA);
        // forfaits annuels proratisés sur 0 jour -> 0
        self::assertEqualsWithDelta(0.0, $this->lineAmount($r, 'management_annual'), self::DELTA);
    }

    public function testSubscriptionScalesWithWholeMonths(): void
    {
        // 60 jours -> round(60/30.4375) = 2 mois entiers -> 2 × 5.00 = 10.00
        $r = $this->calc->calculateElectricityCost(0.0, 0.0, 0.0, 0.0, 60, $this->calcTariff($this->electricityTariff()));
        self::assertEqualsWithDelta(10.0, $this->lineAmount($r, 'subscription'), self::DELTA);
    }

    public function testDynamicEqualsClassicWhenEnergyMatchesT1T2(): void
    {
        $tariff  = $this->calcTariff($this->electricityTariff());
        $classic = $this->calc->calculateElectricityCost(100.0, 50.0, 20.0, 10.0, 30, $tariff, 100.0);

        // Énergie dynamique = exactement la part fournisseur classique (T1+T2).
        $dynEnergy = 100.0 * 0.10 + 50.0 * 0.08; // 14.0
        $dynamic   = $this->calc->calculateElectricityCostDynamic(100.0, 50.0, 20.0, 10.0, 30, $tariff, $dynEnergy, 100.0);

        self::assertSame('dynamic', $dynamic['mode']);
        self::assertEqualsWithDelta(14.0, $this->lineAmount($dynamic, 'energy_dynamic'), self::DELTA);

        // Total, HTVA et tous les composants fixes restent identiques au classique.
        self::assertEqualsWithDelta($classic['total'], $dynamic['total'], self::DELTA);
        self::assertEqualsWithDelta($classic['htva'], $dynamic['htva'], self::DELTA);
        foreach ([
            'subscription', 'distribution_t1', 'distribution_t2', 'transport', 'management_annual',
            'prosumer_annual', 'excise_duty', 'green_contribution', 'public_service_annual',
            'injection_t1', 'injection_t2',
        ] as $k) {
            self::assertEqualsWithDelta($this->lineAmount($classic, $k), $this->lineAmount($dynamic, $k), self::DELTA, $k);
        }
        self::assertEqualsWithDelta($classic['solar_savings'], $dynamic['solar_savings'], self::DELTA);
    }

    public function testDynamicEnergyDrivesTheTotal(): void
    {
        $tariff        = $this->calcTariff($this->electricityTariff());
        $classicEnergy = 100.0 * 0.10 + 50.0 * 0.08; // 14.0

        $base    = $this->calc->calculateElectricityCostDynamic(100.0, 50.0, 20.0, 10.0, 30, $tariff, $classicEnergy);
        $cheaper = $this->calc->calculateElectricityCostDynamic(100.0, 50.0, 20.0, 10.0, 30, $tariff, $classicEnergy / 2);

        // Énergie deux fois moins chère → total réduit d'exactement la moitié de l'énergie.
        self::assertEqualsWithDelta($base['total'] - $classicEnergy / 2, $cheaper['total'], self::DELTA);
        // La part dynamique remplace energy_t1/energy_t2 (absents des lignes dynamiques).
        self::assertNull($this->lineAmount($cheaper, 'energy_t1'));
        self::assertNotNull($this->lineAmount($cheaper, 'energy_dynamic'));
    }

    public function testGasFullBreakdown(): void
    {
        $tariff = [
            'energy'                => 0.05,
            'subscription'          => 3.0,    // €/mois
            'distribution'          => 0.02,
            'distribution_fixed'    => 73.0,   // €/an -> 0.2 €/jour
            'transport'             => 0.01,
            'meter_reading_annual'  => 36.5,   // €/an -> 0.1 €/jour
            'energy_contribution'   => 0.001,
            'federal_excise'        => 0.002,
            'connection_fee_kwh'    => 0.003,
            'public_service_annual' => 36.5,   // €/an -> 0.1 €/jour
        ];

        $r = $this->calc->calculateGasCost(1000.0, 30, $this->calcTariff($tariff, 'gas'));

        self::assertEqualsWithDelta(50.0, $this->lineAmount($r, 'energy'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'subscription'), self::DELTA);
        self::assertEqualsWithDelta(20.0, $this->lineAmount($r, 'distribution'), self::DELTA);
        self::assertEqualsWithDelta(6.0, $this->lineAmount($r, 'distribution_fixed'), self::DELTA);
        self::assertEqualsWithDelta(10.0, $this->lineAmount($r, 'transport'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'meter_reading_annual'), self::DELTA);
        self::assertEqualsWithDelta(1.0, $this->lineAmount($r, 'energy_contribution'), self::DELTA);
        self::assertEqualsWithDelta(2.0, $this->lineAmount($r, 'federal_excise'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'connection_fee_kwh'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'public_service_annual'), self::DELTA);

        // total = 101.00 TTC, split TVA
        self::assertEqualsWithDelta(101.0, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(83.47, $r['htva'], 0.01);
        self::assertEqualsWithDelta(17.53, $r['vat_included'], 0.01);
        self::assertEqualsWithDelta(1000.0, $r['kwh'], self::DELTA);
    }

    public function testWaterBreakdown(): void
    {
        $tariff = [
            'water_supply'        => 2.10,  // €/m³
            'sanitation_communal' => 1.35,  // €/m³
            'meter_rental_annual' => 36.5,  // €/an -> 0.1 €/jour
        ];

        $r = $this->calc->calculateWaterCost(10.0, 30, $this->calcTariff($tariff, 'water'));

        self::assertEqualsWithDelta(21.0, $this->lineAmount($r, 'water_supply'), self::DELTA);
        self::assertEqualsWithDelta(13.5, $this->lineAmount($r, 'sanitation_communal'), self::DELTA);
        self::assertEqualsWithDelta(3.0, $this->lineAmount($r, 'meter_rental_annual'), self::DELTA);
        self::assertEqualsWithDelta(37.5, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(10.0, $r['m3'], self::DELTA);
    }

    public function testM3ToKwhUsesDefaultPcs(): void
    {
        self::assertEqualsWithDelta(105.5, $this->calc->m3ToKwh(10.0), self::DELTA);
    }

    public function testM3ToKwhUsesProvidedPcsAndRoundsTo3Decimals(): void
    {
        self::assertEqualsWithDelta(110.0, $this->calc->m3ToKwh(10.0, 11.0), self::DELTA);
        self::assertEqualsWithDelta(35.163, $this->calc->m3ToKwh(3.333, 10.55), self::DELTA);
    }
}
