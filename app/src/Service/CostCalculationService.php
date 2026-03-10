<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\TariffRepository;
use DateTimeImmutable;

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
        $sameMonth = ($from->format('Y-m') === $to->format('Y-m'));

        if ($sameMonth) {
            // Mois en cours : $to = aujourd'hui (lecture incomplète), on l'inclut avec +1
            $days = max(1, (int) $from->diff($to)->days + 1);
        } else {
            // Mois passé : $to = première lecture du mois suivant (borne exclusive kWh).
            // Pour les taxes fixes (gestion, prosumer, OSP), on prorate sur le nombre de
            // jours calendaires du mois entier, indépendamment du jour de la première lecture.
            $days = (int) $from->format('t');
        }

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

        $from = new DateTimeImmutable($deltas['from']);
        $to   = new DateTimeImmutable($deltas['to']);
        $sameMonth = ($from->format('Y-m') === $to->format('Y-m'));

        if ($sameMonth) {
            // Mois en cours : $to = aujourd'hui (lecture incomplète), on l'inclut avec +1
            $days = max(1, (int) $from->diff($to)->days + 1);
        } else {
            // Mois passé : $to = première lecture du mois suivant (borne exclusive kWh).
            // Pour les taxes fixes (gestion, prosumer, OSP), on prorate sur le nombre de
            // jours calendaires du mois entier, indépendamment du jour de la première lecture.
            $days = (int) $from->format('t');
        }

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
     */
    public function estimateLastGasPeriod(): array
    {
        $pair = $this->gasRepo->getLastTwoReadings();

        if ($pair['from'] === null || $pair['to'] === null) {
            return ['available' => false, 'reason' => 'Il faut au moins deux relevés gaz.'];
        }

        $from = new DateTimeImmutable($pair['from']['reading_at']);
        $to   = new DateTimeImmutable($pair['to']['reading_at']);
        $days = max(1, (int) $from->setTime(0,0,0)->diff($to->setTime(0,0,0))->days);

        // Look up the tariff active at the START of the period ($from).
        // A "January" tariff (valid_from 01/01, valid_to 31/01) would be missed
        // if we searched with $to = 01/02. Fallback to $to if $from yields nothing.
        $tariff = $this->tariffRepo->findActiveGrid('gas', $from)
               ?? $this->tariffRepo->findActiveGrid('gas', $to);

        if ($tariff === null) {
            return ['available' => false, 'reason' => 'Aucun tarif gaz configuré pour cette période.'];
        }

        // PCS fallback chain:
        // 1. tariff's own pcs_coefficient (set when creating the tariff)
        // 2. most recent prior tariff that has a PCS (e.g. Jan tariff for Feb period)
        // 3. Belgian Sibelga default 10.55 kWh/m³
        $pcs = $tariff->pcsCoefficient
            ?? $this->tariffRepo->findMostRecentPcs('gas', $from)
            ?? 10.55;

        $deltaM3 = max(0.0, (float) $pair['to']['counter_m3'] - (float) $pair['from']['counter_m3']);
        $kWh     = $this->calculator->m3ToKwh($deltaM3, $pcs);

        $breakdown = $this->calculator->calculateGasCost($kWh, $days, $tariff->toTariffArray());

        return [
            'available'       => true,
            'period_from'     => $pair['from']['reading_at'],
            'period_to'       => $pair['to']['reading_at'],
            'days'            => $days,
            'delta_m3'        => round($deltaM3, 3),
            'kwh'             => $kWh,
            'pcs_coefficient' => $pcs,
            'tariff_name'     => $tariff->name,
            'tariff_rates'    => $tariff->toTariffArray(),
            'cost'            => $breakdown,
        ];
    }

    /**
     * Estimate gas cost for any given calendar month (year + month).
     * Uses the two readings that bracket the month (GasRepository::getTwoReadingsForMonth).
     */
    public function estimateMonthGas(int $year, int $month): array
    {
        $pair = $this->gasRepo->getTwoReadingsForMonth($year, $month);

        if ($pair['from'] === null || $pair['to'] === null) {
            return [
                'available' => false,
                'reason'    => 'Aucun relevé disponible pour encadrer cette période.',
            ];
        }

        $from = new DateTimeImmutable($pair['from']['reading_at']);
        $to   = new DateTimeImmutable($pair['to']['reading_at']);
        $days = max(1, (int) $from->setTime(0,0,0)->diff($to->setTime(0,0,0))->days);

        $tariff = $this->tariffRepo->findActiveGrid('gas', $from)
               ?? $this->tariffRepo->findActiveGrid('gas', $to);

        if ($tariff === null) {
            return [
                'available' => false,
                'reason'    => sprintf('Aucun tarif gaz configuré pour %04d-%02d.', $year, $month),
            ];
        }

        $pcs = $tariff->pcsCoefficient
            ?? $this->tariffRepo->findMostRecentPcs('gas', $from)
            ?? 10.55;

        $deltaM3 = max(0.0, (float) $pair['to']['counter_m3'] - (float) $pair['from']['counter_m3']);
        $kWh     = $this->calculator->m3ToKwh($deltaM3, $pcs);

        $breakdown = $this->calculator->calculateGasCost($kWh, $days, $tariff->toTariffArray());

        return [
            'available'       => true,
            'period_from'     => $pair['from']['reading_at'],
            'period_to'       => $pair['to']['reading_at'],
            'days'            => $days,
            'delta_m3'        => round($deltaM3, 3),
            'kwh'             => $kWh,
            'pcs_coefficient' => $pcs,
            'tariff_name'     => $tariff->name,
            'tariff_rates'    => $tariff->toTariffArray(),
            'cost'            => $breakdown,
        ];
    }
}