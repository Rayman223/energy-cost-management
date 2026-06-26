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

        // ── Timestamps (Unix seconds, integer arithmetic) ─────────────────────
        $fromDt = new DateTimeImmutable($pair['from']['reading_at']);
        $toDt   = new DateTimeImmutable($pair['to']['reading_at']);

        $fromTs = $fromDt->getTimestamp();
        $toTs   = $toDt->getTimestamp();
        $totalSecs = $toTs - $fromTs;

        if ($totalSecs <= 0) {
            return [
                'available' => false,
                'reason'    => 'Les deux relevés ont le même horodatage.',
            ];
        }

        // ── Calendar boundaries of the requested month ────────────────────────
        $nextYear    = $month === 12 ? $year + 1 : $year;
        $nextMonth   = $month === 12 ? 1         : $month + 1;

        $monthStartDt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,      $month));
        $monthEndDt   = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $nextYear,  $nextMonth));

        $monthStartTs = $monthStartDt->getTimestamp();
        $monthEndTs   = $monthEndDt->getTimestamp();

        // ── Effective coverage window within the month ────────────────────────
        // Clamp the readings' range to the month's boundaries.
        $effStartTs = max($monthStartTs, $fromTs); // can't go before the first reading
        $effEndTs   = min($monthEndTs,   $toTs);   // can't go beyond the last reading

        if ($effStartTs >= $effEndTs) {
            return [
                'available' => false,
                'reason'    => 'Les relevés ne couvrent pas cette période.',
            ];
        }

        // ── Linear interpolation of the meter index ───────────────────────────
        $totalDeltaM3 = (float) $pair['to']['counter_m3'] - (float) $pair['from']['counter_m3'];

        // Fraction of the inter-reading span that corresponds to the month start/end
        $fracStart = ($effStartTs - $fromTs) / $totalSecs;
        $fracEnd   = ($effEndTs   - $fromTs) / $totalSecs;

        $indexAtEffStart = (float) $pair['from']['counter_m3'] + $totalDeltaM3 * $fracStart;
        $indexAtEffEnd   = (float) $pair['from']['counter_m3'] + $totalDeltaM3 * $fracEnd;

        $monthlyM3 = max(0.0, $indexAtEffEnd - $indexAtEffStart);

        // ── Days for fixed-cost proration ─────────────────────────────────────
        // If the readings fully bracket the month (from ≤ monthStart AND to ≥ monthEnd)
        // use the exact calendar day-count of the month.
        // Otherwise (partial coverage — first/last month in DB, or current month)
        // use the number of days actually covered by data.
        $calendarDays = (int) $monthStartDt->format('t'); // 28/29/30/31
        $coverageDays = (int) round(($effEndTs - $effStartTs) / 86400);
        $isFull       = ($fromTs <= $monthStartTs && $toTs >= $monthEndTs);
        $days         = $isFull ? $calendarDays : max(1, $coverageDays);

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

        $kWh = $this->calculator->m3ToKwh($monthlyM3, $pcs);

        $breakdown = $this->calculator->calculateGasCost($kWh, $days, $tariff->toTariffArray());

        return [
            'available'           => true,
            // Show the exact reading timestamps used, for transparency
            'period_from'         => $pair['from']['reading_at'],
            'period_to'           => $pair['to']['reading_at'],
            // Effective window within the calendar month
            'month_start'         => $monthStartDt->format('Y-m-d H:i:s'),
            'month_end'           => $monthEndDt->format('Y-m-d H:i:s'),
            'interpolated'        => !($fromTs === $monthStartTs && $toTs === $monthEndTs),
            'days'                => $days,
            'calendar_days'       => $calendarDays,
            'is_full_month'       => $isFull,
            'delta_m3'            => round($monthlyM3, 3),
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