<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ComponentKind;
use App\Domain\EuropeanCountries;
use App\Domain\TariffGrid;
use App\Domain\TariffUnitRate;
use App\Repository\Contract\StatisticsRepositoryInterface;
use App\Repository\StatisticsRepository;
use DateTimeImmutable;

/**
 * Compose les statistiques communautaires de /stats (#8).
 *
 * Le service ne fait AUCUNE requête : le repository lui remet des agrégats déjà
 * k-anonymisés, et il se charge de ce qui relève du choix éditorial — quelle
 * devise fait référence pour un pays, quelle résolution de prix spot afficher,
 * comment situer un foyer dans son pays. Séparation utile : la garantie de
 * confidentialité tient dans un seul fichier, celui qui écrit le SQL.
 *
 * Il ne connaît pas non plus la locale : il renvoie des codes ISO que le template
 * traduit via {@see EuropeanCountries::name()}. Le JS n'ayant pas accès à ext-intl,
 * c'est le template qui lui passe les noms résolus.
 *
 * ARRONDIS DE PUBLICATION — ce n'est pas de la cosmétique. Le k-anonymat protège
 * mal contre l'observation répétée : si un pays passe de 5 à 6 foyers, la
 * différence entre les deux moyennes publiées livre exactement la valeur du
 * nouvel arrivant. Arrondir la publication rend cette soustraction beaucoup moins
 * précise. Ça n'élimine pas l'attaque (il faudrait du bruit aléatoire, hors
 * périmètre ici) : c'est une atténuation, documentée comme telle dans /privacy.
 *
 * @phpstan-type CountryPrice array{country: string, is_other: bool, currency: string,
 *     households: int, ttc_per_kwh: float|null, htva_per_kwh: float|null, fixed_year_ttc: float|null}
 * @phpstan-type CountryUsage array{country: string, is_other: bool, households: int, value: float}
 * @phpstan-type PricingMix array{country: string, is_other: bool, fixed: int, dynamic: int, dynamic_pct: float}
 * @phpstan-type SpotZone array{zone: string, resolution_min: int, avg_price: float, points: int,
 *     first_at: string, last_at: string}
 * @phpstan-type StatsOverview array{window: array{from: string, to: string, months: int},
 *     k: int, coverage: array{households: int, countries: int}, prices: list<CountryPrice>,
 *     electricity: list<CountryUsage>, gas: list<CountryUsage>, water: list<CountryUsage>,
 *     mix: list<PricingMix>, spot: list<SpotZone>, has_data: bool}
 * @phpstan-type MonthPoint array{month: string, mine: float|null, average: float|null, delta_pct: float|null}
 * @phpstan-type CategoryPoint array{category: string, mine: float|null, average: float|null}
 * @phpstan-type PrivateStats array{country: string, currency: string, opted_out: bool,
 *     households: int, my_rate: float|null, country_rate: float|null, rate_delta_pct: float|null,
 *     my_net_cost: float|null, my_usage: float|null, country_usage: float|null,
 *     usage_delta_pct: float|null, rate_percentile: int|null, usage_percentile: int|null,
 *     monthly: list<MonthPoint>, categories: list<CategoryPoint>}
 */
final class StatisticsService
{
    /** Pas d'arrondi des tarifs publiés, en unité monétaire (0,001 €/kWh). */
    private const RATE_STEP = 0.001;

    /** Pas d'arrondi des consommations publiées (10 kWh ou 10 m³ par an). */
    private const USAGE_STEP = 10.0;

    /** @var StatsOverview|null Mémoïsation requête-scopée (cf. {@see publicOverview()}). */
    private ?array $overview = null;

    public function __construct(private readonly StatisticsRepositoryInterface $stats)
    {
    }

    /**
     * Statistiques publiques du site.
     *
     * Mémoïsé pour la durée de la requête : la page les affiche une fois, et le
     * bloc privé les relit pour situer le foyer. Pas de cache inter-requêtes —
     * APCu n'est pas une dépendance déclarée du projet (le chemin de code serait
     * absent en CLI comme en CI, donc jamais testé), et un cache fichier
     * demanderait un répertoire inscriptible que le déploiement ne garantit pas.
     * Si la page devient coûteuse sur un gros corpus, la marche suivante est une
     * couche APCu opportuniste, pas une table d'agrégats précalculés.
     *
     * @return StatsOverview
     */
    public function publicOverview(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        if ($this->overview !== null) {
            return $this->overview;
        }

        [$defaultFrom, $defaultTo] = StatisticsRepository::defaultWindow();
        $from ??= $defaultFrom;
        $to   ??= $defaultTo;

        $prices = $this->composePrices($this->stats->unitRateByCountry());
        $elec   = $this->composeUsage($this->stats->electricityUsageByCountry($from, $to));
        $gas    = $this->composeUsage($this->stats->utilityUsageByCountry('gas', $from, $to));
        $water  = $this->composeUsage($this->stats->utilityUsageByCountry('water', $from, $to));
        $mix    = $this->composeMix($this->stats->pricingModeByCountry());
        $spot   = $this->composeSpot($this->stats->spotPriceByZone($from, $to));

        $this->overview = [
            'window'      => [
                'from'   => $from->format('Y-m-d'),
                'to'     => $to->format('Y-m-d'),
                'months' => StatisticsRepository::WINDOW_MONTHS,
            ],
            'k'           => StatisticsRepositoryInterface::MIN_HOUSEHOLDS,
            'coverage'    => $this->stats->coverage(),
            'prices'      => $prices,
            'electricity' => $elec,
            'gas'         => $gas,
            'water'       => $water,
            'mix'         => $mix,
            'spot'        => $spot,
            'has_data'    => $prices !== [] || $elec !== [] || $gas !== [] || $water !== [],
        ];

        return $this->overview;
    }

    /**
     * Comparaison du foyer connecté à la moyenne de son pays.
     *
     * Renvoie null quand il n'y a rien à comparer : pas de pays au profil. La vue
     * affiche alors une invite à le renseigner plutôt qu'un bloc vide.
     *
     * DEUX INDICATEURS DE PRIX, et c'est délibéré. « Mon tarif » se calcule avec
     * exactement les mêmes composantes et les mêmes poids que la moyenne du pays
     * ({@see TariffUnitRate}), donc les deux se comparent. « Mon coût réel »
     * ({@see TariffCalculatorService::netCostPerKwh()}) inclut l'abonnement et les
     * forfaits annuels : le comparer à une moyenne de taux variables ferait
     * paraître tout petit consommateur 40 % plus cher que la moyenne du seul fait
     * de son abonnement. Il est donc affiché seul.
     *
     * @param array<string, mixed>|null $breakdown Décompte électricité sur la fenêtre,
     *        {@see CostCalculationService::estimatePeriodElectricity()}. null si indisponible.
     * @return PrivateStats|null
     */
    public function privateComparison(
        int $userId,
        ?string $country,
        string $currency,
        bool $optedOut,
        ?TariffGrid $activeGrid,
        ?array $breakdown,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): ?array {
        if ($country === null || $country === '') {
            return null;
        }

        [$defaultFrom, $defaultTo] = StatisticsRepository::defaultWindow();
        $from ??= $defaultFrom;
        $to   ??= $defaultTo;

        $overview = $this->publicOverview($from, $to);

        $myRate = $activeGrid !== null
            ? TariffUnitRate::fromCalculationTariff($activeGrid->toCalculationTariff())
            : null;

        $countryPrice = $this->findCountry($overview['prices'], $country);
        $countryRate  = $countryPrice['ttc_per_kwh'] ?? null;

        $mySeries      = $this->stats->householdMonthlySeries($userId, $from, $to);
        $countrySeries = $this->stats->countryMonthlySeries($country, $from, $to);

        $myUsage      = $this->annualise($mySeries);
        $countryUsage = ($this->findCountry($overview['electricity'], $country)['value'] ?? null);

        $rateRank  = $myRate !== null ? $this->stats->rateRankInCountry($country, $myRate) : null;
        $usageRank = $myUsage !== null ? $this->stats->usageRankInCountry($country, $myUsage, $from, $to) : null;

        return [
            'country'          => $country,
            'currency'         => $countryPrice['currency'] ?? $currency,
            'opted_out'        => $optedOut,
            'households'       => $countryPrice['households'] ?? 0,
            'my_rate'          => $myRate,
            'country_rate'     => $countryRate,
            'rate_delta_pct'   => self::deltaPct($myRate, $countryRate),
            'my_net_cost'      => $breakdown !== null ? TariffCalculatorService::netCostPerKwh($breakdown) : null,
            'my_usage'         => $myUsage,
            'country_usage'    => $countryUsage,
            'usage_delta_pct'  => self::deltaPct($myUsage, $countryUsage),
            'rate_percentile'  => self::percentile($rateRank),
            'usage_percentile' => self::percentile($usageRank),
            'monthly'          => $this->composeMonthly($mySeries, $countrySeries, $from, $to),
            'categories'       => $this->composeCategories($country, $activeGrid),
        ];
    }

    /**
     * Écart relatif en %, ou null si incalculable.
     *
     * Une référence nulle ou absente ne donne pas 0 % mais null : la vue affiche
     * un tiret, jamais un « identique à la moyenne » qui serait faux.
     */
    public static function deltaPct(?float $mine, ?float $reference): ?float
    {
        if ($mine === null || $reference === null || $reference <= 0.0) {
            return null;
        }

        return round(($mine - $reference) / $reference * 100.0, 1);
    }

    /**
     * @param array{below: int, total: int}|null $rank
     */
    private static function percentile(?array $rank): ?int
    {
        if ($rank === null || $rank['total'] <= 0) {
            return null;
        }

        return (int) round($rank['below'] / $rank['total'] * 100.0);
    }

    /**
     * Devise de référence d'un pays et arrondi de publication.
     *
     * Un pays peut porter plusieurs devises (un foyer expatrié, une grille saisie
     * en CHF). Le projet ne convertit jamais : on retient le groupe dont la devise
     * est celle attendue du pays, à défaut le plus peuplé, et on écarte les autres
     * plutôt que d'additionner des € et des francs.
     *
     * Le bucket « Autres » n'affiche AUCUN prix : y mélanger des devises n'aurait
     * pas de sens, et il ne désigne de toute façon aucun pays.
     *
     * @param list<array{bucket: string, currency: string, households: int, ttc_per_kwh: float,
     *     htva_per_kwh: float, fixed_year_ttc: float}> $rows
     * @return list<CountryPrice>
     */
    private function composePrices(array $rows): array
    {
        /** @var array<string, list<array{bucket: string, currency: string, households: int, ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float}>> $byBucket */
        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[$row['bucket']][] = $row;
        }

        $out = [];
        foreach ($byBucket as $bucket => $candidates) {
            $isOther = $bucket === StatisticsRepositoryInterface::OTHER_BUCKET;

            if ($isOther) {
                $households = 0;
                foreach ($candidates as $candidate) {
                    $households += $candidate['households'];
                }
                $out[] = [
                    'country'        => $bucket,
                    'is_other'       => true,
                    'currency'       => '',
                    'households'     => $households,
                    'ttc_per_kwh'    => null,
                    'htva_per_kwh'   => null,
                    'fixed_year_ttc' => null,
                ];
                continue;
            }

            $chosen = $this->pickCurrency($bucket, $candidates);
            $out[]  = [
                'country'        => $bucket,
                'is_other'       => false,
                'currency'       => $chosen['currency'],
                'households'     => $chosen['households'],
                'ttc_per_kwh'    => self::roundTo($chosen['ttc_per_kwh'], self::RATE_STEP),
                'htva_per_kwh'   => self::roundTo($chosen['htva_per_kwh'], self::RATE_STEP),
                'fixed_year_ttc' => self::roundTo($chosen['fixed_year_ttc'], 1.0),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$a['is_other'], $a['country']] <=> [$b['is_other'], $b['country']]);

        return $out;
    }

    /**
     * @param list<array{bucket: string, currency: string, households: int, ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float}> $candidates
     * @return array{bucket: string, currency: string, households: int, ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float}
     */
    private function pickCurrency(string $country, array $candidates): array
    {
        $expected = EuropeanCountries::currencyOf($country);
        foreach ($candidates as $candidate) {
            if ($expected !== null && $candidate['currency'] === $expected) {
                return $candidate;
            }
        }

        // Pays hors référentiel, ou aucune grille dans la devise attendue : le
        // groupe le plus peuplé fait référence.
        usort($candidates, static fn (array $a, array $b): int => $b['households'] <=> $a['households']);

        return $candidates[0];
    }

    /**
     * @param list<array{bucket: string, households: int, value: float}> $rows
     * @return list<CountryUsage>
     */
    private function composeUsage(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'country'    => $row['bucket'],
                'is_other'   => $row['bucket'] === StatisticsRepositoryInterface::OTHER_BUCKET,
                'households' => $row['households'],
                'value'      => (float) self::roundTo($row['value'], self::USAGE_STEP),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$a['is_other'], $a['country']] <=> [$b['is_other'], $b['country']]);

        return $out;
    }

    /**
     * @param list<array{bucket: string, fixed: int, dynamic: int}> $rows
     * @return list<PricingMix>
     */
    private function composeMix(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $total = $row['fixed'] + $row['dynamic'];
            $out[] = [
                'country'     => $row['bucket'],
                'is_other'    => $row['bucket'] === StatisticsRepositoryInterface::OTHER_BUCKET,
                'fixed'       => $row['fixed'],
                'dynamic'     => $row['dynamic'],
                'dynamic_pct' => $total > 0 ? round($row['dynamic'] / $total * 100.0, 1) : 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$a['is_other'], $a['country']] <=> [$b['is_other'], $b['country']]);

        return $out;
    }

    /**
     * Une entrée par zone : la résolution horaire est préférée quand elle existe.
     *
     * ENTSO-E publie pour certaines zones du PT60M et du PT15M sur les mêmes
     * heures. Le repository les sépare pour ne pas compter deux fois la même
     * heure ; ici on choisit, et on expose la résolution retenue pour que la vue
     * puisse la mentionner.
     *
     * @param list<SpotZone> $rows
     * @return list<SpotZone>
     */
    private function composeSpot(array $rows): array
    {
        /** @var array<string, SpotZone> $best */
        $best = [];
        foreach ($rows as $row) {
            $current = $best[$row['zone']] ?? null;
            if ($current === null || ($current['resolution_min'] !== 60 && $row['resolution_min'] === 60)) {
                $best[$row['zone']] = $row;
            }
        }

        $out = array_values($best);
        usort($out, static fn (array $a, array $b): int => $a['zone'] <=> $b['zone']);

        return $out;
    }

    /**
     * Douze points fixes : mon mois, la moyenne du pays, l'écart.
     *
     * Un mois manquant d'un côté ou de l'autre vaut null et non zéro — c'est un
     * trou dans la courbe (relevé absent, ou mois sous le seuil de k-anonymat),
     * pas une consommation nulle.
     *
     * @param array<string, float> $mine
     * @param array<string, float> $country
     * @return list<MonthPoint>
     */
    private function composeMonthly(
        array $mine,
        array $country,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $out    = [];
        $cursor = $from->modify('first day of this month')->setTime(0, 0, 0);

        while ($cursor < $to) {
            $key      = $cursor->format('Y-m');
            $myValue  = isset($mine[$key]) ? round($mine[$key], 1) : null;
            $avgValue = isset($country[$key]) ? (float) self::roundTo($country[$key], 1.0) : null;

            $out[] = [
                'month'     => $key,
                'mine'      => $myValue,
                'average'   => $avgValue,
                'delta_pct' => self::deltaPct($myValue, $avgValue),
            ];

            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * Comparaison poste par poste, en €/kWh.
     *
     * Ne portent que les composantes variables : abonnements et crédits
     * d'injection en sont absents par construction (poids nul dans
     * {@see TariffUnitRate}), comme dans la moyenne du pays. La vue doit le dire.
     *
     * @return list<CategoryPoint>
     */
    private function composeCategories(string $country, ?TariffGrid $activeGrid): array
    {
        $averages = [];
        foreach ($this->stats->unitRateByCountryAndCategory() as $row) {
            if ($row['bucket'] === $country) {
                $averages[$row['category']] = $row['ttc_per_kwh'];
            }
        }

        $mine = [];
        if ($activeGrid !== null) {
            foreach ($activeGrid->toCalculationTariff()['lines'] as $line) {
                $kind   = ComponentKind::fromStringOrDefault($line['kind']);
                $weight = TariffUnitRate::weight($kind);
                if ($weight === 0.0) {
                    continue;
                }

                $category = $line['category'] !== '' ? $line['category'] : $kind->group();
                $mine[$category] = ($mine[$category] ?? 0.0) + $line['amount'] * $weight;
            }
        }

        $categories = array_unique(array_merge(array_keys($mine), array_keys($averages)));
        sort($categories);

        $out = [];
        foreach ($categories as $category) {
            $out[] = [
                'category' => $category,
                'mine'     => isset($mine[$category]) ? round($mine[$category], 4) : null,
                'average'  => isset($averages[$category]) ? self::roundTo($averages[$category], self::RATE_STEP) : null,
            ];
        }

        return $out;
    }

    /**
     * Consommation annualisée déduite d'une série mensuelle.
     *
     * Même règle que le SQL agrégé — moyenne mensuelle × 12, et au moins trois
     * mois observés — sans quoi « moi » et « la moyenne » ne seraient pas
     * calculés de la même façon et l'écart affiché serait faux.
     *
     * @param array<string, float> $series
     */
    private function annualise(array $series): ?float
    {
        $months = count($series);
        if ($months < 3) {
            return null;
        }

        $total = array_sum($series);

        return $total > 0.0 ? round($total / $months * 12.0, 1) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findCountry(array $rows, string $country): ?array
    {
        foreach ($rows as $row) {
            if (($row['country'] ?? null) === $country) {
                return $row;
            }
        }

        return null;
    }

    /** Arrondi de publication (cf. la note du docblock de classe). */
    private static function roundTo(?float $value, float $step): ?float
    {
        if ($value === null || $step <= 0.0) {
            return $value;
        }

        return round(round($value / $step) * $step, 6);
    }
}
