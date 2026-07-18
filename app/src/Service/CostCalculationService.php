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
     * @param bool $dynamicEnabled Kill-switch global du tarif dynamique (config
     *        `dynamic_prices.enabled`). false = tarif dynamique désactivé.
     * @param string $pricingMode Mode de tarification choisi par l'utilisateur
     *        ('fixed' | 'dynamic_hourly' | 'dynamic_quarter'). Sélectionne la
     *        résolution des prix dynamiques ; le calcul au quart d'heure est
     *        différé → 'dynamic_quarter' retombe sur l'horaire pour l'instant.
     * @param float $vatRatePercent TVA appliquée au prix spot, EN POURCENTAGE
     *        (21.0 = 21 %), par utilisateur (user_profiles.vat_rate). Même unité
     *        que tariff_grids.vat_rate — cf. #153, unification d'unité.
     * @param float $supplierMarkupPerKwh Marge fournisseur €/kWh ajoutée au prix
     *        spot TTC, par utilisateur (user_profiles.supplier_markup_per_kwh).
     */
    public function __construct(
        private readonly LegacyDailyRepositoryInterface $legacyRepo,
        private readonly TariffRepositoryInterface $tariffRepo,
        private readonly GasReadingRepositoryInterface $gasRepo,
        private readonly TariffCalculatorService $calculator,
        private readonly MonthlyConsumptionInterpolator $interpolator = new MonthlyConsumptionInterpolator(),
        private readonly ?DynamicPriceRepositoryInterface $dynamicPriceRepo = null,
        private readonly bool $dynamicEnabled = false,
        private readonly ?MeterReadingRepositoryInterface $waterRepo = null,
        private readonly string $pricingMode = 'fixed',
        private readonly float $vatRatePercent = 21.0,
        private readonly float $supplierMarkupPerKwh = 0.0,
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

        // Horodatages (base, heure murale locale) parsés dans le fuseau applicatif
        // fixé par app/bootstrap.php (#130 B6) : cohérent avec les autres calculs
        // de période de ce service (élec/eau), tous dans le même fuseau.
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

        $breakdown = $this->calculator->calculateGasCost($kWh, $days, $tariff->toCalculationTariff());

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

        $breakdown = $this->calculator->calculateGasCost($kWh, $interp->days, $tariff->toCalculationTariff());

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
     * Estimate water cost/consumption for a calendar month, using the same
     * midnight interpolation as gas. Si une grille tarifaire « eau » est active,
     * on ajoute le coût (cost / tariff_name / currency) ; sinon on renvoie le
     * volume seul (rétrocompat : l'eau n'avait pas de tarif auparavant).
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

        $result = [
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

        $startDt = new DateTimeImmutable($interp->monthStart);
        $endDt   = new DateTimeImmutable($interp->monthEnd);
        $tariff  = $this->tariffRepo->findActiveGrid('water', $startDt)
                ?? $this->tariffRepo->findActiveGrid('water', $endDt);

        if ($tariff !== null) {
            $result['tariff_name']  = $tariff->name;
            $result['currency']     = $tariff->currency;
            $result['tariff_rates'] = $tariff->toTariffArray();
            $result['cost']         = $this->calculator->calculateWaterCost(
                $interp->monthlyDelta,
                $interp->days,
                $tariff->toCalculationTariff(),
            );
        }

        return $result;
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
     * Convention `[from, to)` (bornes ouvertes à droite, sans +1), identique à
     * celle du gaz ({@see estimateLastGasPeriod()}) : on compte les jours pleins
     * écoulés entre minuit de $from et minuit de $to. Ainsi une période
     * 1er→13 juillet 08:00 vaut 12 jours (et non 13), et une période intra-journée
     * vaut 1 jour (minimum). Uniformise la proratisation des postes fixes entre
     * fluides (#130 B1).
     *
     * Si les horodatages chevauchent deux mois (from en fin de mois N, to en mois N+1) :
     * on utilise le nombre total de jours du mois de $from.
     */
    private function computeDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($from->format('Y-m') === $to->format('Y-m')) {
            return max(1, (int) $from->setTime(0, 0, 0)->diff($to->setTime(0, 0, 0))->days);
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
            tariff:      $tariff->toCalculationTariff(),
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
        if ($this->dynamicPriceRepo === null || !$this->dynamicEnabled) {
            return ['available' => false, 'reason' => 'Tarif dynamique non configuré.'];
        }

        $from      = new DateTimeImmutable($deltas['from']);
        $to        = new DateTimeImmutable($deltas['to']);
        $tariffArr = $tariff->toTariffArray();

        $hourlyImport = $this->legacyRepo->getHourlyImportDeltas($from, $to);
        if ($hourlyImport === []) {
            return ['available' => false, 'reason' => 'Pas de relevés horaires sur la période.'];
        }

        // Prix horaire NATIF (PT60M) d'abord — ENTSO-E publie un prix horaire propre,
        // distinct de la moyenne des points 15 min. Repli sur la moyenne horaire si
        // aucune série horaire native n'est disponible pour la période.
        // NB : le mode 'dynamic_quarter' (15 min) retombe sur l'horaire pour l'instant
        // (calcul au quart d'heure différé) → résolution effective 'hourly'.
        $to1        = $to->modify('+1 hour');
        $prices     = $this->dynamicPriceRepo->getHourlyPrices($from, $to1);
        $priceSource = 'native_hourly';
        if ($prices === []) {
            $prices      = $this->dynamicPriceRepo->getAveragePriceByHour($from, $to1);
            $priceSource = 'avg_hourly';
        }
        if ($prices === []) {
            return ['available' => false, 'reason' => 'Aucun prix dynamique pour cette période (lancez cron_dynamic_prices).'];
        }

        $vatRatePercent = $this->vatRatePercent;
        $markup         = $this->supplierMarkupPerKwh;

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
                $rateTtc     = $prices[$hour] * (1.0 + $vatRatePercent / 100.0) + $markup;
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
            tariff:           $tariff->toCalculationTariff(),
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
            'pricing_mode'   => $this->pricingMode,
            'resolution'     => 'hourly',
            'price_source'   => $priceSource,
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

        // Repli monohoraire (energy_simple/energy_flat) pour les grilles sans T1/T2.
        $flat = $tariff['energy_simple'] ?? $tariff['energy'] ?? 0.0;

        return (float) ($isDay
            ? ($tariff['energy_t1'] ?? $flat)
            : ($tariff['energy_t2'] ?? $flat));
    }
}