<?php

declare(strict_types=1);

namespace App\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Quels mois de profil de charge DEVRAIENT être en base, et lesquels manquent (#101).
 *
 * Le RLP est un profil mesuré : les coefficients d'un mois ne paraissent qu'après sa
 * clôture, et il faut donc les importer chaque mois. Un oubli ne se voit pas — le
 * calcul retombe sans bruit sur le baseload ({@see MonthlyIndexedPriceCalculator}) et
 * le rapprochement écarte le mois. Ce service le rend visible, pour la page
 * d'administration comme pour le cron de notification.
 *
 * Service PUR : il reçoit les grilles qui désignent un profil et les décomptes de
 * points, et ne touche à rien d'autre.
 *
 * Un mois est EXIGÉ quand :
 *  - une grille `indexed_monthly` désignant ce profil le couvre, au moins en partie ;
 *  - il est clos depuis au moins `graceDays` jours (Synergrid publie après coup, sans
 *    calendrier fixe — exiger le mois le 1er ferait sonner une alerte inévitable) ;
 *  - il tombe dans les `lookbackMonths` derniers mois exigibles (au-delà, un trou
 *    ancien relève d'un rattrapage, pas d'une alerte récurrente).
 *
 * Un mois est COMPLET quand ses points couvrent au moins
 * {@see self::MIN_COVERAGE_PCT} % des créneaux du mois, à la résolution que le calcul
 * lit pour ce profil (15 min dès qu'il en existe, l'heure sinon).
 * Un fichier tronqué compte donc comme manquant : le calcul, lui, l'utiliserait tel
 * quel (il pondère sur les créneaux communs), mais sur une partie du mois seulement.
 */
final class LoadProfileFreshness
{
    /** Même seuil que la couverture des cotations dans le calcul mensuel. */
    public const MIN_COVERAGE_PCT = MonthlyIndexedPriceCalculator::PRICE_COVERAGE_MIN_PCT;

    /** Page Synergrid de téléchargement des profils SLP / SPP / RLP (pas d'API). */
    public const DOWNLOAD_URL = 'https://www.synergrid.be/fr/centre-de-documentation/statistiques-et-donnees/profils-slp-spp-rlp';

    /** Chemin de la page d'import, relatif à la racine de l'application. */
    public const UPLOAD_PATH = 'admin/load-profiles';

    public const DEFAULT_GRACE_DAYS = 5;

    public const DEFAULT_LOOKBACK_MONTHS = 12;

    public function __construct(
        private readonly int $graceDays = self::DEFAULT_GRACE_DAYS,
        private readonly int $lookbackMonths = self::DEFAULT_LOOKBACK_MONTHS,
    ) {
    }

    /**
     * Dernier mois exigible à $now : clos depuis au moins `graceDays` jours.
     */
    public function latestRequiredMonth(DateTimeImmutable $now): string
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        return $today->sub(new DateInterval('P' . max(0, $this->graceDays) . 'D'))
            ->modify('first day of this month')
            ->modify('-1 month')
            ->format('Y-m');
    }

    /**
     * Mois exigés par profil, du plus ancien au plus récent.
     *
     * @param list<array{code: string, country: string, valid_from: string, valid_to: ?string}> $usage
     *        Grilles désignant un profil ({@see \App\Repository\LoadProfileRepository::profilesInUse()}).
     * @return array<string, array{code: string, country: string, months: list<string>}> clé « CODE/PAYS »
     */
    public function requiredMonths(array $usage, DateTimeImmutable $now): array
    {
        $latest   = $this->latestRequiredMonth($now);
        $earliest = (new DateTimeImmutable($latest . '-01', new DateTimeZone('UTC')))
            ->modify('-' . max(0, $this->lookbackMonths - 1) . ' months')
            ->format('Y-m');

        $out = [];
        foreach ($usage as $grid) {
            // Fin EXCLUE (#1) : le dernier jour couvert est la veille de valid_to.
            $first = substr($grid['valid_from'], 0, 7);
            $last  = $grid['valid_to'] === null
                ? $latest
                : (new DateTimeImmutable($grid['valid_to'], new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m');

            // Contrat clos avant la fenêtre : il n'exigera plus jamais rien, et une
            // entrée vide se lirait « rien à importer POUR L'INSTANT ». Un contrat
            // futur, lui, garde son entrée vide — le message est alors juste.
            if ($last < $earliest) {
                continue;
            }

            $key = $grid['code'] . '/' . $grid['country'];
            $out[$key] ??= ['code' => $grid['code'], 'country' => $grid['country'], 'months' => []];

            $from = max($first, $earliest);
            $to   = min($last, $latest);
            for ($month = $from; $month <= $to; $month = self::nextMonth($month)) {
                $out[$key]['months'][] = $month;
            }
        }

        foreach ($out as $key => $profile) {
            $months = array_values(array_unique($profile['months']));
            sort($months);
            $out[$key]['months'] = $months;
        }

        return $out;
    }

    /**
     * Couverture d'un mois en %, la meilleure des deux résolutions.
     *
     * @param array<int, int> $pointsByResolution resolution_min => points
     */
    public static function coveragePct(string $month, array $pointsByResolution): float
    {
        $start   = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        $seconds = $start->modify('+1 month')->getTimestamp() - $start->getTimestamp();

        $best = 0.0;
        foreach ($pointsByResolution as $resolution => $points) {
            if ($resolution <= 0) {
                continue;
            }
            $best = max($best, $points / ($seconds / ($resolution * 60)) * 100.0);
        }

        return min(100.0, $best);
    }

    /**
     * Couverture de chaque mois exigé, par profil.
     *
     * @param list<array{code: string, country: string, valid_from: string, valid_to: ?string}> $usage
     * @param callable(string, string, DateTimeImmutable, DateTimeImmutable): array<string, array<int, int>> $pointsFor
     *        Décompte des points d'un profil sur une fenêtre, mois => résolution => points
     *        ({@see \App\Repository\LoadProfileRepository::pointsByMonth()}).
     * @return list<array{code: string, country: string, months: array<string, float>}> mois => couverture %
     */
    public function report(array $usage, callable $pointsFor, DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($this->requiredMonths($usage, $now) as $profile) {
            $months = [];
            if ($profile['months'] !== []) {
                [$from, $to] = self::window($profile['months'][0], $profile['months'][count($profile['months']) - 1]);
                $points      = self::resolutionTheCalculatorReads($pointsFor($profile['code'], $profile['country'], $from, $to));
                foreach ($profile['months'] as $month) {
                    $months[$month] = self::coveragePct($month, $points[$month] ?? []);
                }
            }
            $out[] = ['code' => $profile['code'], 'country' => $profile['country'], 'months' => $months];
        }

        return $out;
    }

    /**
     * Ne garde que la résolution que le calcul lira, comme sa cascade
     * ({@see CostCalculationService}) : le pas de 15 min dès qu'il en existe un point
     * sur la fenêtre, l'heure sinon. Un mois importé à 60 min au milieu d'une série
     * quart-horaire n'est PAS lu par le calcul — le compter complet masquerait
     * exactement le repli silencieux que ce service doit signaler.
     *
     * @param array<string, array<int, int>> $points mois => résolution => points
     * @return array<string, array<int, int>>
     */
    private static function resolutionTheCalculatorReads(array $points): array
    {
        $has15 = false;
        foreach ($points as $byResolution) {
            if (($byResolution[15] ?? 0) > 0) {
                $has15 = true;
                break;
            }
        }
        $keep = $has15 ? 15 : 60;

        return array_map(
            static fn (array $byResolution): array => array_intersect_key($byResolution, [$keep => true]),
            $points,
        );
    }

    /**
     * Mois exigés absents ou incomplets, du plus ancien au plus récent par profil.
     *
     * @param list<array{code: string, country: string, months: array<string, float>}> $report {@see self::report()}
     * @return list<array{code: string, country: string, month: string, coverage_pct: float}>
     */
    public static function missing(array $report): array
    {
        $out = [];
        foreach ($report as $profile) {
            foreach ($profile['months'] as $month => $pct) {
                if (!self::isComplete($pct)) {
                    $out[] = ['code' => $profile['code'], 'country' => $profile['country'], 'month' => (string) $month, 'coverage_pct' => $pct];
                }
            }
        }

        return $out;
    }

    public static function isComplete(float $coveragePct): bool
    {
        return $coveragePct >= self::MIN_COVERAGE_PCT;
    }

    /**
     * Bornes UTC `[début, fin[` couvrant les mois $firstMonth à $lastMonth inclus.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function window(string $firstMonth, string $lastMonth): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            new DateTimeImmutable($firstMonth . '-01', $utc),
            (new DateTimeImmutable($lastMonth . '-01', $utc))->modify('+1 month'),
        ];
    }

    private static function nextMonth(string $month): string
    {
        return (new DateTimeImmutable($month . '-01', new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m');
    }
}
