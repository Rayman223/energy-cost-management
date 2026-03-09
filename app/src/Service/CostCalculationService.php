<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\TariffRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Orchestrates cost calculation for a given period using active tariff grids.
 *
 * Data flow:
 *  1. Fetch daily first-values from Data_Dries / Data_Solaire (LegacyDailyRepository).
 *  2. Resolve active tariff grids from DB (TariffRepository).
 *  3. Delegate cost maths to TariffCalculatorService.
 */
final class CostCalculationService
{
    private const DEFAULT_TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly LegacyDailyRepository $legacyRepo,
        private readonly TariffRepository $tariffRepo,
        private readonly GasRepository $gasRepo,
        private readonly TariffCalculatorService $calculator,
    ) {
    }

    /**
     * Estimate electricity cost for the current calendar month.
     * Uses delta between first reading of the month and the latest available reading.
     */
    public function estimateCurrentMonthElectricity(): array
    {
        $deltas = $this->legacyRepo->getMonthlyDeltas();

        if (empty($deltas)) {
            return ['available' => false, 'reason' => 'No data for current month'];
        }

        $from = new DateTimeImmutable($deltas['from']);
        $to   = new DateTimeImmutable($deltas['to']);
        $days = max(1, (int) $from->diff($to)->days + 1);
        $daysInYear = $this->daysInYear((int) $to->format('Y'));

        $tariff = $this->tariffRepo->findActiveGrid('electricity', $to);
        if ($tariff === null) {
            return ['available' => false, 'reason' => 'No active electricity tariff configured'];
        }

        $breakdown = $this->calculator->calculateElectricityCost(
            kwhT1: $deltas['prelev_jour'] ?? 0.0,
            kwhT2: $deltas['prelev_nuit'] ?? 0.0,
            kwhExportT1: $deltas['injec_jour'] ?? 0.0,
            kwhExportT2: $deltas['injec_nuit'] ?? 0.0,
            days: $days,
            daysInYear: $daysInYear,
            tariff: $tariff->toTariffArray(),
        );

        return [
            'available'     => true,
            'period_from'   => $deltas['from'],
            'period_to'     => $deltas['to'],
            'days'          => $days,
            'tariff_name'   => $tariff->name,
            'tariff_rates'  => $tariff->toTariffArray(),
            'deltas'        => $deltas,
            'cost'          => $breakdown,
        ];
    }

    /**
     * Estimate electricity cost for any given calendar month (year + month).
     */
    public function estimateMonthElectricity(int $year, int $month): array
    {
        $deltas = $this->legacyRepo->getMonthlyDeltasForMonth($year, $month);

        if (empty($deltas)) {
            return ['available' => false, 'reason' => "No data for {$year}-{$month}"];
        }

        $to   = new DateTimeImmutable($deltas['to']);
        $days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $daysInYear = $this->daysInYear($year);

        $tariff = $this->tariffRepo->findActiveGrid('electricity', $to);
        if ($tariff === null) {
            return ['available' => false, 'reason' => 'No active electricity tariff configured'];
        }

        $breakdown = $this->calculator->calculateElectricityCost(
            kwhT1: $deltas['prelev_jour'] ?? 0.0,
            kwhT2: $deltas['prelev_nuit'] ?? 0.0,
            kwhExportT1: $deltas['injec_jour'] ?? 0.0,
            kwhExportT2: $deltas['injec_nuit'] ?? 0.0,
            days: $days,
            daysInYear: $daysInYear,
            tariff: $tariff->toTariffArray(),
        );

        return [
            'available'    => true,
            'period_from'  => $deltas['from'],
            'period_to'    => $deltas['to'],
            'days'         => $days,
            'tariff_name'  => $tariff->name,
            'tariff_rates' => $tariff->toTariffArray(),
            'deltas'       => $deltas,
            'cost'         => $breakdown,
        ];
    }

    /**
     * Estimate gas cost between the two most recent manual readings.
     * @param float $pcsCoefficient m³ → kWh conversion factor (default 10.55)
     */
    public function estimateLastGasPeriod(float $pcsCoefficient = 10.55): array
    {
        $pair = $this->gasRepo->getLastTwoReadings();

        if ($pair['from'] === null || $pair['to'] === null) {
            return ['available' => false, 'reason' => 'Need at least two gas readings'];
        }

        $deltaM3 = max(0.0, (float) $pair['to']['counter_m3'] - (float) $pair['from']['counter_m3']);
        $kWh     = $this->calculator->m3ToKwh($deltaM3, $pcsCoefficient);

        $from = new DateTimeImmutable($pair['from']['reading_at']);
        $to   = new DateTimeImmutable($pair['to']['reading_at']);
        $days = max(1, (int) $from->diff($to)->days);

        $tariff = $this->tariffRepo->findActiveGrid('gas', $to);
        if ($tariff === null) {
            return ['available' => false, 'reason' => 'No active gas tariff configured'];
        }

        $breakdown = $this->calculator->calculateGasCost($kWh, $days, $tariff->toTariffArray());

        return [
            'available'       => true,
            'period_from'     => $pair['from']['reading_at'],
            'period_to'       => $pair['to']['reading_at'],
            'days'            => $days,
            'delta_m3'        => round($deltaM3, 3),
            'kwh'             => $kWh,
            'pcs_coefficient' => $pcsCoefficient,
            'tariff_name'     => $tariff->name,
            'cost'            => $breakdown,
        ];
    }

    private function daysInYear(int $year): int
    {
        $timezone = new DateTimeZone(self::DEFAULT_TIMEZONE);
        $date = (new DateTimeImmutable("{$year}-01-01 00:00:00", $timezone));

        return (int) $date->format('L') === 1 ? 366 : 365;
    }
}
