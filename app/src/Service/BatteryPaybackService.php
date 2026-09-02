<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Battery;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * « En combien de temps ma batterie sera-t-elle amortie ? » (#26)
 *
 * ── Ce qui amortit ─────────────────────────────────────────────────────────
 * L'économie BRUTE, et elle seule : chaque kWh sorti de la batterie est un kWh
 * qui n'a pas été prélevé au réseau, donc pas payé. Le coût d'opportunité calculé
 * par {@see BatterySavingsService} reste un comparatif et n'entre pas ici — le
 * retrancher répondrait à une autre question que celle posée.
 *
 * ── Le rythme projeté ──────────────────────────────────────────────────────
 * Une batterie ne rapporte pas autant en décembre qu'en juillet : la projection
 * cherche donc d'abord une moyenne sur **12 mois glissants**, qui absorbe la
 * saison. Tant que l'historique n'y suffit pas, on retombe sur la moyenne depuis
 * la mise en service — utilisable dès le premier mois, mais biaisée par la saison
 * traversée. Ce repli est TOUJOURS signalé ({@see self::BASIS_SINCE_COMMISSIONING}
 * + `seasonally_biased`) : une estimation faite en plein été promettrait un
 * amortissement qui ne viendra pas.
 *
 * Les mois INCOMPLETS sont écartés du rythme (mois en cours, mois d'installation) :
 * les compter comme des mois pleins écraserait la moyenne vers le bas et
 * allongerait d'autant la date annoncée. Ils comptent en revanche dans l'économie
 * déjà acquise — c'est de l'argent réellement économisé.
 *
 * ── Situer l'amortissement dans la vie de la batterie ──────────────────────
 * Un amortissement projeté après la fin de garantie, ou au-delà des cycles
 * annoncés, n'est pas un amortissement : ces deux repères accompagnent donc la
 * date. Les cycles sont comptés en **cycles équivalents pleins** (énergie totale
 * déchargée ÷ capacité utile), la mesure usuelle du vieillissement.
 *
 * @phpstan-type BatteryPayback array{
 *     investment: float|null,
 *     savings: float,
 *     remaining: float|null,
 *     progress_pct: float|null,
 *     monthly_rate: float|null,
 *     basis: string|null,
 *     seasonally_biased: bool,
 *     months_remaining: float|null,
 *     payback_on: string|null,
 *     paid_back: bool,
 *     months_observed: int,
 *     beyond_horizon: bool,
 *     after_warranty: bool,
 *     cycles_used: float|null,
 *     rated_cycles: int|null,
 *     cycles_pct: float|null,
 * }
 */
final class BatteryPaybackService
{
    /** Rythme moyenné sur les 12 derniers mois calendaires : la saison est absorbée. */
    public const BASIS_ROLLING_12M = 'rolling_12m';

    /** Repli : moyenne depuis la mise en service. Disponible tôt, mais biaisée. */
    public const BASIS_SINCE_COMMISSIONING = 'since_commissioning';

    /** Fenêtre glissante, en mois. */
    private const ROLLING_MONTHS = 12;

    /**
     * Au-delà de cet horizon, la date n'est plus une information : la batterie
     * aurait cédé bien avant. On le dit (`beyond_horizon`) plutôt que d'afficher
     * une échéance en 2087 que personne ne peut prendre au sérieux.
     */
    private const MAX_PROJECTED_MONTHS = 600; // 50 ans

    /**
     * @param array<string, mixed> $balance Bilan de CETTE batterie, produit par
     *        {@see BatterySavingsService::balance()} (une entrée de `batteries`).
     * @param DateTimeImmutable|null $asOf Instant de référence (tests) ; maintenant
     *        en UTC par défaut, fuseau de stockage comme les bornes de mois.
     *
     * @return BatteryPayback
     */
    public function project(Battery $battery, array $balance, ?DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? Dates::nowUtc();

        /** @var list<array<string, mixed>> $months */
        $months  = is_array($balance['months'] ?? null) ? array_values($balance['months']) : [];
        $savings = (float) ($balance['gross_savings'] ?? 0.0);

        $rate = $this->monthlyRate($months);

        $result = [
            'investment'        => $battery->purchasePrice,
            'savings'           => round($savings, 2),
            'remaining'         => null,
            'progress_pct'      => null,
            'monthly_rate'      => $rate['value'] !== null ? round($rate['value'], 2) : null,
            'basis'             => $rate['basis'],
            'seasonally_biased' => $rate['basis'] === self::BASIS_SINCE_COMMISSIONING,
            'months_remaining'  => null,
            'payback_on'        => null,
            'paid_back'         => false,
            'months_observed'   => $rate['count'],
            'beyond_horizon'    => false,
            'after_warranty'    => false,
            'cycles_used'       => null,
            'rated_cycles'      => $battery->ratedCycles,
            'cycles_pct'        => null,
        ];

        $result = $this->withCycles($result, $battery, (float) ($balance['discharge_kwh'] ?? 0.0));

        // Sans prix d'achat, il n'y a rien à amortir : l'économie et le rendement
        // restent affichés, la projection est simplement absente. Mieux vaut ce
        // trou explicite qu'un investissement supposé.
        if ($battery->purchasePrice === null) {
            return $result;
        }

        $remaining = max(0.0, $battery->purchasePrice - $savings);

        $result['remaining']    = round($remaining, 2);
        $result['paid_back']    = $remaining <= 0.0;
        $result['progress_pct'] = $battery->purchasePrice > 0.0
            ? round(min(100.0, $savings / $battery->purchasePrice * 100.0), 1)
            : 100.0;

        if ($result['paid_back'] || $rate['value'] === null || $rate['value'] <= 0.0) {
            return $result;
        }

        $monthsRemaining = $remaining / $rate['value'];
        if ($monthsRemaining > self::MAX_PROJECTED_MONTHS) {
            $result['beyond_horizon'] = true;

            return $result;
        }

        // Arrondi au mois SUPÉRIEUR : c'est le premier mois où le cumul dépasse
        // l'investissement, pas celui où il l'atteint à moitié.
        $payback = $asOf->setTimezone(Dates::utc())
            ->modify('first day of this month')
            ->modify('+' . (int) ceil($monthsRemaining) . ' months');

        $result['months_remaining'] = round($monthsRemaining, 1);
        $result['payback_on']       = $payback->format('Y-m');
        $result['after_warranty']   = $battery->warrantyUntil !== null
            && $payback >= $battery->warrantyUntil->setTimezone(Dates::utc())->modify('first day of this month')->setTime(0, 0, 0);

        return $result;
    }

    /**
     * Rythme mensuel d'économie brute, et la base sur laquelle il est établi.
     *
     * Les mois retenus sont ceux qui sont à la fois **valorisés** (un contrat
     * indexé ou une période sans grille n'en donne aucun) et **complets** : un mois
     * en cours, ou le mois d'installation, ne représente pas un mois de
     * fonctionnement et tirerait la moyenne vers le bas.
     *
     * @param list<array<string, mixed>> $months
     * @return array{value: float|null, basis: string|null, count: int}
     */
    private function monthlyRate(array $months): array
    {
        /** @var list<array{month: string, savings: float}> $eligible */
        $eligible = [];
        foreach ($months as $month) {
            $savings = $month['gross_savings'] ?? null;
            if (!is_float($savings) && !is_int($savings)) {
                continue;
            }
            if (($month['partial'] ?? false) === true) {
                continue;
            }

            $eligible[] = ['month' => (string) $month['month'], 'savings' => (float) $savings];
        }

        if ($eligible === []) {
            return ['value' => null, 'basis' => null, 'count' => 0];
        }

        // Historique assez long pour absorber une saison complète ? On le mesure sur
        // l'ÉTENDUE des mois du bilan, pas sur leur nombre : douze mois dont la
        // moitié est manquante ne couvrent pas une année.
        $rolling = $this->rollingWindow($eligible);
        if ($rolling !== null) {
            return ['value' => self::average($rolling), 'basis' => self::BASIS_ROLLING_12M, 'count' => count($rolling)];
        }

        return [
            'value' => self::average($eligible),
            'basis' => self::BASIS_SINCE_COMMISSIONING,
            'count' => count($eligible),
        ];
    }

    /**
     * Les mois éligibles des 12 derniers mois calendaires, ou `null` si le bilan ne
     * couvre pas encore une année entière.
     *
     * @param list<array{month: string, savings: float}> $eligible Triés par mois croissant.
     * @return list<array{month: string, savings: float}>|null
     */
    private function rollingWindow(array $eligible): ?array
    {
        $first = self::monthStart($eligible[0]['month']);
        $last  = self::monthStart($eligible[count($eligible) - 1]['month']);

        // Étendue < 12 mois : la fenêtre glissante n'aurait pas plus de contenu que
        // la moyenne complète, et prétendrait pourtant absorber la saison.
        $windowStart = $last->modify('-' . (self::ROLLING_MONTHS - 1) . ' months');
        if ($first > $windowStart) {
            return null;
        }

        $window = array_values(array_filter(
            $eligible,
            static fn (array $month): bool => self::monthStart($month['month']) >= $windowStart,
        ));

        return $window === [] ? null : $window;
    }

    /**
     * Cycles équivalents pleins consommés : énergie totale déchargée rapportée à la
     * capacité utile. Mesure usuelle du vieillissement — un cycle « équivalent »
     * agrège les décharges partielles, qui sont le régime réel d'une batterie
     * domestique.
     *
     * @param BatteryPayback $result
     * @return BatteryPayback
     */
    private function withCycles(array $result, Battery $battery, float $dischargeKwh): array
    {
        $capacity = $battery->effectiveCapacityKwh();
        if ($capacity <= 0.0 || $dischargeKwh <= 0.0) {
            return $result;
        }

        $cycles = $dischargeKwh / $capacity;
        $result['cycles_used'] = round($cycles, 1);

        if ($battery->ratedCycles !== null && $battery->ratedCycles > 0) {
            $result['cycles_pct'] = round($cycles / $battery->ratedCycles * 100.0, 1);
        }

        return $result;
    }

    /** @param list<array{month: string, savings: float}> $months */
    private static function average(array $months): float
    {
        $total = 0.0;
        foreach ($months as $month) {
            $total += $month['savings'];
        }

        return $total / count($months);
    }

    /** Premier jour du mois 'YYYY-MM', en UTC (fuseau des bornes du bilan). */
    private static function monthStart(string $month): DateTimeImmutable
    {
        return new DateTimeImmutable($month . '-01 00:00:00', Dates::utc());
    }
}
