<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Domain\TariffGrid;
use App\Repository\Contract\BatteryReadingsInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Repository\Contract\TariffRepositoryInterface;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * Ce qu'une batterie a fait économiser, mois par mois (#26).
 *
 * ── Le problème ────────────────────────────────────────────────────────────
 * L'économie est CONTREFACTUELLE : les index `import_t1/t2` du compteur mesurent
 * déjà le réseau APRÈS l'intervention de la batterie. Ce qu'elle a évité de
 * prélever n'apparaît nulle part, il se reconstruit depuis ses propres index.
 *
 * ── Les trois chiffres produits ────────────────────────────────────────────
 *   économie BRUTE    = kWh déchargés × prix d'import TTC évité
 *                       → c'est CE chiffre qui pilote l'amortissement : chaque kWh
 *                         sorti de la batterie est un kWh non prélevé, donc non payé.
 *   coût d'OPPORTUNITÉ = part PV × kWh chargés × tarif d'injection      (manque à gagner)
 *                      + part réseau × kWh chargés × prix d'import évité (recharge payée)
 *                       → affiché À TITRE DE COMPARAISON, jamais déduit de
 *                         l'amortissement.
 *   économie NETTE    = brute − coût d'opportunité, pour qui veut la vue complète.
 *
 * Le prix évité ne retient que les composantes proportionnelles au kWh
 * ({@see TariffCalculatorService::avoidedImportRate()}) : un abonnement se paie
 * que l'on prélève ou non, l'inclure ferait « économiser » un forfait qui tombe
 * de toute façon.
 *
 * ── Les hypothèses, assumées comme telles ──────────────────────────────────
 * Ni l'origine de l'énergie chargée, ni l'heure de la décharge ne se déduisent
 * d'index journaliers. Elles sont donc DÉCLARÉES sur la batterie
 * ({@see Battery::$pvChargeShare}, {@see Battery::$dischargeProfile}) et le
 * résultat les rappelle, pour qu'un chiffre supposé ne se lise pas comme une mesure.
 *
 * ── Ce qui n'est pas couvert ───────────────────────────────────────────────
 * Les contrats indexés au marché (`dynamic_hourly` / `dynamic_quarter`) : valoriser
 * une décharge au prix spot exige des relevés infra-journaliers, que le plafond
 * journalier des index de batterie ne fournit pas. Le mois est alors rendu SANS
 * chiffre et marqué `unsupported_mode`, plutôt qu'avec une valeur fausse.
 *
 * @phpstan-type BatteryMonth array{
 *     month: string,
 *     charge_kwh: float|null,
 *     discharge_kwh: float|null,
 *     avoided_rate: float|null,
 *     injection_rate: float|null,
 *     t1_share: float|null,
 *     gross_savings: float|null,
 *     opportunity_cost: float|null,
 *     net_savings: float|null,
 *     efficiency: float|null,
 *     partial: bool,
 *     unsupported_mode: bool,
 *     no_tariff: bool,
 *     currency: string|null,
 * }
 * @phpstan-type BatteryBalance array{
 *     battery_id: int,
 *     label: string,
 *     pv_charge_share: int,
 *     discharge_profile: string,
 *     months: list<BatteryMonth>,
 *     charge_kwh: float,
 *     discharge_kwh: float,
 *     gross_savings: float,
 *     opportunity_cost: float,
 *     net_savings: float,
 *     efficiency: float|null,
 *     months_valued: int,
 *     has_unsupported_months: bool,
 *     has_untariffed_months: bool,
 *     currency: string|null,
 * }
 */
final class BatterySavingsService
{
    /**
     * Garde-fou de balayage : 50 ans de mois. Une date de mise en service aberrante
     * (« 1900 ») ne doit pas faire boucler la page sur des milliers de mois, chacun
     * coûtant une résolution de grille.
     */
    private const MAX_MONTHS = 600;

    /**
     * Répartition jour/nuit retenue quand le mix réel est indéterminable — un mois
     * sans le moindre prélèvement réseau. La convention 50/50 est déjà celle des
     * statistiques communautaires pour ramener un bihoraire à un prix unique
     * (`stats.method_mix`) : mieux vaut la réutiliser que d'inventer un repli, ou
     * de trancher arbitrairement pour le tarif le plus cher.
     */
    private const UNKNOWN_MIX_T1_SHARE = 0.5;

    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
        private readonly LegacyDailyRepositoryInterface $elecRepo,
        private readonly TariffCalculatorService $calculator = new TariffCalculatorService(),
        private readonly TariffPeriodSplitter $splitter = new TariffPeriodSplitter(),
        private readonly MonthlyConsumptionInterpolator $interpolator = new MonthlyConsumptionInterpolator(),
    ) {
    }

    /**
     * Bilan de chaque batterie du parc, plus l'agrégat du parc.
     *
     * @param list<array{battery: Battery, readings: BatteryReadingsInterface}> $fleet
     *        Le matériel et sa source d'index. Appariés par l'appelant : le service
     *        ne connaît ni PDO ni la manière dont les index sont stockés.
     * @param DateTimeImmutable|null $asOf Instant de référence (tests) ; maintenant
     *        en UTC par défaut — fuseau de stockage, comme les bornes de mois.
     *
     * @return array{batteries: list<BatteryBalance>, fleet: BatteryBalance|null}
     */
    public function balance(array $fleet, ?DateTimeImmutable $asOf = null): array
    {
        $asOf     = $asOf ?? Dates::nowUtc();
        $balances = [];

        foreach ($fleet as $entry) {
            $balances[] = $this->balanceFor($entry['battery'], $entry['readings'], $asOf);
        }

        return ['batteries' => $balances, 'fleet' => $this->aggregate($balances)];
    }

    /**
     * @return BatteryBalance
     */
    private function balanceFor(Battery $battery, BatteryReadingsInterface $readings, DateTimeImmutable $asOf): array
    {
        $series = $readings->indexSeries();
        $months = [];

        foreach ($this->monthsOf($battery, $asOf) as $monthStart) {
            $month = $this->valueMonth($battery, $series, $monthStart);
            if ($month !== null) {
                $months[] = $month;
            }
        }

        return $this->summarise(
            $months,
            $battery->id,
            $battery->label(),
            $battery->pvChargeShare,
            $battery->dischargeProfile->value,
        );
    }

    /**
     * Mois calendaires (UTC) pendant lesquels la batterie a été en service, du plus
     * ancien au plus récent.
     *
     * Bornés par la mise en service et par `decommissioned_on` — une fin EXCLUE
     * (#1), d'où le recul d'un jour pour retrouver le dernier mois réellement
     * couvert. Une batterie déposée le 1er juillet a servi jusqu'en juin.
     *
     * @return list<DateTimeImmutable>
     */
    private function monthsOf(Battery $battery, DateTimeImmutable $asOf): array
    {
        $first = $battery->commissionedOn->setTimezone(Dates::utc())->modify('first day of this month')->setTime(0, 0, 0);

        $last = $battery->decommissionedOn !== null
            ? $battery->decommissionedOn->setTimezone(Dates::utc())->modify('-1 day')
            : $asOf->setTimezone(Dates::utc());
        $last = $last->modify('first day of this month')->setTime(0, 0, 0);

        // Mise en service dans le futur, ou déposée avant d'avoir servi.
        if ($last < $first) {
            return [];
        }

        $months = [];
        $cursor = $first;
        while ($cursor <= $last && count($months) < self::MAX_MONTHS) {
            $months[] = $cursor;
            $cursor   = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * Valorise un mois. `null` quand ni la charge ni la décharge n'y sont
     * mesurables : le mois n'existe alors pas dans le bilan, plutôt que d'y figurer
     * en ligne vide qui laisserait croire à une batterie inactive.
     *
     * @param array{charge: list<array{ts:int,value:float}>, discharge: list<array{ts:int,value:float}>} $series
     * @return BatteryMonth|null
     */
    private function valueMonth(Battery $battery, array $series, DateTimeImmutable $monthStart): ?array
    {
        $monthEnd = $monthStart->modify('+1 month');

        $charge    = $this->deltaOver($series['charge'], $monthStart, $monthEnd);
        $discharge = $this->deltaOver($series['discharge'], $monthStart, $monthEnd);

        if ($charge === null && $discharge === null) {
            return null;
        }

        $chargeKwh    = $charge['value'] ?? null;
        $dischargeKwh = $discharge['value'] ?? null;
        $partial      = ($charge['partial'] ?? false) || ($discharge['partial'] ?? false);

        $row = [
            'month'            => $monthStart->format('Y-m'),
            'charge_kwh'       => $chargeKwh,
            'discharge_kwh'    => $dischargeKwh,
            'avoided_rate'     => null,
            'injection_rate'   => null,
            't1_share'         => null,
            'gross_savings'    => null,
            'opportunity_cost' => null,
            'net_savings'      => null,
            // Rendement : la seule grandeur qui ne dépend d'aucun tarif. Elle reste
            // donc calculée même en contrat dynamique ou sans grille.
            'efficiency'       => ($chargeKwh !== null && $chargeKwh > 0.0 && $dischargeKwh !== null)
                ? round($dischargeKwh / $chargeKwh, 4)
                : null,
            'partial'          => $partial,
            'unsupported_mode' => false,
            'no_tariff'        => false,
            'currency'         => null,
        ];

        $rates = $this->ratesFor($battery, $monthStart, $monthEnd);
        if ($rates['unsupported_mode'] || $rates['no_tariff']) {
            $row['unsupported_mode'] = $rates['unsupported_mode'];
            $row['no_tariff']        = $rates['no_tariff'];

            return $row;
        }

        $row['avoided_rate']   = round($rates['avoided'], 6);
        $row['injection_rate'] = round($rates['injection'], 6);
        $row['t1_share']       = round($rates['t1_share'], 4);
        $row['currency']       = $rates['currency'];

        // Économie brute : chaque kWh sorti de la batterie n'a pas été prélevé.
        $gross = ($dischargeKwh ?? 0.0) * $rates['avoided'];

        // Coût d'opportunité : ce que la charge a coûté. La part photovoltaïque
        // aurait été injectée (manque à gagner), la part réseau a été prélevée et
        // payée au même prix que celui qu'on évite ensuite.
        $opportunity = ($chargeKwh ?? 0.0) * (
            $battery->pvChargeRatio() * $rates['injection']
            + $battery->gridChargeRatio() * $rates['avoided']
        );

        $row['gross_savings']    = round($gross, 2);
        $row['opportunity_cost'] = round($opportunity, 2);
        $row['net_savings']      = round($gross - $opportunity, 2);

        return $row;
    }

    /**
     * Taux du mois, moyennés sur ses sous-périodes tarifaires.
     *
     * Un contrat peut basculer en cours de mois (#196) : chaque segment est pondéré
     * par sa part de jours. Un SEUL segment indexé au marché suffit à rendre le mois
     * non valorisable — on ne panache pas un demi-mois chiffré avec un demi-mois
     * inconnu, le total serait faux sans être signalé comme tel.
     *
     * @return array{avoided: float, injection: float, t1_share: float, currency: string|null, unsupported_mode: bool, no_tariff: bool}
     */
    private function ratesFor(Battery $battery, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
    {
        $empty = ['avoided' => 0.0, 'injection' => 0.0, 't1_share' => 0.0, 'currency' => null];
        $days  = (int) $monthStart->format('t');

        $grids = $this->tariffs->findActiveGridsBetween(
            'electricity',
            $monthStart,
            $monthEnd->modify('-1 day'), // fenêtre de balayage, dernier jour INCLUS
        );
        $segments = $this->splitter->split($grids, $monthStart, $days);

        if ($segments === []) {
            return $empty + ['unsupported_mode' => false, 'no_tariff' => true];
        }

        foreach ($segments as $segment) {
            if ($segment->grid->isDynamic()) {
                return $empty + ['unsupported_mode' => true, 'no_tariff' => false];
            }
        }

        $t1Share   = $this->t1ShareFor($battery, $monthStart, $monthEnd);
        $avoided   = 0.0;
        $injection = 0.0;
        /** @var list<string> $currencies */
        $currencies = [];

        foreach ($segments as $segment) {
            $tariff   = $segment->grid->toCalculationTariff();
            $fraction = $segment->fraction($days);

            $avoided   += $this->calculator->avoidedImportRate($tariff, $t1Share) * $fraction;
            $injection += $this->calculator->injectionRate($tariff) * $fraction;
            $currencies[] = $segment->grid->currency;
        }

        $unique = array_values(array_unique($currencies));

        return [
            'avoided'   => $avoided,
            'injection' => $injection,
            't1_share'  => $t1Share,
            // Devise renseignée seulement si toutes les grilles du mois s'accordent
            // — même règle que {@see CostBreakdownAggregator} pour le taux de TVA.
            'currency'  => count($unique) === 1 ? $unique[0] : null,
            'unsupported_mode' => false,
            'no_tariff'        => false,
        ];
    }

    /**
     * Part de la décharge supposée remplacer un prélèvement en heures pleines.
     *
     * Les trois premiers profils sont des constantes déclarées ; seul `import_mix`
     * se mesure — sur la répartition T1/T2 des kWh RÉELLEMENT prélevés du mois, la
     * seule donnée qui n'ajoute aucune hypothèse par-dessus les relevés.
     */
    private function t1ShareFor(Battery $battery, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): float
    {
        switch ($battery->dischargeProfile) {
            case BatteryDischargeProfile::T1:
                return 1.0;
            case BatteryDischargeProfile::T2:
                return 0.0;
            case BatteryDischargeProfile::Ratio:
                return $battery->dischargeT1Ratio() ?? self::UNKNOWN_MIX_T1_SHARE;
            case BatteryDischargeProfile::ImportMix:
                break;
        }

        $deltas = $this->elecRepo->getDeltasBetween(
            Dates::toDbString($monthStart),
            Dates::toDbString($monthEnd),
        );

        $t1    = (float) ($deltas['prelev_jour'] ?? 0.0);
        $t2    = (float) ($deltas['prelev_nuit'] ?? 0.0);
        $total = $t1 + $t2;

        // Aucun prélèvement du mois : le mix est indéterminable (mois entièrement
        // autoconsommé, ou relevés élec manquants). Le repli est explicite plutôt
        // que de retenir le tarif le plus cher ou le moins cher au hasard.
        return $total > 0.0 ? $t1 / $total : self::UNKNOWN_MIX_T1_SHARE;
    }

    /**
     * Delta d'un compteur sur un mois, bornes CLAMPÉES sur les relevés réels.
     *
     * Pas de projection en avant : sur le mois en cours, la valeur affichée doit
     * être la consommation mesurée à ce jour. Extrapoler gonflerait la dernière
     * ligne, et avec elle l'économie annoncée puis le temps d'amortissement — la
     * question même que pose l'issue.
     *
     * Pas d'extrapolation en arrière non plus : le premier mois d'une batterie
     * commence au premier relevé, pas à une consommation inventée avant lui.
     *
     * @param list<array{ts:int,value:float}> $series
     * @return array{value: float, partial: bool}|null
     */
    private function deltaOver(array $series, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): ?array
    {
        $n = count($series);
        if ($n < 2) {
            // Un seul relevé : aucune pente connue, donc aucun kWh attribuable.
            return null;
        }

        $firstTs = $series[0]['ts'];
        $lastTs  = $series[$n - 1]['ts'];

        $startTs = max($monthStart->getTimestamp(), $firstTs);
        $endTs   = min($monthEnd->getTimestamp(), $lastTs);
        if ($endTs <= $startTs) {
            return null;
        }

        $interpolation = $this->interpolator->interpolateRange(
            $series,
            (new DateTimeImmutable('@' . $startTs))->setTimezone(Dates::utc()),
            (new DateTimeImmutable('@' . $endTs))->setTimezone(Dates::utc()),
        );

        if (!$interpolation->available) {
            return null;
        }

        return [
            // max(0) : un index cumulé ne recule pas ; une valeur négative viendrait
            // d'un compteur remis à zéro, et la compter en négatif retrancherait une
            // économie déjà acquise.
            'value'   => round(max(0.0, $interpolation->monthlyDelta), 3),
            'partial' => $startTs !== $monthStart->getTimestamp() || $endTs !== $monthEnd->getTimestamp(),
        ];
    }

    /**
     * Totaux d'une liste de mois. Le rendement CUMULÉ est recalculé sur les totaux,
     * jamais moyenné : une moyenne de rapports mensuels donnerait le même poids à un
     * mois d'hiver presque inactif qu'à un mois d'été à plein régime.
     *
     * @param list<BatteryMonth> $months
     * @return BatteryBalance
     */
    private function summarise(array $months, int $batteryId, string $label, int $pvShare, string $profile): array
    {
        $charge      = 0.0;
        $discharge   = 0.0;
        $gross       = 0.0;
        $opportunity = 0.0;
        $valued      = 0;
        $unsupported = false;
        $untariffed  = false;
        /** @var list<string> $currencies */
        $currencies = [];

        foreach ($months as $month) {
            // Les quantités se totalisent même sur un mois non valorisable : le
            // rendement, lui, reste calculable sans tarif.
            $charge    += $month['charge_kwh'] ?? 0.0;
            $discharge += $month['discharge_kwh'] ?? 0.0;

            $unsupported = $unsupported || $month['unsupported_mode'];
            $untariffed  = $untariffed || $month['no_tariff'];

            if ($month['gross_savings'] === null) {
                continue;
            }

            $gross       += $month['gross_savings'];
            $opportunity += $month['opportunity_cost'] ?? 0.0;
            $valued++;

            if ($month['currency'] !== null) {
                $currencies[] = $month['currency'];
            }
        }

        $unique = array_values(array_unique($currencies));

        return [
            'battery_id'        => $batteryId,
            'label'             => $label,
            'pv_charge_share'   => $pvShare,
            'discharge_profile' => $profile,
            'months'            => $months,
            'charge_kwh'        => round($charge, 3),
            'discharge_kwh'     => round($discharge, 3),
            'gross_savings'     => round($gross, 2),
            'opportunity_cost'  => round($opportunity, 2),
            'net_savings'       => round($gross - $opportunity, 2),
            'efficiency'        => $charge > 0.0 ? round($discharge / $charge, 4) : null,
            'months_valued'     => $valued,
            'has_unsupported_months' => $unsupported,
            'has_untariffed_months'  => $untariffed,
            'currency'          => count($unique) === 1 ? $unique[0] : null,
        ];
    }

    /**
     * Agrégat du parc. `null` sans aucune batterie : un parc vide n'a pas de bilan
     * à zéro, il n'a pas de bilan du tout.
     *
     * Les mois sont fusionnés par clé calendaire pour que le parc ait, lui aussi,
     * une série mensuelle — deux batteries installées à des dates différentes n'ont
     * pas les mêmes mois.
     *
     * @param list<BatteryBalance> $balances
     * @return BatteryBalance|null
     */
    private function aggregate(array $balances): ?array
    {
        if ($balances === []) {
            return null;
        }
        if (count($balances) === 1) {
            return $balances[0];
        }

        /** @var array<string, BatteryMonth> $merged */
        $merged = [];
        foreach ($balances as $balance) {
            foreach ($balance['months'] as $month) {
                $key = $month['month'];
                if (!isset($merged[$key])) {
                    $merged[$key] = $month;
                    continue;
                }
                $merged[$key] = $this->mergeMonths($merged[$key], $month);
            }
        }

        ksort($merged);

        return $this->summarise(array_values($merged), 0, '', 0, '');
    }

    /**
     * Fusionne le même mois calendaire de deux batteries.
     *
     * Les TAUX ne sont pas fusionnés (`avoided_rate`, `injection_rate`,
     * `t1_share` passent à null) : ils dépendent des hypothèses propres à chaque
     * batterie, et une moyenne n'aurait aucun sens physique. Seules les quantités
     * et les montants s'additionnent.
     *
     * @param BatteryMonth $a
     * @param BatteryMonth $b
     * @return BatteryMonth
     */
    private function mergeMonths(array $a, array $b): array
    {
        $charge    = self::addNullable($a['charge_kwh'], $b['charge_kwh']);
        $discharge = self::addNullable($a['discharge_kwh'], $b['discharge_kwh']);

        return [
            'month'            => $a['month'],
            'charge_kwh'       => $charge,
            'discharge_kwh'    => $discharge,
            'avoided_rate'     => null,
            'injection_rate'   => null,
            't1_share'         => null,
            'gross_savings'    => self::addNullable($a['gross_savings'], $b['gross_savings']),
            'opportunity_cost' => self::addNullable($a['opportunity_cost'], $b['opportunity_cost']),
            'net_savings'      => self::addNullable($a['net_savings'], $b['net_savings']),
            'efficiency'       => ($charge !== null && $charge > 0.0 && $discharge !== null)
                ? round($discharge / $charge, 4)
                : null,
            'partial'          => $a['partial'] || $b['partial'],
            'unsupported_mode' => $a['unsupported_mode'] || $b['unsupported_mode'],
            'no_tariff'        => $a['no_tariff'] || $b['no_tariff'],
            'currency'         => $a['currency'] === $b['currency'] ? $a['currency'] : null,
        ];
    }

    /**
     * Somme de deux valeurs éventuellement absentes. `null + null` reste `null` :
     * deux mois sans mesure ne font pas un mois à zéro.
     */
    private static function addNullable(?float $a, ?float $b): ?float
    {
        if ($a === null && $b === null) {
            return null;
        }

        return round(($a ?? 0.0) + ($b ?? 0.0), 3);
    }
}
