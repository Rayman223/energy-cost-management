<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TariffGrid;
use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Repository\Contract\TariffRepositoryInterface;
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
    /** Coefficient PCS gaz par défaut (kWh/m³) en l'absence de valeur configurée. */
    private const DEFAULT_PCS = 10.55;

    public function __construct(
        private readonly LegacyDailyRepositoryInterface $legacyRepo,
        private readonly TariffRepositoryInterface $tariffRepo,
        private readonly GasReadingRepositoryInterface $gasRepo,
        private readonly TariffCalculatorService $calculator,
        private readonly GasMonthInterpolator $gasInterpolator = new GasMonthInterpolator(),
    ) {
    }

    /**
     * Estimate electricity cost for the current calendar month.
     * Uses delta between first reading of the month and the latest available reading.
     *
     * @return array<string, mixed>
     */
    public function estimateCurrentMonthElectricity(): array
    {
        $deltas = $this->legacyRepo->getMonthlyDeltas();

        if (empty($deltas)) {
            return ['available' => false, 'reason' => 'No data for current month'];
        }

        $from = new DateTimeImmutable($deltas['from']);
        $to   = new DateTimeImmutable($deltas['to']);
        $days = $this->computeDays($from, $to);

        $tariff = $this->tariffRepo->findActiveGrid('electricity', $to);
        if ($tariff === null) {
            return ['available' => false, 'reason' => 'No active electricity tariff configured'];
        }

        return $this->buildElectricityResponse($deltas, $tariff, $days);
    }

    /**
     * Estimate electricity cost for any given calendar month (year + month).
     *
     * @return array<string, mixed>
     */
    public function estimateMonthElectricity(int $year, int $month): array
    {
        $deltas = $this->legacyRepo->getMonthlyDeltasForMonth($year, $month);

        if (empty($deltas)) {
            return ['available' => false, 'reason' => sprintf('No data for %04d-%02d', $year, $month)];
        }

        $from = new DateTimeImmutable($deltas['from']);
        $to   = new DateTimeImmutable($deltas['to']);
        $days = $this->computeDays($from, $to);

        $tariff = $this->tariffRepo->findActiveGrid('electricity', $from)
               ?? $this->tariffRepo->findActiveGrid('electricity', $to);

        if ($tariff === null) {
            return ['available' => false, 'reason' => sprintf('No active electricity tariff for %04d-%02d', $year, $month)];
        }

        return $this->buildElectricityResponse($deltas, $tariff, $days);
    }

    /**
     * Estimate gas cost between the two most recent manual readings.
     * Returns ['available' => false] when fewer than two readings exist.
     *
     * @return array<string, mixed>
     */
    public function estimateLastGasPeriod(): array
    {
        $pair = $this->gasRepo->getLastTwoReadings();

        if ($pair['from'] === null || $pair['to'] === null) {
            return ['available' => false, 'reason' => 'Il faut au moins deux relevés gaz.'];
        }

        $from = new DateTimeImmutable($pair['from']['reading_at']);
        $to   = new DateTimeImmutable($pair['to']['reading_at']);
        $days = max(1, (int) $from->setTime(0, 0, 0)->diff($to->setTime(0, 0, 0))->days);

        $tariff = $this->tariffRepo->findActiveGrid('gas', $from)
               ?? $this->tariffRepo->findActiveGrid('gas', $to);

        if ($tariff === null) {
            return ['available' => false, 'reason' => 'Aucun tarif gaz configuré pour cette période.'];
        }

        $pcs = $tariff->pcsCoefficient
            ?? $this->tariffRepo->findMostRecentPcs('gas', $from)
            ?? self::DEFAULT_PCS;

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
     * Estimate gas cost for any given calendar month using LINEAR INTERPOLATION.
     *
     * The two readings bracketing the month (from GasRepository::getTwoReadingsForMonth)
     * may fall on any date — e.g. Aug-31 and Oct-01 for a September month with no
     * reading on Sep-01. We interpolate linearly by Unix timestamp to derive the
     * theoretical index at exactly midnight on the 1st of month M and the 1st of
     * month M+1, then compute the difference as the monthly consumption.
     *
     * Fixed daily costs (abonnement, redevance, …) are prorated over the number of
     * calendar days actually covered by data within the month.
     *
     * @return array<string, mixed>
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

        $fromDt = new DateTimeImmutable($pair['from']['reading_at']);
        $toDt   = new DateTimeImmutable($pair['to']['reading_at']);

        // Interpolation linéaire de la consommation du mois (logique pure, testée
        // indépendamment dans GasMonthInterpolatorTest).
        $interp = $this->gasInterpolator->interpolate(
            $fromDt,
            (float) $pair['from']['counter_m3'],
            $toDt,
            (float) $pair['to']['counter_m3'],
            $year,
            $month,
        );

        if (!$interp->available) {
            return ['available' => false, 'reason' => $interp->reason];
        }

        // ── Tariff & PCS ──────────────────────────────────────────────────────
        $tariff = $this->tariffRepo->findActiveGrid('gas', $fromDt)
               ?? $this->tariffRepo->findActiveGrid('gas', $toDt);

        if ($tariff === null) {
            return [
                'available' => false,
                'reason'    => sprintf('Aucun tarif gaz configuré pour %04d-%02d.', $year, $month),
            ];
        }

        $pcs = $tariff->pcsCoefficient
            ?? $this->tariffRepo->findMostRecentPcs('gas', $fromDt)
            ?? self::DEFAULT_PCS;

        $kWh = $this->calculator->m3ToKwh($interp->monthlyM3, $pcs);

        $breakdown = $this->calculator->calculateGasCost($kWh, $interp->days, $tariff->toTariffArray());

        return [
            'available'           => true,
            // Show the exact reading timestamps used, for transparency
            'period_from'         => $pair['from']['reading_at'],
            'period_to'           => $pair['to']['reading_at'],
            // Effective window within the calendar month
            'month_start'         => $interp->monthStart,
            'month_end'           => $interp->monthEnd,
            'interpolated'        => $interp->interpolated,
            'days'                => $interp->days,
            'calendar_days'       => $interp->calendarDays,
            'is_full_month'       => $interp->isFull,
            'delta_m3'            => round($interp->monthlyM3, 3),
            'kwh'                 => round($kWh, 2),
            'pcs_coefficient'     => $pcs,
            'tariff_name'         => $tariff->name,
            'tariff_rates'        => $tariff->toTariffArray(),
            'cost'                => $breakdown,
        ];
    }

    // ── Helpers privés ─────────────────────────────────────────────────────────

    /**
     * Calcule le nombre de jours pour une période électricité.
     *
     * Si from et to sont dans le même mois-calendrier : nombre de jours entre les
     * deux horodatages + 1 (inclusif), avec un minimum de 1.
     * Si les horodatages chevauchent deux mois (from en fin de mois N, to en mois N+1) :
     * on utilise le nombre total de jours du mois de $from.
     */
    private function computeDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($from->format('Y-m') === $to->format('Y-m')) {
            return max(1, (int) $from->diff($to)->days + 1);
        }

        return (int) $from->format('t');
    }

    /**
     * Construit le tableau de résultat électricité à partir des deltas, du tarif et du nombre de jours.
     * Factorise la logique commune à estimateCurrentMonthElectricity() et estimateMonthElectricity().
     *
     * @param array<string, mixed> $deltas
     * @return array<string, mixed>
     */
    private function buildElectricityResponse(array $deltas, TariffGrid $tariff, int $days): array
    {
        $breakdown = $this->calculator->calculateElectricityCost(
            kwhT1:       $deltas['prelev_jour'] ?? 0.0,
            kwhT2:       $deltas['prelev_nuit'] ?? 0.0,
            kwhExportT1: $deltas['injec_jour']  ?? 0.0,
            kwhExportT2: $deltas['injec_nuit']  ?? 0.0,
            days:        $days,
            tariff:      $tariff->toTariffArray(),
            kwhSolar:    (float) ($deltas['solar'] ?? 0.0),
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
}