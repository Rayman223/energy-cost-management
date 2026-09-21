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
 * @phpstan-type OverallPrice array{currency: string, households: int, ttc_per_kwh: float|null,
 *     htva_per_kwh: float|null, fixed_year_ttc: float|null}
 * @phpstan-type OverallUsage array{households: int, value: float}
 * @phpstan-type OverallSummary array{households: int, countries: int, prices: list<OverallPrice>,
 *     electricity: OverallUsage|null, gas: OverallUsage|null, water: OverallUsage|null,
 *     mix: array{fixed: int, dynamic: int, dynamic_pct: float}|null, has_data: bool}
 * @phpstan-type CountryDetail array{country: string, is_other: bool, households: int,
 *     currency: string, ttc_per_kwh: float|null, htva_per_kwh: float|null,
 *     fixed_year_ttc: float|null, electricity: float|null, gas: float|null, water: float|null,
 *     dynamic_pct: float|null, rate_delta_pct: float|null, usage_delta_pct: float|null}
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
     * Portée du corpus : foyers contributeurs et pays couverts (#85).
     *
     * Une seule requête, là où {@see publicOverview()} en lance sept. La page
     * d'accueil publique n'affiche que ces deux chiffres et c'est la page la
     * plus visitée du site — y déclencher tous les agrégats par pays
     * (tarifs, trois consommations sur douze mois, mix, prix spot) coûterait
     * sur chaque visite anonyme et chaque passage de robot.
     *
     * Déjà mémoïsé si l'aperçu complet a été calculé : on ne relit pas la base
     * pour une valeur qu'on a en main.
     *
     * @return array{households: int, countries: int}
     */
    public function coverage(): array
    {
        return $this->overview['coverage'] ?? $this->stats->coverage();
    }

    /**
     * Récapitulatif **tous pays confondus** (#85).
     *
     * Dérivé de {@see publicOverview()} — aucune requête supplémentaire, et
     * surtout aucun nouvel agrégat : c'est une exigence de confidentialité, pas
     * une optimisation. Un global calculé en SQL engloberait les foyers que le
     * k-anonymat masque ; le soustraire des lignes publiées livrerait alors
     * l'agrégat du résidu, c'est-à-dire de moins de cinq foyers. En ne sommant
     * que ce qui est déjà affiché, le récapitulatif n'apprend rien qu'un
     * lecteur ne puisse recalculer à la main.
     *
     * Moyennes **pondérées par le nombre de foyers** : un pays à 400 foyers ne
     * doit pas peser autant qu'un pays à 6.
     *
     * Les prix restent **séparés par devise**. Le corpus est européen mais pas
     * uniquement en euros, et additionner des couronnes à des euros produirait
     * un nombre qui ne veut rien dire — le repository refuse déjà de le faire
     * en SQL, ce n'est pas pour le faire ici.
     *
     * @return OverallSummary
     */
    public function overallSummary(): array
    {
        $overview = $this->publicOverview();

        $countries = [];
        foreach (['prices', 'electricity', 'gas', 'water'] as $section) {
            foreach ($overview[$section] as $row) {
                if ($row['is_other'] === false) {
                    $countries[$row['country']] = true;
                }
            }
        }

        $prices = $this->overallPrices($overview['prices']);
        $elec   = self::overallUsage($overview['electricity']);
        $gas    = self::overallUsage($overview['gas']);
        $water  = self::overallUsage($overview['water']);
        $mix    = self::overallMix($overview['mix']);

        // Foyers qui alimentent RÉELLEMENT les chiffres publiés, et non tous
        // les contributeurs déclarés que compte `coverage()` : un compte au
        // profil complet mais sans grille ni relevé ne pèse dans aucun agrégat,
        // et l'annoncer à côté de moyennes laisse croire qu'il y a compté.
        //
        // Les ensembles se recoupent sans qu'on puisse les recomposer depuis
        // les lignes publiées (un même foyer peut porter un tarif ET une
        // consommation) : on retient le plus grand, qui est la borne basse
        // exacte — « au moins tant de foyers derrière ces chiffres ».
        $households = 0;
        foreach ([$elec, $gas, $water] as $usage) {
            $households = max($households, $usage['households'] ?? 0);
        }
        foreach ($prices as $price) {
            $households = max($households, $price['households']);
        }
        if ($mix !== null) {
            $households = max($households, $mix['fixed'] + $mix['dynamic']);
        }

        return [
            'households'  => $households,
            'countries'   => \count($countries),
            'prices'      => $prices,
            'electricity' => $elec,
            'gas'         => $gas,
            'water'       => $water,
            'mix'         => $mix,
            // `mix` compris : le bloc porte aussi la carte « contrats à prix
            // dynamique », et la masquer alors qu'elle a un chiffre à montrer
            // perdrait la seule donnée publiée d'un corpus encore jeune.
            'has_data'    => $prices !== [] || $elec !== null || $gas !== null || $water !== null || $mix !== null,
        ];
    }

    /**
     * Moyenne pondérée des tarifs, une ligne par devise.
     *
     * Le bucket « Autres pays » est écarté : il ne porte aucun prix (mélanger
     * les devises n'aurait pas de sens, cf. {@see composePrices()}), et ses
     * foyers ne peuvent donc pondérer personne.
     *
     * @param list<CountryPrice> $rows
     * @return list<OverallPrice>
     */
    private function overallPrices(array $rows): array
    {
        /** @var array<string, array{households: int, ttc: float, htva: float, fixed: float}> $byCurrency */
        $byCurrency = [];
        foreach ($rows as $row) {
            if ($row['is_other'] || $row['ttc_per_kwh'] === null || $row['households'] <= 0) {
                continue;
            }

            $bucket = $byCurrency[$row['currency']] ?? ['households' => 0, 'ttc' => 0.0, 'htva' => 0.0, 'fixed' => 0.0];

            $bucket['households'] += $row['households'];
            $bucket['ttc']        += $row['ttc_per_kwh'] * $row['households'];
            $bucket['htva']       += ($row['htva_per_kwh'] ?? 0.0) * $row['households'];
            $bucket['fixed']      += ($row['fixed_year_ttc'] ?? 0.0) * $row['households'];

            $byCurrency[$row['currency']] = $bucket;
        }

        $out = [];
        foreach ($byCurrency as $currency => $bucket) {
            $households = $bucket['households'];
            $out[]      = [
                'currency'       => $currency,
                'households'     => $households,
                'ttc_per_kwh'    => self::roundTo($bucket['ttc'] / $households, self::RATE_STEP),
                'htva_per_kwh'   => self::roundTo($bucket['htva'] / $households, self::RATE_STEP),
                'fixed_year_ttc' => self::roundTo($bucket['fixed'] / $households, 1.0),
            ];
        }

        // La devise la plus représentée d'abord : c'est celle que la vue met en avant.
        usort($out, static fn (array $a, array $b): int => $b['households'] <=> $a['households']);

        return $out;
    }

    /**
     * Moyenne pondérée d'une consommation. Le bucket « Autres pays » compte ici :
     * des kWh et des m³ s'additionnent, eux, quelle que soit la devise.
     *
     * @param list<CountryUsage> $rows
     * @return OverallUsage|null
     */
    private static function overallUsage(array $rows): ?array
    {
        $households = 0;
        $total      = 0.0;
        foreach ($rows as $row) {
            if ($row['households'] <= 0) {
                continue;
            }
            $households += $row['households'];
            $total      += $row['value'] * $row['households'];
        }

        if ($households === 0) {
            return null;
        }

        return [
            'households' => $households,
            'value'      => (float) self::roundTo($total / $households, self::USAGE_STEP),
        ];
    }

    /**
     * Part des contrats dynamiques sur l'ensemble du corpus publié.
     *
     * @param list<PricingMix> $rows
     * @return array{fixed: int, dynamic: int, dynamic_pct: float}|null
     */
    private static function overallMix(array $rows): ?array
    {
        $fixed   = 0;
        $dynamic = 0;
        foreach ($rows as $row) {
            $fixed   += $row['fixed'];
            $dynamic += $row['dynamic'];
        }

        $total = $fixed + $dynamic;
        if ($total === 0) {
            return null;
        }

        return [
            'fixed'       => $fixed,
            'dynamic'     => $dynamic,
            'dynamic_pct' => round($dynamic / $total * 100.0, 1),
        ];
    }

    /**
     * Fiche d'un pays (#85) : ce que la liste déroulante de `/stats` met en
     * avant quand un visiteur choisit un pays.
     *
     * Comme {@see overallSummary()}, purement dérivée de {@see publicOverview()} :
     * un pays qui n'y figure pas est un pays sous le seuil d'anonymat, et la
     * méthode renvoie alors null plutôt que d'aller le chercher ailleurs.
     *
     * Les écarts sont calculés contre le récapitulatif tous pays, **à devise
     * égale** pour le tarif : comparer un prix en couronnes à une moyenne en
     * euros donnerait un écart de plusieurs centaines de pour cent qui ne
     * dirait rien de la vie chère.
     *
     * @return CountryDetail|null
     */
    public function countryDetail(string $country): ?array
    {
        $overview = $this->publicOverview();

        $price = $this->findCountry($overview['prices'], $country);
        $elec  = $this->findCountry($overview['electricity'], $country);
        $gas   = $this->findCountry($overview['gas'], $country);
        $water = $this->findCountry($overview['water'], $country);
        $mix   = $this->findCountry($overview['mix'], $country);

        // Même critère que publishedCountries() — `mix` compris. Un pays dont
        // seule la part de contrats dynamiques franchit le seuil (grilles toutes
        // dynamiques, donc aucun tarif unitaire, et pas encore 90 jours de
        // relevés) est proposé par la liste déroulante et publié dans le
        // tableau : l'exclure ici afficherait « pas assez de foyers » sur un
        // pays dont la page montre pourtant déjà un chiffre.
        if ($price === null && $elec === null && $gas === null && $water === null && $mix === null) {
            return null;
        }

        $overall  = $this->overallSummary();
        $currency = is_string($price['currency'] ?? null) ? $price['currency'] : '';

        // Moyenne globale de la MÊME devise, seule comparable.
        $reference = null;
        foreach ($overall['prices'] as $row) {
            if ($row['currency'] === $currency) {
                $reference = $row['ttc_per_kwh'];
                break;
            }
        }

        $households = 0;
        foreach ([$price, $elec, $gas, $water] as $row) {
            $households = max($households, (int) ($row['households'] ?? 0));
        }
        if ($mix !== null) {
            // Les lignes de mix ne portent pas de compte de foyers, mais leur
            // somme en est un : sans ça un pays publié par le seul mix
            // afficherait « 0 foyers » sous son titre.
            $households = max($households, (int) $mix['fixed'] + (int) $mix['dynamic']);
        }

        return [
            'country'         => $country,
            // Dérivé du code lui-même : se fier à la première ligne trouvée
            // renverrait false pour un bucket résiduel qui n'a ni tarif ni
            // électricité (seulement de l'eau, par exemple).
            'is_other'        => $country === StatisticsRepositoryInterface::OTHER_BUCKET,
            'households'      => $households,
            'currency'        => $currency,
            'ttc_per_kwh'     => $price['ttc_per_kwh'] ?? null,
            'htva_per_kwh'    => $price['htva_per_kwh'] ?? null,
            'fixed_year_ttc'  => $price['fixed_year_ttc'] ?? null,
            'electricity'     => $elec['value'] ?? null,
            'gas'             => $gas['value'] ?? null,
            'water'           => $water['value'] ?? null,
            'dynamic_pct'     => $mix['dynamic_pct'] ?? null,
            'rate_delta_pct'  => self::deltaPct($price['ttc_per_kwh'] ?? null, $reference),
            'usage_delta_pct' => self::deltaPct($elec['value'] ?? null, $overall['electricity']['value'] ?? null),
        ];
    }

    /**
     * Pays proposés par la liste déroulante : ceux dont au moins un indicateur
     * est publié. Le bucket résiduel en fait partie — il est affiché dans les
     * tableaux, il doit pouvoir être sélectionné comme le reste.
     *
     * @return list<string>
     */
    public function publishedCountries(): array
    {
        $overview = $this->publicOverview();

        $countries = [];
        foreach (['prices', 'electricity', 'gas', 'water', 'mix'] as $section) {
            foreach ($overview[$section] as $row) {
                $countries[$row['country']] = true;
            }
        }

        $list = array_keys($countries);
        sort($list);

        return $list;
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
