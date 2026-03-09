<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Belgian electricity & gas cost calculator.
 *
 * Electricity tariff array keys (all amounts in €):
 *   energy_simple         float  €/kWh  supplier mono-hourly rate (alternative to t1/t2)
 *   energy_t1             float  €/kWh  supplier day rate
 *   energy_t2             float  €/kWh  supplier night rate
 *   subscription          float  €/mois abonnement fournisseur (fixed monthly)
 *   distribution_t1       float  €/kWh  Sibelga day network tariff
 *   distribution_t2       float  €/kWh  Sibelga night network tariff
 *   transport             float  €/kWh  transport tariff (Elia/Sibelga)
 *   management_annual     float  €/an   gestion réseau (fixed annual)
 *   prosumer_annual       float  €/an   taxe prosumer BRUGEL (fixed annual)
 *   excise_duty           float  €/kWh  droit d'accise spécial
 *   energy_contribution   float  €/kWh  contribution énergie
 *   green_contribution    float  €/kWh  contribution verte & cogénération
 *   public_service_annual float  €/an   obligations de service public (fixed annual)
 *   injection_t1          float  €/kWh  injection credit day
 *   injection_t2          float  €/kWh  injection credit night
 *
 * Gas tariff array keys:
 *   energy                float  €/kWh
 *   subscription          float  €/mois
 *   energy_contribution   float  €/kWh
 *   federal_excise        float  €/kWh
 *   distribution          float  €/kWh
 *   distribution_fixed    float  c€/kWh (stored as cents, /100 to get €)
 *   transport             float  €/kWh
 *   meter_reading_annual  float  €/an
 */
final class TariffCalculatorService
{
    private const TVA       = 0.21;
    private const DAYS_YEAR = 365.0;

    /**
     * Calculate the full electricity cost for a given period.
     * Handles all tariff fields defined in tariffs.php.
     */
    public function calculateElectricityCost(
        float $kwhT1,
        float $kwhT2,
        float $kwhExportT1,
        float $kwhExportT2,
        int $days,
        array $tariff
    ): array {
        $totalKwh   = $kwhT1 + $kwhT2;
        // Subscription is a fixed monthly fee: 2.99 €/mois stays 2.99 € whether the month
        // has 28, 29, 30 or 31 days. We round to the nearest whole month.
        $wholeMonths = max(1, round($days / 30.4375));

        // ── Fournisseur ──────────────────────────────────────────────────────
        //$energySimple   = $totalKwh  * ($tariff['energy_simple']  ?? 0.0);
        $energyT1       = $kwhT1       * ($tariff['energy_t1']      ?? 0.0);
        $energyT2       = $kwhT2       * ($tariff['energy_t2']      ?? 0.0);
        $subscription   = $wholeMonths * ($tariff['subscription']   ?? 0.0);

        // ── Distribution (Sibelga) ───────────────────────────────────────────
        $distributionT1   = $kwhT1    * ($tariff['distribution_t1'] ?? 0.0);
        $distributionT2   = $kwhT2    * ($tariff['distribution_t2'] ?? 0.0);
        $transport        = $totalKwh * ($tariff['transport']        ?? 0.0);
        $managementFee    = $days     * (($tariff['management_annual']     ?? 0.0) / self::DAYS_YEAR);

        // ── Taxes & contributions ────────────────────────────────────────────
        $prosumerFee          = $days     * (($tariff['prosumer_annual']       ?? 0.0) / self::DAYS_YEAR);
        $exciseDuty           = $totalKwh * ($tariff['excise_duty']            ?? 0.0);
        $energyContribution   = $totalKwh * ($tariff['energy_contribution']    ?? 0.0);
        $greenContribution    = $totalKwh * ($tariff['green_contribution']     ?? 0.0);
        $publicServiceFee     = $days     * (($tariff['public_service_annual'] ?? 0.0) / self::DAYS_YEAR);

        // ── Injection credits (negative = reduce the bill) ────────────────────
        $injectionT1 = -($kwhExportT1 * ($tariff['injection_t1'] ?? 0.0));
        $injectionT2 = -($kwhExportT2 * ($tariff['injection_t2'] ?? 0.0));

        // All tariff amounts are already TTC (VAT-inclusive).
        // total_ttc = sum of all lines (already includes VAT)
        // htva      = total_ttc / 1.21
        // vat_in    = total_ttc - htva  (the portion that goes to the state)
        $totalTtc = $energyT1
            + $energyT2
            + $subscription
            + $distributionT1
            + $distributionT2
            + $transport
            + $managementFee
            + $prosumerFee
            + $exciseDuty
            + $energyContribution
            + $greenContribution
            + $publicServiceFee
            + $injectionT1
            + $injectionT2;

        $htva        = $totalTtc / (1 + self::TVA);
        $vatIncluded = $totalTtc - $htva;

        return [
            // Fournisseur
            //'energy_simple'         => round($energySimple, 4),
            'energy_t1'             => round($energyT1, 4),
            'energy_t2'             => round($energyT2, 4),
            'subscription'          => round($subscription, 4),
            // Distribution
            'distribution_t1'       => round($distributionT1, 4),
            'distribution_t2'       => round($distributionT2, 4),
            'transport'             => round($transport, 4),
            'management_fee'        => round($managementFee, 4),
            // Taxes
            'prosumer_fee'          => round($prosumerFee, 4),
            'excise_duty'           => round($exciseDuty, 4),
            'energy_contribution'   => round($energyContribution, 7),
            'green_contribution'    => round($greenContribution, 4),
            'public_service_fee'    => round($publicServiceFee, 4),
            // Injection
            'injection_t1'          => round($injectionT1, 4),
            'injection_t2'          => round($injectionT2, 4),
            // Totals
            'total'                 => round($totalTtc, 2),
            'htva'                  => round($htva, 2),
            'vat_included'          => round($vatIncluded, 2),
            'import_kwh'            => round($totalKwh, 3),
            'export_kwh'            => round($kwhExportT1 + $kwhExportT2, 3),
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