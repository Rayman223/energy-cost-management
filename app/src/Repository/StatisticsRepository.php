<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ComponentKind;
use App\Repository\Contract\StatisticsRepositoryInterface;
use App\Repository\Sql\TariffLineSql;
use App\Support\Dates;
use DateTimeImmutable;
use PDO;

/**
 * Agrégats communautaires de /stats (#8) — le PREMIER repository du projet qui
 * lit au-delà d'un seul foyer.
 *
 * Partout ailleurs, un `user_id` injecté au constructeur borde chaque requête.
 * Ici il n'y en a pas, et c'est précisément ce qui rend cette classe sensible :
 * elle doit garantir par construction ce que le scoping garantissait ailleurs par
 * défaut. Trois règles, à faire respecter en revue de toute modification :
 *
 *   1. Aucune méthode ne renvoie de valeur attribuable à un foyer. Les agrégats
 *      par pays passent tous par {@see self::bucketed()}, qui applique le seuil de
 *      k-anonymat EN SQL — jamais en post-traitement PHP, où un oubli d'appel
 *      suffirait à publier une ligne isolée.
 *   2. Le prédicat de contribution ({@see self::CONTRIBUTOR}) exclut les foyers
 *      retirés du numérateur ET du dénominateur. Un foyer qui s'est retiré ne doit
 *      pas continuer à faire franchir le seuil à son pays — le pays resterait
 *      publié « grâce » à lui.
 *   3. Les méthodes prenant un `$userId` ne lisent que ce foyer, et l'appelant l'a
 *      déjà authentifié comme tel.
 *
 * Un mot sur les index cumulés. `meter_readings.index_value` et
 * `utility_readings.counter_m3` sont des compteurs, pas des consommations : un
 * `MAX - MIN` naïf compterait un remplacement de compteur comme des dizaines de
 * milliers de kWh, et comparerait un foyer qui relève depuis trois ans à un autre
 * qui relève depuis trois semaines. Les requêtes chaînent donc des deltas
 * consécutifs, neutralisent les régressions d'index et annualisent sur la durée
 * réellement observée. Le détail est commenté au fil des CTE.
 */
final class StatisticsRepository implements StatisticsRepositoryInterface
{
    /** Fenêtre d'observation par défaut, en mois. Douze absorbe la saisonnalité du chauffage. */
    public const WINDOW_MONTHS = 12;

    /**
     * Foyers dont les données alimentent les agrégats publics.
     *
     * Le compte doit être actif (un compte bloqué n'a pas à peser sur des chiffres
     * publiés), avoir un pays au profil (sans quoi il n'y a rien à agréger) et ne
     * pas s'être retiré.
     */
    private const CONTRIBUTOR = "up.country IS NOT NULL AND up.country <> '' AND up.stats_opt_out = 0";

    /**
     * Pré-agrégation mensuelle des index électriques, chaînée mois à mois.
     *
     * Pourquoi mensuel plutôt qu'un LAG sur les relevés bruts : un compteur
     * quart-horaire produit ~35 000 lignes par an et par registre. Chaîner ligne à
     * ligne sur tous les foyers serait impayable à chaque affichage de page ;
     * regrouper d'abord par mois ramène le frame de fenêtre à douze lignes.
     *
     * Le `%s` reçoit un filtre utilisateur optionnel (chaîne vide pour l'agrégat).
     */
    private const ELECTRICITY_MONTHLY_CTE = <<<'SQL'
        WITH monthly AS (
            SELECT m.user_id AS user_id,
                   r.id      AS register_id,
                   -- Casté en DATE, pas laissé en chaîne : les comparaisons et le
                   -- DATE_SUB de la série mensuelle doivent porter sur des dates,
                   -- pas dépendre de l'ordre lexicographique de 'Y-m-01'.
                   CAST(DATE_FORMAT(rd.reading_at, '%%Y-%%m-01') AS DATE) AS month_start,
                   MIN(rd.index_value) AS idx_min,
                   MAX(rd.index_value) AS idx_max
            FROM meter_readings rd
            JOIN meter_registers r ON r.id = rd.register_id
            JOIN meters m ON m.id = r.meter_id
            WHERE r.register_key IN ('import_t1', 'import_t2')
              AND rd.reading_at >= :from
              AND rd.reading_at <  :to
              %s
            GROUP BY m.user_id, r.id, month_start
        ),
        chained AS (
            SELECT user_id, register_id, month_start, idx_min, idx_max,
                   LAG(idx_max) OVER (PARTITION BY register_id ORDER BY month_start) AS prev_idx_max,
                   MIN(month_start) OVER (PARTITION BY user_id)                      AS first_month
            FROM monthly
        ),
        register_month AS (
            SELECT user_id, month_start,
                   CASE
                       -- Registre apparu en cours de fenêtre : rien avant à quoi
                       -- se rattacher, seule la consommation intra-mois est mesurée.
                       WHEN prev_idx_max IS NULL   THEN idx_max - idx_min
                       -- Index qui régresse : remise à zéro ou compteur remplacé.
                       -- Le mois est neutralisé plutôt que de compter un delta
                       -- négatif ou, pire, l'écart absolu. Il n'existe aucun
                       -- marqueur de remplacement en base : c'est une heuristique.
                       WHEN idx_max < prev_idx_max THEN 0
                       -- Cas courant. Se rattacher au mois précédent couvre AUSSI
                       -- le trou inter-mois (dernier relevé de M-1 → premier de M),
                       -- qu'un simple idx_max - idx_min perdrait.
                       ELSE idx_max - prev_idx_max
                   END AS kwh
            FROM chained
            -- Le premier mois observé d'un foyer est structurellement partiel (le
            -- relevé initial tombe en cours de mois) : le garder sous-compterait
            -- jusqu'à un mois entier.
            WHERE month_start > first_month
        ),
        household_month AS (
            SELECT user_id, month_start, SUM(kwh) AS kwh
            FROM register_month
            GROUP BY user_id, month_start
        )
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Fenêtre d'observation par défaut : les WINDOW_MONTHS mois calendaires révolus.
     *
     * Bornée au premier jour du mois courant : le mois en cours est partiel, l'y
     * inclure ferait baisser toutes les moyennes au fil de son écoulement.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [from, to[ en UTC.
     */
    public static function defaultWindow(): array
    {
        $to = (new DateTimeImmutable('now', Dates::utc()))
            ->modify('first day of this month')
            ->setTime(0, 0, 0);

        return [$to->modify('-' . self::WINDOW_MONTHS . ' months'), $to];
    }

    public function unitRateByCountry(): array
    {
        $sql = sprintf(
            <<<'SQL'
            %s,
            scoped AS (
                SELECT up.country AS country, gr.currency AS currency,
                       gr.ttc_per_kwh AS ttc_per_kwh,
                       gr.ttc_per_kwh / (1 + gr.vat_rate / 100) AS htva_per_kwh,
                       gr.fixed_year_ttc AS fixed_year_ttc
                FROM grid_rate gr
                JOIN user_profiles up ON up.user_id = gr.user_id
                JOIN users u ON u.id = gr.user_id AND u.status = 'active'
                WHERE %s AND gr.ttc_per_kwh > 0
            ),
            by_country AS (
                SELECT country, currency, COUNT(*) AS households,
                       SUM(ttc_per_kwh) AS s_ttc, SUM(htva_per_kwh) AS s_htva,
                       SUM(fixed_year_ttc) AS s_fix
                FROM scoped
                GROUP BY country, currency
            )
            SELECT CASE WHEN households >= %d THEN country ELSE '%s' END AS bucket,
                   currency,
                   SUM(households)               AS households,
                   SUM(s_ttc)  / SUM(households) AS ttc_per_kwh,
                   SUM(s_htva) / SUM(households) AS htva_per_kwh,
                   SUM(s_fix)  / SUM(households) AS fixed_year_ttc
            FROM by_country
            GROUP BY bucket, currency
            HAVING SUM(households) >= %d
            ORDER BY bucket, currency
            SQL,
            $this->activeGridRateCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
            self::OTHER_BUCKET,
            self::MIN_HOUSEHOLDS,
        );

        $out = [];
        foreach ($this->fetchAll($sql) as $row) {
            $out[] = [
                'bucket'         => (string) $row['bucket'],
                'currency'       => (string) $row['currency'],
                'households'     => (int) $row['households'],
                'ttc_per_kwh'    => (float) $row['ttc_per_kwh'],
                'htva_per_kwh'   => (float) $row['htva_per_kwh'],
                'fixed_year_ttc' => (float) $row['fixed_year_ttc'],
            ];
        }

        return $out;
    }

    public function unitRateByCountryAndCategory(): array
    {
        $sql = sprintf(
            <<<'SQL'
            %s,
            scoped AS (
                SELECT up.country AS country, gc.currency AS currency,
                       gc.category AS category, gc.user_id AS user_id,
                       gc.ttc_per_kwh AS ttc_per_kwh
                FROM grid_category gc
                JOIN user_profiles up ON up.user_id = gc.user_id
                JOIN users u ON u.id = gc.user_id AND u.status = 'active'
                WHERE %s
            ),
            by_country AS (
                SELECT country, currency, category,
                       COUNT(DISTINCT user_id) AS households,
                       SUM(ttc_per_kwh)        AS s_ttc
                FROM scoped
                GROUP BY country, currency, category
            )
            SELECT CASE WHEN households >= %d THEN country ELSE '%s' END AS bucket,
                   currency, category,
                   SUM(households)              AS households,
                   SUM(s_ttc) / SUM(households) AS ttc_per_kwh
            FROM by_country
            GROUP BY bucket, currency, category
            HAVING SUM(households) >= %d
            ORDER BY bucket, currency, category
            SQL,
            $this->activeGridCategoryCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
            self::OTHER_BUCKET,
            self::MIN_HOUSEHOLDS,
        );

        $out = [];
        foreach ($this->fetchAll($sql) as $row) {
            $out[] = [
                'bucket'      => (string) $row['bucket'],
                'currency'    => (string) $row['currency'],
                'category'    => (string) $row['category'],
                'households'  => (int) $row['households'],
                'ttc_per_kwh' => (float) $row['ttc_per_kwh'],
            ];
        }

        return $out;
    }

    public function electricityUsageByCountry(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = sprintf(
            <<<'SQL'
            %s,
            household AS (
                SELECT user_id, SUM(kwh) AS total, COUNT(*) AS months
                FROM household_month
                GROUP BY user_id
                -- Moins de trois mois exploitables : annualiser reviendrait à
                -- projeter sur l'année un échantillon dominé par une saison. Le
                -- foyer est écarté, pas ramené à zéro (ce qui tirerait la moyenne).
                HAVING COUNT(*) >= 3 AND SUM(kwh) > 0
            ),
            scoped AS (
                SELECT up.country AS country, h.total / h.months * 12.0 AS value
                FROM household h
                JOIN user_profiles up ON up.user_id = h.user_id
                JOIN users u ON u.id = h.user_id AND u.status = 'active'
                WHERE %s
            ),
            by_country AS (
                SELECT country, COUNT(*) AS households, SUM(value) AS total
                FROM scoped
                GROUP BY country
            )
            SELECT CASE WHEN households >= %d THEN country ELSE '%s' END AS bucket,
                   SUM(households)              AS households,
                   SUM(total) / SUM(households) AS value
            FROM by_country
            GROUP BY bucket
            HAVING SUM(households) >= %d
            ORDER BY bucket
            SQL,
            $this->electricityMonthlyCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
            self::OTHER_BUCKET,
            self::MIN_HOUSEHOLDS,
        );

        return $this->mapUsageRows($this->fetchAll($sql, $this->window($from, $to)));
    }

    public function utilityUsageByCountry(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = sprintf(
            <<<'SQL'
            WITH deltas AS (
                SELECT user_id, reading_at, counter_m3,
                       LAG(counter_m3) OVER (PARTITION BY user_id ORDER BY reading_at) AS prev_m3,
                       LAG(reading_at)  OVER (PARTITION BY user_id ORDER BY reading_at) AS prev_at
                FROM utility_readings
                WHERE energy_type = :etype
                  AND reading_at >= :from
                  AND reading_at <  :to
            ),
            household AS (
                SELECT user_id,
                       -- Un delta négatif (remise à zéro, compteur remplacé) est
                       -- écarté AVEC sa durée : le taux m³/jour reste cohérent au
                       -- lieu d'être dilué par une période non mesurée.
                       SUM(CASE WHEN prev_m3 IS NOT NULL AND counter_m3 >= prev_m3
                                THEN counter_m3 - prev_m3 ELSE 0 END) AS m3,
                       SUM(CASE WHEN prev_m3 IS NOT NULL AND counter_m3 >= prev_m3
                                THEN TIMESTAMPDIFF(DAY, prev_at, reading_at) ELSE 0 END) AS days
                FROM deltas
                GROUP BY user_id
                -- Sous un trimestre, annualiser un relevé de gaz projetterait une
                -- saison sur l'année entière. Garantit aussi days > 0.
                HAVING days >= 90 AND m3 > 0
            ),
            scoped AS (
                SELECT up.country AS country, h.m3 / h.days * 365.0 AS value
                FROM household h
                JOIN user_profiles up ON up.user_id = h.user_id
                JOIN users u ON u.id = h.user_id AND u.status = 'active'
                WHERE %s
            ),
            by_country AS (
                SELECT country, COUNT(*) AS households, SUM(value) AS total
                FROM scoped
                GROUP BY country
            )
            SELECT CASE WHEN households >= %d THEN country ELSE '%s' END AS bucket,
                   SUM(households)              AS households,
                   SUM(total) / SUM(households) AS value
            FROM by_country
            GROUP BY bucket
            HAVING SUM(households) >= %d
            ORDER BY bucket
            SQL,
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
            self::OTHER_BUCKET,
            self::MIN_HOUSEHOLDS,
        );

        $params = $this->window($from, $to) + ['etype' => $energyType];

        return $this->mapUsageRows($this->fetchAll($sql, $params));
    }

    public function pricingModeByCountry(): array
    {
        // Une grille active par foyer, même règle de priorité que partout ailleurs.
        $sql = sprintf(
            <<<'SQL'
            WITH active_grid AS (
                SELECT g.user_id, g.pricing_mode,
                       ROW_NUMBER() OVER (PARTITION BY g.user_id ORDER BY g.valid_from DESC, g.id DESC) AS rn
                FROM tariff_grids g
                WHERE g.energy_type = 'electricity'
                  AND g.user_id IS NOT NULL
                  AND g.valid_from <= CURDATE()
                  AND (g.valid_to IS NULL OR g.valid_to > CURDATE())
            ),
            scoped AS (
                SELECT up.country AS country,
                       CASE WHEN ag.pricing_mode = 'fixed' THEN 1 ELSE 0 END AS is_fixed
                FROM active_grid ag
                JOIN user_profiles up ON up.user_id = ag.user_id
                JOIN users u ON u.id = ag.user_id AND u.status = 'active'
                WHERE ag.rn = 1 AND %s
            ),
            by_country AS (
                SELECT country, COUNT(*) AS households,
                       SUM(is_fixed)     AS fixed,
                       SUM(1 - is_fixed) AS dynamic
                FROM scoped
                GROUP BY country
            )
            SELECT CASE WHEN households >= %d THEN country ELSE '%s' END AS bucket,
                   SUM(fixed) AS fixed, SUM(dynamic) AS dynamic
            FROM by_country
            GROUP BY bucket
            HAVING SUM(households) >= %d
            ORDER BY bucket
            SQL,
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
            self::OTHER_BUCKET,
            self::MIN_HOUSEHOLDS,
        );

        $out = [];
        foreach ($this->fetchAll($sql) as $row) {
            $out[] = [
                'bucket'  => (string) $row['bucket'],
                'fixed'   => (int) $row['fixed'],
                'dynamic' => (int) $row['dynamic'],
            ];
        }

        return $out;
    }

    public function spotPriceByZone(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Aucune jointure vers user_profiles : la répartition des foyers par zone
        // serait ré-identifiante là où une zone ne couvre que quelques comptes.
        $sql = <<<'SQL'
            SELECT bidding_zone, resolution_min,
                   AVG(price_eur_kwh) AS avg_price,
                   COUNT(*)           AS points,
                   MIN(period_start)  AS first_at,
                   MAX(period_start)  AS last_at
            FROM dynamic_prices
            WHERE energy_type = 'electricity'
              AND period_start >= :from
              AND period_start <  :to
            GROUP BY bidding_zone, resolution_min
            ORDER BY bidding_zone, resolution_min
            SQL;

        $out = [];
        foreach ($this->fetchAll($sql, $this->window($from, $to)) as $row) {
            $out[] = [
                'zone'           => (string) $row['bidding_zone'],
                'resolution_min' => (int) $row['resolution_min'],
                'avg_price'      => (float) $row['avg_price'],
                'points'         => (int) $row['points'],
                'first_at'       => (string) $row['first_at'],
                'last_at'        => (string) $row['last_at'],
            ];
        }

        return $out;
    }

    public function coverage(): array
    {
        $sql = sprintf(
            <<<'SQL'
            SELECT COUNT(*) AS households, COUNT(DISTINCT up.country) AS countries
            FROM user_profiles up
            JOIN users u ON u.id = up.user_id AND u.status = 'active'
            WHERE %s
            SQL,
            self::CONTRIBUTOR,
        );

        $row = $this->fetchAll($sql)[0] ?? ['households' => 0, 'countries' => 0];
        $households = (int) $row['households'];

        // Sous le seuil, publier « 3 foyers dans 1 pays » situerait déjà le corpus.
        return $households >= self::MIN_HOUSEHOLDS
            ? ['households' => $households, 'countries' => (int) $row['countries']]
            : ['households' => 0, 'countries' => 0];
    }

    public function householdMonthlySeries(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = $this->electricityMonthlyCte('AND m.user_id = :uid')
            . "\nSELECT DATE_FORMAT(month_start, '%Y-%m') AS month, kwh"
            . "\nFROM household_month ORDER BY month";

        $out = [];
        foreach ($this->fetchAll($sql, $this->window($from, $to) + ['uid' => $userId]) as $row) {
            $out[(string) $row['month']] = (float) $row['kwh'];
        }

        return $out;
    }

    public function countryMonthlySeries(string $country, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = sprintf(
            <<<'SQL'
            %s,
            continuous AS (
                -- Un mois ne compte pour un foyer que si le mois PRÉCÉDENT est lui
                -- aussi observé. Sinon la consommation du trou est imputée en bloc
                -- au mois suivant, et la courbe montre un pic qui n'a pas eu lieu.
                SELECT hm.user_id, hm.month_start, hm.kwh
                FROM household_month hm
                JOIN household_month prev
                  ON prev.user_id     = hm.user_id
                 AND prev.month_start = DATE_SUB(hm.month_start, INTERVAL 1 MONTH)
            ),
            scoped AS (
                SELECT c.user_id, c.month_start, c.kwh
                FROM continuous c
                JOIN user_profiles up ON up.user_id = c.user_id
                JOIN users u ON u.id = c.user_id AND u.status = 'active'
                WHERE up.country = :country AND %s
            )
            SELECT DATE_FORMAT(month_start, '%%Y-%%m') AS month, AVG(kwh) AS value
            FROM scoped
            GROUP BY month
            -- k-anonymat MOIS PAR MOIS : franchir le seuil sur l'année ne garantit
            -- pas de le franchir en juillet.
            HAVING COUNT(DISTINCT user_id) >= %d
            ORDER BY month
            SQL,
            $this->electricityMonthlyCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
        );

        $params = $this->window($from, $to) + ['country' => $country];

        $out = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $out[(string) $row['month']] = (float) $row['value'];
        }

        return $out;
    }

    public function usageRankInCountry(
        string $country,
        float $value,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?array {
        $sql = sprintf(
            <<<'SQL'
            %s,
            household AS (
                SELECT user_id, SUM(kwh) AS total, COUNT(*) AS months
                FROM household_month
                GROUP BY user_id
                HAVING COUNT(*) >= 3 AND SUM(kwh) > 0
            ),
            scoped AS (
                SELECT h.total / h.months * 12.0 AS value
                FROM household h
                JOIN user_profiles up ON up.user_id = h.user_id
                JOIN users u ON u.id = h.user_id AND u.status = 'active'
                WHERE up.country = :country AND %s
            )
            SELECT SUM(CASE WHEN value < :mine THEN 1 ELSE 0 END) AS below,
                   COUNT(*)                                       AS total
            FROM scoped
            HAVING COUNT(*) >= %d
            SQL,
            $this->electricityMonthlyCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
        );

        $params = $this->window($from, $to) + ['country' => $country, 'mine' => $value];

        return $this->firstRank($this->fetchAll($sql, $params));
    }

    public function rateRankInCountry(string $country, float $rate): ?array
    {
        $sql = sprintf(
            <<<'SQL'
            %s,
            scoped AS (
                SELECT gr.ttc_per_kwh AS value
                FROM grid_rate gr
                JOIN user_profiles up ON up.user_id = gr.user_id
                JOIN users u ON u.id = gr.user_id AND u.status = 'active'
                WHERE up.country = :country AND gr.ttc_per_kwh > 0 AND %s
            )
            SELECT SUM(CASE WHEN value < :mine THEN 1 ELSE 0 END) AS below,
                   COUNT(*)                                       AS total
            FROM scoped
            HAVING COUNT(*) >= %d
            SQL,
            $this->activeGridRateCte(),
            self::CONTRIBUTOR,
            self::MIN_HOUSEHOLDS,
        );

        return $this->firstRank($this->fetchAll($sql, ['country' => $country, 'mine' => $rate]));
    }

    /**
     * CTE des grilles électricité actives et de leur tarif au kWh.
     *
     * Une seule grille par foyer : la plus récemment entrée en vigueur, même ordre
     * de priorité que {@see TariffRepository::findActiveGrid()}. `valid_to` est une
     * borne EXCLUE (#1). Le catalogue partagé (`user_id IS NULL`) n'est pas un
     * foyer et ne doit jamais compter comme tel.
     *
     * Les grilles dynamiques sont écartées : sans ligne `energy_*`, la somme ne
     * porterait que réseau et taxes, un prix artificiellement bas qui
     * empoisonnerait la moyenne du pays.
     */
    private function activeGridRateCte(): string
    {
        return sprintf(
            <<<'SQL'
            WITH active_grid AS (
                SELECT g.id, g.user_id, g.currency, g.vat_rate,
                       ROW_NUMBER() OVER (PARTITION BY g.user_id ORDER BY g.valid_from DESC, g.id DESC) AS rn
                FROM tariff_grids g
                WHERE g.energy_type  = 'electricity'
                  AND g.pricing_mode = 'fixed'
                  AND g.user_id IS NOT NULL
                  AND g.valid_from <= CURDATE()
                  AND (g.valid_to IS NULL OR g.valid_to > CURDATE())
            ),
            grid_rate AS (
                SELECT ag.user_id, ag.currency, ag.vat_rate,
                       SUM(l.amount_per_kwh * (%s)) AS ttc_per_kwh,
                       SUM(CASE WHEN l.component_kind = '%s' THEN l.amount_per_kwh
                                WHEN l.component_kind = '%s' THEN l.amount_per_kwh * 12
                                ELSE 0 END) AS fixed_year_ttc
                FROM active_grid ag
                JOIN tariff_grid_lines l ON l.tariff_grid_id = ag.id
                WHERE ag.rn = 1
                GROUP BY ag.user_id, ag.currency, ag.vat_rate
            )
            SQL,
            TariffLineSql::perKwhWeightCase(),
            ComponentKind::FixedAnnual->value,
            ComponentKind::FixedMonthly->value,
        );
    }

    /** Même périmètre, ventilé par catégorie tarifaire pour la comparaison par poste. */
    private function activeGridCategoryCte(): string
    {
        return sprintf(
            <<<'SQL'
            WITH active_grid AS (
                SELECT g.id, g.user_id, g.currency,
                       ROW_NUMBER() OVER (PARTITION BY g.user_id ORDER BY g.valid_from DESC, g.id DESC) AS rn
                FROM tariff_grids g
                WHERE g.energy_type  = 'electricity'
                  AND g.pricing_mode = 'fixed'
                  AND g.user_id IS NOT NULL
                  AND g.valid_from <= CURDATE()
                  AND (g.valid_to IS NULL OR g.valid_to > CURDATE())
            ),
            grid_category AS (
                SELECT ag.user_id, ag.currency,
                       %s AS category,
                       SUM(l.amount_per_kwh * (%s)) AS ttc_per_kwh
                FROM active_grid ag
                JOIN tariff_grid_lines l ON l.tariff_grid_id = ag.id
                WHERE ag.rn = 1
                  AND l.component_kind IN (%s)
                GROUP BY ag.user_id, ag.currency, category
            )
            SQL,
            TariffLineSql::categoryCase(),
            TariffLineSql::perKwhWeightCase(),
            TariffLineSql::perKwhKindList(),
        );
    }

    /** CTE électricité, avec un filtre utilisateur optionnel injecté dans `monthly`. */
    private function electricityMonthlyCte(string $userFilter = ''): string
    {
        return sprintf(self::ELECTRICITY_MONTHLY_CTE, $userFilter);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{below: int, total: int}|null
     */
    private function firstRank(array $rows): ?array
    {
        $row = $rows[0] ?? null;
        if (!is_array($row) || (int) $row['total'] < self::MIN_HOUSEHOLDS) {
            return null;
        }

        return ['below' => (int) $row['below'], 'total' => (int) $row['total']];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{bucket: string, households: int, value: float}>
     */
    private function mapUsageRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'bucket'     => (string) $row['bucket'],
                'households' => (int) $row['households'],
                'value'      => (float) $row['value'],
            ];
        }

        return $out;
    }

    /** @return array{from: string, to: string} */
    private function window(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ['from' => Dates::toDbString($from), 'to' => Dates::toDbString($to)];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }
}
