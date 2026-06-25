<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\TariffCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cœur de calcul tarifaire (service pur, sans dépendance).
 *
 * Les valeurs attendues sont calculées à la main à partir des règles métier :
 * tous les tarifs sont TTC, total = somme des lignes, htva = total / 1.21,
 * tva = total − htva, forfaits annuels prorata-temporis sur 365 jours,
 * abonnement = forfait mensuel fixe (nb de mois entiers, plancher 1).
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

    public function testElectricityFullBreakdown(): void
    {
        $r = $this->calc->calculateElectricityCost(
            kwhT1: 100.0,
            kwhT2: 50.0,
            kwhExportT1: 20.0,
            kwhExportT2: 10.0,
            days: 30,
            tariff: $this->electricityTariff(),
        );

        // Parts variables fournisseur / distribution
        self::assertEqualsWithDelta(10.0, $r['energy_t1'], self::DELTA);
        self::assertEqualsWithDelta(4.0, $r['energy_t2'], self::DELTA);
        self::assertEqualsWithDelta(5.0, $r['subscription'], self::DELTA);   // 1 mois entier
        self::assertEqualsWithDelta(5.0, $r['distribution_t1'], self::DELTA);
        self::assertEqualsWithDelta(2.0, $r['distribution_t2'], self::DELTA);
        self::assertEqualsWithDelta(1.5, $r['transport'], self::DELTA);

        // Forfaits annuels proratisés (30 jours)
        self::assertEqualsWithDelta(3.0, $r['management_fee'], self::DELTA);
        self::assertEqualsWithDelta(6.0, $r['prosumer_fee'], self::DELTA);
        self::assertEqualsWithDelta(3.0, $r['public_service_fee'], self::DELTA);

        // Taxes proportionnelles
        self::assertEqualsWithDelta(3.0, $r['excise_duty'], self::DELTA);
        self::assertEqualsWithDelta(0.15, $r['energy_contribution'], self::DELTA);
        self::assertEqualsWithDelta(0.45, $r['green_contribution'], self::DELTA);

        // Crédits d'injection : négatifs (réduisent la facture)
        self::assertEqualsWithDelta(-1.0, $r['injection_t1'], self::DELTA);
        self::assertEqualsWithDelta(-0.4, $r['injection_t2'], self::DELTA);

        // Totaux : 41.70 TTC, split TVA 21 %
        self::assertEqualsWithDelta(41.70, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(34.46, $r['htva'], 0.01);
        self::assertEqualsWithDelta(7.24, $r['vat_included'], 0.01);

        self::assertEqualsWithDelta(150.0, $r['import_kwh'], self::DELTA);
        self::assertEqualsWithDelta(30.0, $r['export_kwh'], self::DELTA);
    }

    public function testInjectionCreditsLowerTheTotal(): void
    {
        $tariff = $this->electricityTariff();

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
            tariff: $this->electricityTariff(),
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
        $r = $this->calc->calculateElectricityCost(100.0, 50.0, 0.0, 0.0, 30, $this->electricityTariff());

        self::assertNull($r['solar_produced']);
        self::assertNull($r['solar_consumed']);
        self::assertNull($r['self_consumption_pct']);
        self::assertNull($r['solar_savings_rate']);
        self::assertNull($r['solar_savings']);
    }

    public function testEmptyTariffYieldsZeroTotal(): void
    {
        $r = $this->calc->calculateElectricityCost(100.0, 50.0, 20.0, 10.0, 30, []);

        self::assertEqualsWithDelta(0.0, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(0.0, $r['htva'], self::DELTA);
        self::assertEqualsWithDelta(0.0, $r['vat_included'], self::DELTA);
    }

    public function testSubscriptionFlooredToOneWholeMonth(): void
    {
        // days = 0 -> nb de mois entiers plancher à 1 -> abonnement plein
        $r = $this->calc->calculateElectricityCost(0.0, 0.0, 0.0, 0.0, 0, $this->electricityTariff());
        self::assertEqualsWithDelta(5.0, $r['subscription'], self::DELTA);
        // forfaits annuels proratisés sur 0 jour -> 0
        self::assertEqualsWithDelta(0.0, $r['management_fee'], self::DELTA);
    }

    public function testSubscriptionScalesWithWholeMonths(): void
    {
        // 60 jours -> round(60/30.4375) = 2 mois entiers -> 2 × 5.00 = 10.00
        $r = $this->calc->calculateElectricityCost(0.0, 0.0, 0.0, 0.0, 60, $this->electricityTariff());
        self::assertEqualsWithDelta(10.0, $r['subscription'], self::DELTA);
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

        $r = $this->calc->calculateGasCost(1000.0, 30, $tariff);

        self::assertEqualsWithDelta(50.0, $r['energy'], self::DELTA);
        self::assertEqualsWithDelta(3.0, $r['subscription'], self::DELTA);
        self::assertEqualsWithDelta(20.0, $r['distribution'], self::DELTA);
        self::assertEqualsWithDelta(6.0, $r['distribution_fixed'], self::DELTA);
        self::assertEqualsWithDelta(10.0, $r['transport'], self::DELTA);
        self::assertEqualsWithDelta(3.0, $r['meter_reading'], self::DELTA);
        self::assertEqualsWithDelta(1.0, $r['energy_contribution'], self::DELTA);
        self::assertEqualsWithDelta(2.0, $r['federal_excise'], self::DELTA);
        self::assertEqualsWithDelta(3.0, $r['connection_fee'], self::DELTA);
        self::assertEqualsWithDelta(3.0, $r['public_service'], self::DELTA);

        // total = 101.00 TTC, split TVA
        self::assertEqualsWithDelta(101.0, $r['total'], self::DELTA);
        self::assertEqualsWithDelta(83.47, $r['htva'], 0.01);
        self::assertEqualsWithDelta(17.53, $r['vat_included'], 0.01);
        self::assertEqualsWithDelta(1000.0, $r['kwh'], self::DELTA);
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
