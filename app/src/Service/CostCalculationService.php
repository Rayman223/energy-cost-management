<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\SpotFormula;
use App\Domain\TariffGrid;
use App\Domain\TariffSegment;
use App\Repository\Contract\DynamicPriceRepositoryInterface;
use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Orchestrates cost calculation for a given period using active tariff grids.
 *
 * Data flow:
 *  1. Fetch monthly/hourly deltas from the register model (ElectricityReadingRepository).
 *  2. Resolve active tariff grids from DB (TariffRepository).
 *  3. Delegate cost maths to TariffCalculatorService.
 *
 * Le taux de TVA vient TOUJOURS de la grille de la sous-période concernée
 * (`tariff_grids.vat_rate`) : source unique depuis #232, versionnée par
 * valid_from/valid_to, aussi bien pour la décomposition HTVA des montants TTC que
 * pour la TVA du prix spot.
 */
final class CostCalculationService
{
    /** Coefficient PCS gaz par défaut (kWh/m³) en l'absence de valeur configurée. */
    private const DEFAULT_PCS = 10.55;

    /**
     * Part minimale de consommation devant être mesurée au pas de 15 min pour qu'une
     * période soit facturée au quart d'heure (#230). En dessous, l'essentiel du profil
     * intra-horaire serait reconstruit par étalement plutôt que mesuré : mieux vaut le
     * calcul horaire, qui repose sur un prix PT60M publié, qu'un quart-horaire de
     * façade. Le seuil laisse passer les trous ponctuels d'un flux quart-horaire
     * (relevé manqué, coupure) sans faire basculer tout le mois.
     */
    private const QUARTER_READINGS_MIN_PCT = 80.0;

    /**
     * Part minimale de consommation devant disposer d'un prix pour qu'une série de
     * prix soit retenue. En dessous, les créneaux non couverts ne retombent pas sur
     * une autre série de prix mais sur le tarif fournisseur CLASSIQUE : une série
     * lacunaire ferait donc sortir l'essentiel de la période du tarif dynamique,
     * sans que `price_source` le signale. Mieux vaut alors la série concurrente,
     * moins fine mais complète.
     */
    private const PRICE_COVERAGE_MIN_PCT = 80.0;

    /**
     * @param bool $dynamicEnabled Kill-switch global du tarif dynamique (config
     *        `dynamic_prices.enabled`). false = tarif dynamique désactivé.
     * @param string $pricingMode Mode de tarification choisi par l'utilisateur
     *        ('fixed' | 'dynamic_hourly' | 'dynamic_quarter'). Sélectionne la
     *        résolution des prix dynamiques : 'dynamic_quarter' facture au MTU de
     *        15 min quand la zone publie des prix PT15M ET que les relevés sont au
     *        pas de 15 min, sinon repli sur l'horaire (cf. buildDynamicResponse).
     * @param float $supplierMarkupPerKwh Marge fournisseur €/kWh ajoutée au prix
     *        spot TTC, par utilisateur (user_profiles.supplier_markup_per_kwh).
     *        Sert de REPLI depuis #228 : une grille portant des lignes spot_coefficient
     *        ou spot_offset fait autorité et ce champ est alors ignoré
     *        (cf. SpotFormulaResolver).
     * @param string $tariffTimezone Fuseau (IANA) du contrat servant à borner la
     *        bascule tarifaire jour/nuit (T1/T2). Les clés horaires étant stockées
     *        en UTC (cf. App\Support\Dates), on reconvertit vers ce fuseau avant de
     *        classer une heure. Par utilisateur (user_profiles.timezone) ; défaut
     *        Europe/Brussels. Sépare la facturation (fuseau contrat) de l'affichage.
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
        private readonly float $supplierMarkupPerKwh = 0.0,
        private readonly string $tariffTimezone = 'Europe/Brussels',
        private readonly TariffPeriodSplitter $splitter = new TariffPeriodSplitter(),
        private readonly CostBreakdownAggregator $aggregator = new CostBreakdownAggregator(),
        private readonly SpotFormulaResolver $formulaResolver = new SpotFormulaResolver(),
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

        $segments = $this->segmentsFor('electricity', $from, $to, $days);
        if ($segments === []) {
            return ['available' => false, 'reason' => 'No active electricity tariff configured'];
        }

        return $this->buildElectricityResponse($deltas, $segments, $days);
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

        $segments = $this->segmentsFor('electricity', $from, $to, $days);
        if ($segments === []) {
            return ['available' => false, 'reason' => sprintf('No active electricity tariff for %04d-%02d', $year, $month)];
        }

        return $this->buildElectricityResponse($deltas, $segments, $days);
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

        $segments = $this->segmentsFor('electricity', $from, $to, $days);
        if ($segments === []) {
            return ['available' => false, 'reason' => 'No active electricity tariff configured'];
        }

        return $this->buildDynamicResponse($deltas, $segments, $days);
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

        $segments = $this->segmentsFor('electricity', $from, $to, $days);
        if ($segments === []) {
            return ['available' => false, 'reason' => sprintf('No active electricity tariff for %04d-%02d', $year, $month)];
        }

        return $this->buildDynamicResponse($deltas, $segments, $days);
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

        $segments = $this->segmentsFor('gas', $from, $to, $days);
        if ($segments === []) {
            return ['available' => false, 'reason' => 'Aucun tarif gaz configuré pour cette période.'];
        }

        $deltaM3 = max(0.0, (float) $pair['to']['counter_m3'] - (float) $pair['from']['counter_m3']);
        $gas     = $this->buildGasCost($segments, $deltaM3, $days, $from);

        return [
            'available'       => true,
            'period_from'     => $pair['from']['reading_at'],
            'period_to'       => $pair['to']['reading_at'],
            'days'            => $days,
            'delta_m3'        => round($deltaM3, 3),
            'kwh'             => $gas['kwh'],
            'pcs_coefficient' => $gas['pcs'],
            'tariff_name'     => $this->tariffName($segments),
            'currency'        => $segments[0]->grid->currency,
            'tariff_rates'    => $this->dominantGrid($segments)->toTariffArray(),
            'tariff_segments' => $gas['segments'],
            'cost'            => $gas['cost'],
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

        // ── Tariff & PCS ──────────────────────────────────────────────────────
        $segments = $this->segmentsFor('gas', $startDt, new DateTimeImmutable($interp->monthEnd), $interp->days);
        if ($segments === []) {
            return [
                'available' => false,
                'reason'    => sprintf('Aucun tarif gaz configuré pour %04d-%02d.', $year, $month),
            ];
        }

        $gas = $this->buildGasCost($segments, $interp->monthlyDelta, $interp->days, $startDt);

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
            'kwh'             => round($gas['kwh'], 2),
            'pcs_coefficient' => $gas['pcs'],
            'tariff_name'     => $this->tariffName($segments),
            'currency'        => $segments[0]->grid->currency,
            'tariff_rates'    => $this->dominantGrid($segments)->toTariffArray(),
            'tariff_segments' => $gas['segments'],
            'cost'            => $gas['cost'],
        ];
    }

    /**
     * Volume gaz (m³) d'un mois calendaire, SANS exiger de grille tarifaire.
     *
     * {@see estimateMonthGas()} renvoie `available => false` quand aucun tarif
     * gaz n'est configuré ; la card « Gaz » du dashboard n'a besoin que du
     * volume. On isole donc ici l'interpolation (même moteur, mêmes bornes à
     * minuit), sans résolution tarifaire — symétrique du volet « volume seul »
     * de {@see estimateMonthWater()}.
     *
     * @return array<string, mixed>
     */
    public function monthGasVolume(int $year, int $month): array
    {
        $interp = $this->interpolator->interpolateMonth(
            $this->toSeries($this->gasRepo->getReadingsForInterpolation($year, $month)),
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
            'days'          => $interp->days,
            'calendar_days' => $interp->calendarDays,
            'is_projection' => $interp->isProjection,
            'delta_m3'      => round($interp->monthlyDelta, 3),
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

        $startDt  = new DateTimeImmutable($interp->monthStart);
        $segments = $this->segmentsFor('water', $startDt, new DateTimeImmutable($interp->monthEnd), $interp->days);

        if ($segments !== []) {
            $breakdowns = [];
            foreach ($segments as $segment) {
                $breakdowns[] = $this->calculator->calculateWaterCost(
                    $interp->monthlyDelta * $segment->fraction($interp->days),
                    $segment->days,
                    $segment->grid->toCalculationTariff(),
                    $this->monthsOverride($segments, $interp->days, $segment),
                );
            }

            $result['tariff_name']     = $this->tariffName($segments);
            $result['currency']        = $segments[0]->grid->currency;
            $result['tariff_rates']    = $this->dominantGrid($segments)->toTariffArray();
            $result['tariff_segments'] = $this->segmentsMeta($segments, $breakdowns);
            $result['cost']            = $this->aggregator->aggregate($breakdowns);
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
                // reading_at est stocké en UTC : instant absolu déterministe.
                'ts'    => (new DateTimeImmutable($r['reading_at'], new DateTimeZone('UTC')))->getTimestamp(),
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

    // ── Sous-périodes tarifaires (#196) ───────────────────────────────────────

    /**
     * Découpe une période en sous-périodes tarifaires homogènes : une par grille
     * successivement active. Renvoie [] si aucune grille ne couvre la période —
     * les appelants transforment ce cas en réponse `available => false`.
     *
     * La fenêtre balayée par le découpage est celle des $days jours à partir de
     * $from (computeDays() peut renvoyer le nombre de jours du mois quand la
     * période chevauche deux mois) ; la fenêtre INTERROGÉE va jusqu'à $to, qui
     * peut être postérieur.
     *
     * Repli : si aucune grille n'est active pendant la fenêtre balayée mais qu'une
     * l'est à la borne de fin, elle couvre toute la période — c'est le comportement
     * de l'ancien `findActiveGrid($from) ?? findActiveGrid($to)`, qui rendait un
     * coût dès la création d'une grille démarrant aujourd'hui.
     *
     * @return list<TariffSegment>
     */
    private function segmentsFor(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to, int $days): array
    {
        $start   = $from->setTime(0, 0, 0);
        $scanEnd = $start->modify(sprintf('+%d day', max(0, $days - 1)));

        $grids    = $this->tariffRepo->findActiveGridsBetween($energyType, $start, max($scanEnd, $to->setTime(0, 0, 0)));
        $segments = $this->splitter->split($grids, $start, $days);

        if ($segments === [] && $grids !== []) {
            return [new TariffSegment($grids[0], $start, $scanEnd, $days)];
        }

        return $segments;
    }

    /**
     * Nombre de mois facturés attribué à une sous-période : les mois de la période
     * ENTIÈRE, répartis au prorata des jours. null en mono-grille → le calculateur
     * garde son plancher entier historique (aucun changement de comportement).
     *
     * @param list<TariffSegment> $segments
     */
    private function monthsOverride(array $segments, int $totalDays, TariffSegment $segment): ?float
    {
        if (count($segments) < 2) {
            return null;
        }

        return $this->calculator->wholeMonths($totalDays) * $segment->fraction($totalDays);
    }

    /**
     * Coût gaz d'une période découpée en sous-périodes : le volume est réparti au
     * prorata des jours et chaque sous-période applique le PCS de SA grille
     * (repli sur le dernier PCS connu puis sur la constante par défaut).
     *
     * @param  list<TariffSegment> $segments
     * @return array{kwh: float, pcs: float, cost: array<string, mixed>, segments: list<array<string, mixed>>}
     */
    private function buildGasCost(array $segments, float $deltaM3, int $days, DateTimeImmutable $from): array
    {
        $breakdowns = [];
        $totalKwh   = 0.0;

        foreach ($segments as $segment) {
            $pcs = $segment->grid->pcsCoefficient
                ?? $this->tariffRepo->findMostRecentPcs('gas', $segment->from)
                ?? self::DEFAULT_PCS;

            $kWh       = $this->calculator->m3ToKwh($deltaM3 * $segment->fraction($days), $pcs);
            $totalKwh += $kWh;

            $breakdowns[] = $this->calculator->calculateGasCost(
                $kWh,
                $segment->days,
                $segment->grid->toCalculationTariff(),
                $this->monthsOverride($segments, $days, $segment),
            );
        }

        // PCS exposé : celui de la grille tant qu'il n'y en a qu'une, sinon le PCS
        // EFFECTIF de la période (kWh obtenus / m³ consommés). Le dashboard affiche
        // « delta_m3 → kWh » et « PCS x kWh/m³ » côte à côte : avec deux grilles de
        // PCS différents, seul le coefficient effectif redonne le kWh affiché.
        if (count($segments) > 1 && $deltaM3 > 0.0) {
            $pcs = round($totalKwh / $deltaM3, 3);
        } else {
            $pcs = $this->dominantGrid($segments)->pcsCoefficient
                ?? $this->tariffRepo->findMostRecentPcs('gas', $from)
                ?? self::DEFAULT_PCS;
        }

        return [
            'kwh'      => $totalKwh,
            'pcs'      => $pcs,
            'cost'     => $this->aggregator->aggregate($breakdowns),
            'segments' => $this->segmentsMeta($segments, $breakdowns),
        ];
    }

    /**
     * Libellé tarifaire de la période : le nom de la grille, ou les noms des
     * grilles successives joints par « + » quand la période en compte plusieurs.
     *
     * @param list<TariffSegment> $segments
     */
    private function tariffName(array $segments): string
    {
        $names = [];
        foreach ($segments as $segment) {
            $names[$segment->grid->name] = true;
        }

        return implode(' + ', array_keys($names));
    }

    /**
     * Grille de la sous-période la plus longue : sert aux champs qui ne peuvent
     * porter qu'une grille (`tariff_rates`, exploité par TariffController::mapGrid
     * et le repli horaire du tarif dynamique).
     *
     * @param list<TariffSegment> $segments
     */
    private function dominantGrid(array $segments): TariffGrid
    {
        return $segments[$this->dominantIndex($segments)]->grid;
    }

    /**
     * Index de la sous-période la plus longue. Extrait de {@see dominantGrid()} pour que
     * la formule dynamique exposée (#228) désigne la même sous-période que `tariff_rates`.
     *
     * @param list<TariffSegment> $segments
     */
    private function dominantIndex(array $segments): int
    {
        $best = 0;
        foreach ($segments as $i => $segment) {
            if ($segment->days > $segments[$best]->days) {
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * Détail par sous-période exposé au dashboard (affiché seulement quand la
     * période en compte plusieurs).
     *
     * @param  list<TariffSegment> $segments
     * @param  list<array<string, mixed>> $breakdowns
     * @return list<array<string, mixed>>
     */
    private function segmentsMeta(array $segments, array $breakdowns): array
    {
        $meta = [];
        foreach ($segments as $i => $segment) {
            $meta[] = [
                'name'  => $segment->grid->name,
                'from'  => $segment->from->format('Y-m-d'),
                'to'    => $segment->to->format('Y-m-d'),
                'days'  => $segment->days,
                'total' => (float) ($breakdowns[$i]['total'] ?? 0.0),
            ];
        }

        return $meta;
    }

    /**
     * Sous-période à laquelle rattacher une clé de créneau (heure ou quart d'heure,
     * stockée en UTC) : on la classe par sa date dans le fuseau du contrat, comme la
     * bascule jour/nuit. Les créneaux hors fenêtre sont rattachés au segment le plus
     * proche.
     *
     * @param list<TariffSegment> $segments
     */
    private function segmentIndexForHour(array $segments, string $hour): int
    {
        $day = (new DateTimeImmutable($hour, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($this->tariffTimezone))
            ->format('Y-m-d');

        foreach ($segments as $i => $segment) {
            if ($day <= $segment->to->format('Y-m-d')) {
                return $i;
            }
        }

        return count($segments) - 1;
    }

    /**
     * Construit le tableau de résultat électricité à partir des deltas, des
     * sous-périodes tarifaires et du nombre de jours. Factorise la logique commune
     * à estimateCurrentMonthElectricity() et estimateMonthElectricity().
     *
     * Chaque sous-période reçoit sa part des quantités (prorata des jours, #196) ;
     * les décomptes sont ensuite agrégés. Une seule grille sur la période → un seul
     * segment couvrant tout : résultat identique au calcul mono-grille historique.
     *
     * @param array<string, mixed> $deltas
     * @param list<TariffSegment> $segments
     * @return array<string, mixed>
     */
    private function buildElectricityResponse(array $deltas, array $segments, int $days): array
    {
        $breakdowns = [];
        foreach ($segments as $segment) {
            $share = $segment->fraction($days);

            $breakdowns[] = $this->calculator->calculateElectricityCost(
                kwhT1:          (float) ($deltas['prelev_jour'] ?? 0.0) * $share,
                kwhT2:          (float) ($deltas['prelev_nuit'] ?? 0.0) * $share,
                kwhExportT1:    (float) ($deltas['injec_jour']  ?? 0.0) * $share,
                kwhExportT2:    (float) ($deltas['injec_nuit']  ?? 0.0) * $share,
                days:           $segment->days,
                tariff:         $segment->grid->toCalculationTariff(),
                kwhSolar:       (float) ($deltas['solar'] ?? 0.0) * $share,
                monthsOverride: $this->monthsOverride($segments, $days, $segment),
            );
        }

        return [
            'available'       => true,
            'period_from'     => $deltas['from'],
            'period_to'       => $deltas['to'],
            'days'            => $days,
            'tariff_name'     => $this->tariffName($segments),
            'currency'        => $segments[0]->grid->currency,
            'tariff_rates'    => $this->dominantGrid($segments)->toTariffArray(),
            'tariff_segments' => $this->segmentsMeta($segments, $breakdowns),
            'deltas'          => $deltas,
            'cost'            => $this->aggregator->aggregate($breakdowns),
        ];
    }

    /**
     * Construit la réponse « tarif dynamique » : croise la conso avec les prix de
     * marché, indexe la part énergie et réutilise tous les composants fixes du tarif
     * classique. Repli sur le tarif classique pour les créneaux sans prix dynamique
     * (couverture exposée via coverage_pct).
     *
     * Le pas de calcul est celui de {@see resolveDynamicSeries()} : quart d'heure en
     * mode 'dynamic_quarter' quand les données le permettent, heure sinon. Le reste
     * de la méthode est indifférent au pas — un « créneau » est une clé de période
     * UTC et une quantité.
     *
     * Le prix spot brut n'est jamais facturé tel quel : la formule du contrat
     * (coefficient × spot + marge) est appliquée créneau par créneau via SpotFormula
     * (#228), avec des paramètres résolus par sous-période tarifaire.
     *
     * @param array<string, mixed> $deltas
     * @param list<TariffSegment> $segments
     * @return array<string, mixed>
     */
    private function buildDynamicResponse(array $deltas, array $segments, int $days): array
    {
        if ($this->dynamicPriceRepo === null || !$this->dynamicEnabled) {
            return ['available' => false, 'reason' => 'Tarif dynamique non configuré.'];
        }

        $from = new DateTimeImmutable($deltas['from']);
        $to   = new DateTimeImmutable($deltas['to']);

        $series = $this->resolveDynamicSeries($from, $to);
        if (isset($series['reason'])) {
            return ['available' => false, 'reason' => $series['reason']];
        }

        /** @var list<array{slot: string, import_kwh: float}> $slots */
        $slots = $series['slots'];
        /** @var array<string, float> $prices */
        $prices      = $series['prices'];
        $priceSource = $series['price_source'];

        // Formule d'indexation par sous-période (#228) : coefficient et marge sont des
        // lignes de la grille, donc ils changent avec elle. Résolu une fois par segment
        // plutôt qu'à chaque heure.
        $formulas = array_map(
            fn (TariffSegment $s): SpotFormula => $this->formulaResolver->resolve($s->grid, $this->supplierMarkupPerKwh),
            $segments,
        );

        $energyTtc  = 0.0;
        $totalKwh   = 0.0;
        $coveredKwh = 0.0;
        // Décomposition de la part énergie selon les deux inconnues de la formule (#229) :
        // energy_ttc = coefficient × indexedBaseTtc + offset × coveredKwh + uncoveredTtc.
        // Les deux premiers termes varient avec la formule, le troisième non — c'est ce qui
        // rend le rapprochement facture résoluble sans rejouer la boucle heure par heure.
        $indexedBaseTtc = 0.0;
        $uncoveredTtc   = 0.0;
        /** @var array<string, array{day: string, import_kwh: float, energy_dynamic: float}> $daily */
        $daily = [];
        // Énergie dynamique ventilée par sous-période tarifaire : ici pas de prorata,
        // chaque créneau est rattaché à sa sous-période par sa date réelle (#196).
        $energyBySegment = array_fill(0, count($segments), 0.0);

        foreach ($slots as $row) {
            $slot = $row['slot'];
            $kwh  = $row['import_kwh'];
            $totalKwh += $kwh;

            $segmentIndex = $this->segmentIndexForHour($segments, $slot);

            if (isset($prices[$slot])) {
                // TVA de la grille de CETTE sous-période (#232) : source unique du taux,
                // et versionnée — un passage de 21 % à 6 % en cours de période s'applique
                // à partir de sa date, sans réécrire l'avant.
                $vatRate     = $segments[$segmentIndex]->grid->vatRate;
                $rateTtc     = $formulas[$segmentIndex]->rateTtc($prices[$slot], $vatRate);
                $coveredKwh += $kwh;
                // Base indexée AVANT coefficient et offset, avec la même TVA que rateTtc().
                $indexedBaseTtc += $kwh * $prices[$slot] * (1.0 + $vatRate / 100.0);
            } else {
                // Créneau sans prix dynamique → repli sur le tarif fournisseur classique
                // (déjà TTC) de la grille applicable à CE créneau.
                $rateTtc       = $this->classicEnergyRateForHour($slot, $segments[$segmentIndex]->grid->toTariffArray());
                $uncoveredTtc += $kwh * $rateTtc;
            }

            $lineCost   = $kwh * $rateTtc;
            $energyTtc += $lineCost;
            $energyBySegment[$segmentIndex] += $lineCost;

            $day = substr($slot, 0, 10);
            if (!isset($daily[$day])) {
                $daily[$day] = ['day' => $day, 'import_kwh' => 0.0, 'energy_dynamic' => 0.0];
            }
            $daily[$day]['import_kwh']     += $kwh;
            $daily[$day]['energy_dynamic'] += $lineCost;
        }

        $breakdowns = [];
        foreach ($segments as $i => $segment) {
            $share = $segment->fraction($days);

            $breakdowns[] = $this->calculator->calculateElectricityCostDynamic(
                kwhT1:            (float) ($deltas['prelev_jour'] ?? 0.0) * $share,
                kwhT2:            (float) ($deltas['prelev_nuit'] ?? 0.0) * $share,
                kwhExportT1:      (float) ($deltas['injec_jour']  ?? 0.0) * $share,
                kwhExportT2:      (float) ($deltas['injec_nuit']  ?? 0.0) * $share,
                days:             $segment->days,
                tariff:           $segment->grid->toCalculationTariff(),
                dynamicEnergyTtc: $energyBySegment[$i],
                kwhSolar:         (float) ($deltas['solar'] ?? 0.0) * $share,
                monthsOverride:   $this->monthsOverride($segments, $days, $segment),
            );
        }

        $dailyOut = array_values(array_map(
            static fn (array $d): array => [
                'day'            => $d['day'],
                'import_kwh'     => round($d['import_kwh'], 3),
                'energy_dynamic' => round($d['energy_dynamic'], 2),
            ],
            $daily
        ));

        // Sous-période la plus longue : les champs qui ne peuvent porter qu'une valeur
        // (tariff_rates, formula) désignent tous la même, pour rester cohérents entre eux.
        $dominant = $this->dominantIndex($segments);

        return [
            'available'      => true,
            'period_from'    => $deltas['from'],
            'period_to'      => $deltas['to'],
            'days'           => $days,
            'pricing_mode'   => $this->pricingMode,
            // Résolution EFFECTIVE du calcul, à distinguer de celle demandée par le
            // mode : un utilisateur en 'dynamic_quarter' doit voir qu'il a été
            // facturé à l'heure, et pourquoi, plutôt que de croire au quart d'heure.
            'resolution'           => $series['resolution'],
            'resolution_requested' => $this->pricingMode === 'dynamic_quarter' ? 'quarter' : 'hourly',
            'resolution_fallback'  => $series['fallback'],
            'price_source'   => $priceSource,
            'tariff_name'    => $this->tariffName($segments),
            'currency'        => $segments[0]->grid->currency,
            'tariff_rates'   => $segments[$dominant]->grid->toTariffArray(),
            'tariff_segments' => $this->segmentsMeta($segments, $breakdowns),
            'deltas'         => $deltas,
            'formula'        => $this->formulaMeta($segments[$dominant], $formulas[$dominant]),
            'spot_base'      => $this->spotBaseMeta($indexedBaseTtc, $coveredKwh, $uncoveredTtc, $formulas, $segments),
            'energy_dynamic' => round($energyTtc, 2),
            'avg_price_kwh'  => $totalKwh > 0.0 ? round($energyTtc / $totalKwh, 6) : null,
            'coverage_pct'   => $totalKwh > 0.0 ? round($coveredKwh / $totalKwh * 100.0, 1) : 0.0,
            'matched_kwh'    => round($totalKwh, 3),
            'cost'           => $this->aggregator->aggregate($breakdowns),
            'daily'          => $dailyOut,
        ];
    }

    /**
     * Choisit le pas de calcul du tarif dynamique et la série de prix associée, sur
     * la cascade quart natif → horaire natif → moyenne horaire (#230).
     *
     * Le quart d'heure n'est retenu qu'à deux conditions cumulatives, mesurées sur la
     * consommation réelle et non sur la simple existence des données : les prix PT15M
     * couvrent la période, et les relevés sont réellement au pas de 15 min
     * ({@see self::QUARTER_READINGS_MIN_PCT} pour les deux seuils). Sans relevés 15 min,
     * le calcul facturerait un profil de consommation reconstruit par étalement. Sans
     * couverture de prix, c'est pire qu'un simple retour à l'horaire : les créneaux
     * sans prix 15 min ne retombent pas sur le spot horaire mais sur le tarif
     * fournisseur CLASSIQUE, si bien qu'un seul point PT15M publié — un mois de
     * bascule MTU15, un cron qui n'a rétro-rempli que les derniers jours — suffirait à
     * faire facturer presque tout le mois hors du tarif dynamique. `fallback` dit
     * laquelle des deux conditions a manqué.
     *
     * Le mode 'dynamic_hourly' court-circuite tout cela et garde son comportement
     * historique, prix horaire natif d'abord, moyenne des 15 min ensuite.
     *
     * @return array{resolution: string, price_source: string, prices: array<string, float>,
     *               slots: list<array{slot: string, import_kwh: float}>, fallback: ?string}
     *         |array{reason: string} Motif d'indisponibilité si la série est inexploitable.
     */
    private function resolveDynamicSeries(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // +1 h : la borne haute des deltas est le dernier relevé, dont le créneau de
        // prix commence avant lui — l'élargir garantit de couvrir ce dernier créneau.
        $to1      = $to->modify('+1 hour');
        $repo     = $this->dynamicPriceRepo;
        $fallback = null;

        if ($this->pricingMode === 'dynamic_quarter' && $repo !== null) {
            $quarterPrices = $repo->getQuarterPrices($from, $to1);
            $quarterSlots  = $quarterPrices === [] ? [] : $this->legacyRepo->getQuarterImportDeltas($from, $to);

            if ($quarterSlots !== []
                && $this->weightedShare($quarterSlots, static fn (array $s): bool => $s['native'] === true)
                   < self::QUARTER_READINGS_MIN_PCT
            ) {
                $fallback = 'readings_not_quarter';
            } elseif ($quarterPrices === []
                || $this->weightedShare($quarterSlots, static fn (array $s): bool => isset($quarterPrices[$s['quarter']]))
                   < self::PRICE_COVERAGE_MIN_PCT
            ) {
                $fallback = 'no_quarter_prices';
            } else {
                return [
                    'resolution'   => 'quarter',
                    'price_source' => 'native_quarter',
                    'prices'       => $quarterPrices,
                    'slots'        => array_map(
                        static fn (array $r): array => ['slot' => $r['quarter'], 'import_kwh' => $r['import_kwh']],
                        $quarterSlots,
                    ),
                    'fallback'     => null,
                ];
            }
        }

        $hourlyImport = $this->legacyRepo->getHourlyImportDeltas($from, $to);
        if ($hourlyImport === []) {
            return ['reason' => 'Pas de relevés horaires sur la période.'];
        }

        // Prix horaire NATIF (PT60M) d'abord — ENTSO-E publie un prix horaire propre,
        // distinct de la moyenne des points 15 min.
        //
        // Le repli sur la moyenne horaire se décide sur la COUVERTURE, pas sur une
        // série vide : un mois à cheval sur la bascule MTU15 a des heures natives sur
        // sa première moitié et des points 15 min sur la seconde. Ne regarder que
        // « natif vide ? » ferait facturer la seconde moitié au tarif fournisseur
        // classique, sans que `price_source` en dise rien, alors que la moyenne
        // horaire, elle, agrège les deux résolutions et couvre tout le mois.
        $prices      = $repo?->getHourlyPrices($from, $to1) ?? [];
        $priceSource = 'native_hourly';
        $covered     = $this->weightedShare($hourlyImport, static fn (array $r): bool => isset($prices[$r['hour']]));

        if ($covered < self::PRICE_COVERAGE_MIN_PCT) {
            $average = $repo?->getAveragePriceByHour($from, $to1) ?? [];
            // Le natif reste prioritaire à couverture égale : c'est le prix publié pour
            // l'heure, pas une moyenne reconstruite de ses quarts. Sauf s'il est vide —
            // une série absente n'est jamais préférable, même à couverture nulle des deux
            // côtés : c'est ce qui distingue « aucun prix » de « prix hors des heures
            // consommées », et l'appelant a besoin des deux cas séparément.
            if ($prices === []
                || $this->weightedShare($hourlyImport, static fn (array $r): bool => isset($average[$r['hour']])) > $covered
            ) {
                $prices      = $average;
                $priceSource = 'avg_hourly';
            }
        }

        if ($prices === []) {
            return ['reason' => 'Aucun prix dynamique pour cette période (lancez cron_dynamic_prices).'];
        }

        return [
            'resolution'   => 'hourly',
            'price_source' => $priceSource,
            'prices'       => $prices,
            'slots'        => array_map(
                static fn (array $r): array => ['slot' => $r['hour'], 'import_kwh' => $r['import_kwh']],
                $hourlyImport,
            ),
            'fallback'     => $fallback,
        ];
    }

    /**
     * Part (en %) de la consommation portée par les créneaux vérifiant $matches —
     * mesurés au pas de 15 min, ou disposant d'un prix. Pondérer par les kWh plutôt
     * que compter les créneaux est ce qui rend le seuil pertinent : ce sont les
     * kilowattheures qui sont facturés, pas les cases du calendrier.
     *
     * Sur une période sans consommation (index stable), le ratio en kWh n'a pas de
     * sens : on retombe sur la proportion de créneaux, pour ne pas annoncer un repli
     * alors que les données sont bien là.
     *
     * @param array<int, array<string, mixed>> $slots Créneaux horaires ou quart-horaires.
     * @param callable(array<string, mixed>): bool $matches
     */
    private function weightedShare(array $slots, callable $matches): float
    {
        if ($slots === []) {
            return 0.0;
        }

        $total   = 0.0;
        $matched = 0.0;
        $count   = 0;
        foreach ($slots as $slot) {
            $kwh    = (float) $slot['import_kwh'];
            $total += $kwh;
            if ($matches($slot)) {
                $matched += $kwh;
                $count++;
            }
        }

        return $total > 0.0
            ? $matched / $total * 100.0
            : $count / count($slots) * 100.0;
    }

    /**
     * Formule d'indexation exposée au dashboard, pour que l'utilisateur voie ce qui a été
     * appliqué au prix de marché (#228). `offset_source` dit d'où vient la marge (grille
     * ou repli profil) ; `coefficient_rejected` signale un coefficient hors bornes
     * neutralisé, cas où la formule appliquée ne correspond à rien de saisi.
     *
     * @return array<string, float|string|bool>
     */
    private function formulaMeta(TariffSegment $segment, SpotFormula $formula): array
    {
        return [
            'spot_coefficient'     => round($formula->coefficient, 4),
            'spot_offset_ttc'      => round($formula->offsetTtc, 7),
            'vat_rate'             => $segment->grid->vatRate,
            'offset_source'        => $formula->offsetSource,
            'coefficient_rejected' => $formula->coefficientRejected,
        ];
    }

    /**
     * Décomposition de la part énergie selon les inconnues de la formule (#229), pour le
     * rapprochement facture. Invariant garanti par la boucle de buildDynamicResponse() :
     *
     *     energy_dynamic = coefficient × indexed_ttc + offset_ttc × covered_kwh + uncovered_ttc
     *
     * Retrouver (coefficient, offset) à partir d'un montant facturé se ramène donc à une
     * équation linéaire par mois, sans rejouer le croisement conso × prix heure par heure.
     *
     * `formula_uniform` dit si toutes les sous-périodes du mois partagent les mêmes
     * paramètres. Quand elle est fausse, l'invariant ci-dessus n'a plus de solution unique
     * — le mois chevauche deux contrats — et l'appelant doit le signaler plutôt
     * qu'inventer un couple.
     *
     * Le TAUX DE TVA en fait partie, alors qu'il n'apparaît pas dans l'invariant : il sert
     * à l'appelant pour convertir un montant facturé HTVA en TTC. Deux sous-périodes de
     * même formule mais de TVA différente (passage de 21 % à 6 % en cours de mois) rendent
     * cette conversion ambiguë, même si la résolution, elle, resterait exacte.
     *
     * @param list<SpotFormula>   $formulas
     * @param list<TariffSegment> $segments Même indexation que $formulas.
     * @return array{indexed_ttc: float, covered_kwh: float, uncovered_ttc: float, formula_uniform: bool}
     */
    private function spotBaseMeta(
        float $indexedBaseTtc,
        float $coveredKwh,
        float $uncoveredTtc,
        array $formulas,
        array $segments,
    ): array {
        $uniform = true;
        foreach ($formulas as $i => $formula) {
            // Comparaison à tolérance plutôt que stricte : deux grilles portant le même
            // coefficient peuvent le composer différemment (une ligne 1,08 d'un côté,
            // 1,04 × 1,0385 de l'autre) et différer sur le dernier bit sans que les
            // contrats diffèrent réellement. Le seuil est très en dessous de ce qu'un
            // utilisateur peut saisir (7 décimales en base).
            if (!$this->sameParameter($formula->coefficient, $formulas[0]->coefficient)
                || !$this->sameParameter($formula->offsetTtc, $formulas[0]->offsetTtc)
                || !$this->sameParameter($segments[$i]->grid->vatRate, $segments[0]->grid->vatRate)
            ) {
                $uniform = false;
                break;
            }
        }

        return [
            // 4 décimales : ces montants alimentent une division (résolution du système),
            // arrondir plus court propagerait l'erreur sur le coefficient proposé.
            'indexed_ttc'     => round($indexedBaseTtc, 4),
            'covered_kwh'     => round($coveredKwh, 3),
            'uncovered_ttc'   => round($uncoveredTtc, 4),
            'formula_uniform' => $uniform,
        ];
    }

    /**
     * Deux paramètres tarifaires sont-ils le même ? Tolérance 1e-9, soit deux ordres de
     * grandeur sous la précision de saisie (DECIMAL(12,7)) : un écart réel reste détecté,
     * un artefact de composition en virgule flottante non.
     */
    private function sameParameter(float $a, float $b): bool
    {
        return abs($a - $b) < 1.0e-9;
    }

    /**
     * Tarif fournisseur classique (TTC) applicable à un créneau donné (clé horaire ou
     * quart-horaire), pour le repli des créneaux sans prix dynamique. T1 (jour)
     * 07h–23h, T2 (nuit) sinon.
     *
     * @param array<string, mixed> $tariff
     */
    private function classicEnergyRateForHour(string $hour, array $tariff): float
    {
        // $hour est une clé horaire stockée en UTC (reading_at ; cf. App\Support\Dates).
        // La bascule jour/nuit T1/T2 est définie en heure locale du contrat : on
        // reconvertit vers ce fuseau avant d'extraire l'heure, sinon un relevé de
        // 07h30 belge (05h30 UTC l'été) serait classé à tort en tarif nuit.
        $h = (int) (new DateTimeImmutable($hour, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($this->tariffTimezone))
            ->format('G');
        $isDay = $h >= 7 && $h < 23;

        // Repli monohoraire (energy_simple/energy_flat) pour les grilles sans T1/T2.
        $flat = $tariff['energy_simple'] ?? $tariff['energy'] ?? 0.0;

        return (float) ($isDay
            ? ($tariff['energy_t1'] ?? $flat)
            : ($tariff['energy_t2'] ?? $flat));
    }
}