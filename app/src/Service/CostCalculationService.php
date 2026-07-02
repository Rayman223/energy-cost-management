<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TariffGrid;
use App\Repository\Contract\DynamicPriceRepositoryInterface;
use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;

/**
 * Orchestrates cost calculation for a given period using active tariff grids.
 *
 * Data flow:
 *  1. Fetch monthly/hourly deltas from the register model (ElectricityReadingRepository).
 *  2. Resolve active tariff grids from DB (TariffRepository).
 *  3. Delegate cost maths to TariffCalculatorService.
 */
final class CostCalculationService
{
    /** Coefficient PCS gaz par défaut (kWh/m³) en l'absence de valeur configurée. */
    private const DEFAULT_PCS = 10.55;

    /**
     * @param array<string, mixed> $dynamicConfig Bloc de config `dynamic_prices`
     *        (enabled, supplier_markup_per_kwh, vat_rate…). Vide = tarif dynamique désactivé.
     */
    public function __construct(
        private readonly LegacyDailyRepositoryInterface $legacyRepo,
        private readonly TariffRepositoryInterface $tariffRepo,
        private readonly GasReadingRepositoryInterface $gasRepo,
        private readonly TariffCalculatorService $calculator,
        private readonly MonthlyConsumptionInterpolator $interpolator = new MonthlyConsumptionInterpolator(),
        private readonly ?DynamicPriceRepositoryInterface $dynamicPriceRepo = null,
        private readonly array $dynamicConfig = [],
        private readonly ?MeterReadingRepositoryInterface $waterRepo = null,
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
     * Estimation « tarif dynamique » du mois courant (part énergie indexée au spot).
     *
     * @return array<string, mixed>
     */
    public function estimateCurrentMonthElectricityDynamic(): array
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

        return $this->buildDynamicResponse($deltas, $tariff, $days);
    }

    /**
     * Estimation « tarif dynamique » pour un mois calendaire donné.
     *
     * @return array<string, mixed>
     */
    public function estimateMonthElectricityDynamic(int $year, int $month): array
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

        return $this->buildDynamicResponse($deltas, $tariff, $days);
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
            'currency'        => $tariff->currency,
            'tariff_rates'    => $tariff->toTariffArray(),
            'cost'            => $breakdown,
        ];
    }

    /**
     * Estimate gas cost for any given calendar month using LINEAR INTERPOLATION
     * TO MIDNIGHT (cf. MonthlyConsumptionInterpolator).
     *
     * On estime l'index théorique à minuit le 1er de M et le 1er de M+1 à partir
     * de la fenêtre de relevés (le dernier avant le mois, ceux du mois, le premier
     * après le mois). Les relevés intermédiaires servent d'ancrages et le décalage
     * horaire des relevés manuels (relevé à 07:54 → minuit) est récupéré par
     * extrapolation aux bornes. Mois en cours → projection jusqu'à fin de mois.
     *
     * @return array<string, mixed>
     */
    public function estimateMonthGas(int $year, int $month): array
    {
        $interp = $this->interpolator->interpolateMonth(
            $this->toSeries($this->gasRepo->getReadingsForInterpolation($year, $month)),
            $year,
            $month,
        );

        if (!$interp->available) {
            return ['available' => false, 'reason' => $interp->reason];
        }

        $startDt = new DateTimeImmutable($interp->monthStart);
        $endDt   = new DateTimeImmutable($interp->monthEnd);

        // ── Tariff & PCS ──────────────────────────────────────────────────────
        $tariff = $this->tariffRepo->findActiveGrid('gas', $startDt)
               ?? $this->tariffRepo->findActiveGrid('gas', $endDt);

        if ($tariff === null) {
            return [
                'available' => false,
                'reason'    => sprintf('Aucun tarif gaz configuré pour %04d-%02d.', $year, $month),
            ];
        }

        $pcs = $tariff->pcsCoefficient
            ?? $this->tariffRepo->findMostRecentPcs('gas', $startDt)
            ?? self::DEFAULT_PCS;

        $kWh = $this->calculator->m3ToKwh($interp->monthlyDelta, $pcs);

        $breakdown = $this->calculator->calculateGasCost($kWh, $interp->days, $tariff->toTariffArray());

        return [
            'available'       => true,
            // Fenêtre calendaire effective (bornes interpolées à minuit).
            'period_from'     => $interp->monthStart,
            'period_to'       => $interp->monthEnd,
            'month_start'     => $interp->monthStart,
            'month_end'       => $interp->monthEnd,
            'days'            => $interp->days,
            'calendar_days'   => $interp->calendarDays,
            'is_projection'   => $interp->isProjection,
            'delta_m3'        => round($interp->monthlyDelta, 3),
            'kwh'             => round($kWh, 2),
            'pcs_coefficient' => $pcs,
            'tariff_name'     => $tariff->name,
            'currency'        => $tariff->currency,
            'tariff_rates'    => $tariff->toTariffArray(),
            'cost'            => $breakdown,
        ];
    }

    /**
     * Estimate water CONSUMPTION (volume m³) for a calendar month, using the same
     * midnight interpolation as gas. L'eau n'a pas de tarif → on renvoie le volume
     * uniquement (aucun coût).
     *
     * @return array<string, mixed>
     */
    public function estimateMonthWater(int $year, int $month): array
    {
        if ($this->waterRepo === null) {
            return ['available' => false, 'reason' => 'Relevés eau indisponibles.'];
        }

        $interp = $this->interpolator->interpolateMonth(
            $this->toSeries($this->waterRepo->getReadingsForInterpolation($year, $month)),
            $year,
            $month,
        );

        if (!$interp->available) {
            return ['available' => false, 'reason' => $interp->reason];
        }

        return [
            'available'     => true,
            'period_from'   => $interp->monthStart,
            'period_to'     => $interp->monthEnd,
            'month_start'   => $interp->monthStart,
            'month_end'     => $interp->monthEnd,
            'days'          => $interp->days,
            'calendar_days' => $interp->calendarDays,
            'is_projection' => $interp->isProjection,
            'delta_m3'      => round($interp->monthlyDelta, 3),
        ];
    }

    // ── Helpers privés ─────────────────────────────────────────────────────────

    /**
     * Convertit une fenêtre de relevés compteur (reading_at + counter_m3) en série
     * {ts, value} consommée par MonthlyConsumptionInterpolator.
     *
     * @param list<array{reading_at: string, counter_m3: float}> $readings
     * @return list<array{ts: int, value: float}>
     */
    private function toSeries(array $readings): array
    {
        return array_map(
            static fn (array $r): array => [
                'ts'    => (new DateTimeImmutable($r['reading_at']))->getTimestamp(),
                'value' => (float) $r['counter_m3'],
            ],
            $readings,
        );
    }

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
            'currency'        => $tariff->currency,
            'tariff_rates' => $tariff->toTariffArray(),
            'deltas'       => $deltas,
            'cost'         => $breakdown,
        ];
    }

    /**
     * Construit la réponse « tarif dynamique » : croise la conso horaire avec les
     * prix de marché horaires, indexe la part énergie et réutilise tous les
     * composants fixes du tarif classique. Repli sur le tarif classique pour les
     * heures sans prix dynamique (couverture exposée via coverage_pct).
     *
     * @param array<string, mixed> $deltas
     * @return array<string, mixed>
     */
    private function buildDynamicResponse(array $deltas, TariffGrid $tariff, int $days): array
    {
        if ($this->dynamicPriceRepo === null || ($this->dynamicConfig['enabled'] ?? false) !== true) {
            return ['available' => false, 'reason' => 'Tarif dynamique non configuré.'];
        }

        $from      = new DateTimeImmutable($deltas['from']);
        $to        = new DateTimeImmutable($deltas['to']);
        $tariffArr = $tariff->toTariffArray();

        $hourlyImport = $this->legacyRepo->getHourlyImportDeltas($from, $to);
        if ($hourlyImport === []) {
            return ['available' => false, 'reason' => 'Pas de relevés horaires sur la période.'];
        }

        $prices = $this->dynamicPriceRepo->getAveragePriceByHour($from, $to->modify('+1 hour'));
        if ($prices === []) {
            return ['available' => false, 'reason' => 'Aucun prix dynamique pour cette période (lancez cron_dynamic_prices).'];
        }

        $vat    = (float) ($this->dynamicConfig['vat_rate'] ?? 0.21);
        $markup = (float) ($this->dynamicConfig['supplier_markup_per_kwh'] ?? 0.0);

        $energyTtc  = 0.0;
        $totalKwh   = 0.0;
        $coveredKwh = 0.0;
        /** @var array<string, array{day: string, import_kwh: float, energy_dynamic: float}> $daily */
        $daily = [];

        foreach ($hourlyImport as $row) {
            $hour = $row['hour'];
            $kwh  = $row['import_kwh'];
            $totalKwh += $kwh;

            if (isset($prices[$hour])) {
                $rateTtc     = $prices[$hour] * (1.0 + $vat) + $markup;
                $coveredKwh += $kwh;
            } else {
                // Heure sans prix dynamique → repli sur le tarif fournisseur classique (déjà TTC).
                $rateTtc = $this->classicEnergyRateForHour($hour, $tariffArr);
            }

            $lineCost   = $kwh * $rateTtc;
            $energyTtc += $lineCost;

            $day = substr($hour, 0, 10);
            if (!isset($daily[$day])) {
                $daily[$day] = ['day' => $day, 'import_kwh' => 0.0, 'energy_dynamic' => 0.0];
            }
            $daily[$day]['import_kwh']     += $kwh;
            $daily[$day]['energy_dynamic'] += $lineCost;
        }

        $breakdown = $this->calculator->calculateElectricityCostDynamic(
            kwhT1:            (float) ($deltas['prelev_jour'] ?? 0.0),
            kwhT2:            (float) ($deltas['prelev_nuit'] ?? 0.0),
            kwhExportT1:      (float) ($deltas['injec_jour']  ?? 0.0),
            kwhExportT2:      (float) ($deltas['injec_nuit']  ?? 0.0),
            days:             $days,
            tariff:           $tariffArr,
            dynamicEnergyTtc: $energyTtc,
            kwhSolar:         (float) ($deltas['solar'] ?? 0.0),
        );

        $dailyOut = array_values(array_map(
            static fn (array $d): array => [
                'day'            => $d['day'],
                'import_kwh'     => round($d['import_kwh'], 3),
                'energy_dynamic' => round($d['energy_dynamic'], 2),
            ],
            $daily
        ));

        return [
            'available'      => true,
            'period_from'    => $deltas['from'],
            'period_to'      => $deltas['to'],
            'days'           => $days,
            'tariff_name'    => $tariff->name,
            'currency'        => $tariff->currency,
            'tariff_rates'   => $tariffArr,
            'deltas'         => $deltas,
            'energy_dynamic' => round($energyTtc, 2),
            'avg_price_kwh'  => $totalKwh > 0.0 ? round($energyTtc / $totalKwh, 6) : null,
            'coverage_pct'   => $totalKwh > 0.0 ? round($coveredKwh / $totalKwh * 100.0, 1) : 0.0,
            'matched_kwh'    => round($totalKwh, 3),
            'cost'           => $breakdown,
            'daily'          => $dailyOut,
        ];
    }

    /**
     * Tarif fournisseur classique (TTC) applicable à une heure donnée, pour le repli
     * des heures sans prix dynamique. T1 (jour) 07h–23h, T2 (nuit) sinon.
     *
     * @param array<string, mixed> $tariff
     */
    private function classicEnergyRateForHour(string $hour, array $tariff): float
    {
        $h     = (int) substr($hour, 11, 2);
        $isDay = $h >= 7 && $h < 23;

        return (float) ($isDay ? ($tariff['energy_t1'] ?? 0.0) : ($tariff['energy_t2'] ?? 0.0));
    }
}