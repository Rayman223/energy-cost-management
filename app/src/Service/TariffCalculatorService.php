<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Belgian electricity & gas cost calculator.
 *
 * Tariff array keys (all amounts in €):
 *
 * Electricity:
 *   energy_t1            float  €/kWh  supplier day rate
 *   energy_t2            float  €/kWh  supplier night rate
 *   distribution_t1      float  €/kWh  Sibelga day network tariff
 *   distribution_t2      float  €/kWh  Sibelga night network tariff
 *   distribution_fixed   float  €/day  fixed network fee (abonnement réseau)
 *   federal_contribution float  €/kWh  cotisation fédérale énergie (default 0.0054)
 *   injection_t1         float  €/kWh  injection credit day (can be 0 for prosumers)
 *   injection_t2         float  €/kWh  injection credit night
 *   prosumer_annual      float  €/year annual prosumer capacity fee (taxe prosumer BRUGEL)
 *
 * Gas:
 *   energy               float  €/kWh  supplier gas rate
 *   distribution         float  €/kWh  Sibelga gas network tariff
 *   distribution_fixed   float  €/day  fixed gas network fee
 *   federal_contribution float  €/kWh  default 0.0
 */
final class TariffCalculatorService
{
    private const TVA = 0.21;

    /**
     * Calculate the full electricity cost for a given period.
     *
     * @param float $kwhT1       kWh consumed (day tariff)
     * @param float $kwhT2       kWh consumed (night tariff)
     * @param float $kwhExportT1 kWh injected (day tariff)
     * @param float $kwhExportT2 kWh injected (night tariff)
     * @param int   $days        number of days in the period
     * @param array $tariff      tariff configuration
     *
     * @return array{
     *   energy_t1: float,
     *   energy_t2: float,
     *   distribution_t1: float,
     *   distribution_t2: float,
     *   distribution_fixed: float,
     *   federal_contribution: float,
     *   prosumer_fee: float,
     *   injection_credit: float,
     *   subtotal_ex_vat: float,
     *   vat: float,
     *   total: float,
     *   import_kwh: float,
     *   export_kwh: float,
     * }
     */
    public function calculateElectricityCost(
        float $kwhT1,
        float $kwhT2,
        float $kwhExportT1,
        float $kwhExportT2,
        int $days,
        array $tariff
    ): array {
        $energyCostT1   = $kwhT1 * ($tariff['energy_t1'] ?? 0.0);
        $energyCostT2   = $kwhT2 * ($tariff['energy_t2'] ?? 0.0);

        $distributionT1    = $kwhT1 * ($tariff['distribution_t1'] ?? 0.0);
        $distributionT2    = $kwhT2 * ($tariff['distribution_t2'] ?? 0.0);
        $distributionFixed = $days  * ($tariff['distribution_fixed'] ?? 0.0);

        $federalContribution = ($kwhT1 + $kwhT2) * ($tariff['federal_contribution'] ?? 0.0054);

        // Prosumer fee: annual total prorated per day (BRUGEL / Sibelga Brussels)
        $prosumerFee = $days * (($tariff['prosumer_annual'] ?? 0.0) / 365.0);

        // Injection credit (negative — reduces the bill)
        $injectionCredit = -(
            $kwhExportT1 * ($tariff['injection_t1'] ?? 0.0)
            + $kwhExportT2 * ($tariff['injection_t2'] ?? 0.0)
        );

        $subtotal = $energyCostT1
            + $energyCostT2
            + $distributionT1
            + $distributionT2
            + $distributionFixed
            + $federalContribution
            + $prosumerFee
            + $injectionCredit;

        $vat   = $subtotal * self::TVA;
        $total = $subtotal + $vat;

        return [
            'energy_t1'           => round($energyCostT1, 4),
            'energy_t2'           => round($energyCostT2, 4),
            'distribution_t1'     => round($distributionT1, 4),
            'distribution_t2'     => round($distributionT2, 4),
            'distribution_fixed'  => round($distributionFixed, 4),
            'federal_contribution'=> round($federalContribution, 4),
            'prosumer_fee'        => round($prosumerFee, 4),
            'injection_credit'    => round($injectionCredit, 4),
            'subtotal_ex_vat'     => round($subtotal, 4),
            'vat'                 => round($vat, 4),
            'total'               => round($total, 2),
            'import_kwh'          => round($kwhT1 + $kwhT2, 3),
            'export_kwh'          => round($kwhExportT1 + $kwhExportT2, 3),
        ];
    }

    /**
     * Calculate gas cost (manual index entries).
     *
     * @param float $kwh   consumed kWh (after m³ → kWh conversion)
     * @param int   $days  number of days in the period
     * @param array $tariff
     */
    public function calculateGasCost(float $kwh, int $days, array $tariff): array
    {
        $energy      = $kwh  * ($tariff['energy'] ?? 0.0);
        $distribution = $kwh * ($tariff['distribution'] ?? 0.0);
        $fixed       = $days * ($tariff['distribution_fixed'] ?? 0.0);
        $federal     = $kwh  * ($tariff['federal_contribution'] ?? 0.0);

        $subtotal = $energy + $distribution + $fixed + $federal;
        $vat      = $subtotal * self::TVA;
        $total    = $subtotal + $vat;

        return [
            'energy'               => round($energy, 4),
            'distribution'         => round($distribution, 4),
            'distribution_fixed'   => round($fixed, 4),
            'federal_contribution' => round($federal, 4),
            'subtotal_ex_vat'      => round($subtotal, 4),
            'vat'                  => round($vat, 4),
            'total'                => round($total, 2),
            'kwh'                  => round($kwh, 3),
        ];
    }

    /**
     * Convert gas m³ to kWh using PCS coefficient.
     * Belgian average PCS ≈ 10.55 kWh/m³ (Sibelga reference value, configurable).
     */
    public function m3ToKwh(float $m3, float $pcsCoefficient = 10.55): float
    {
        return round($m3 * $pcsCoefficient, 3);
    }
}
