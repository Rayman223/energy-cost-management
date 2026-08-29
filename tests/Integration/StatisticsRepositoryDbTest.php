<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\TariffUnitRate;
use App\Domain\UserProfile;
use App\Repository\ElectricityReadingRepository;
use App\Repository\StatisticsRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * StatisticsRepository est le premier repository du projet qui lit au-delà d'un
 * seul foyer (#8) : partout ailleurs, un `user_id` au constructeur rend une fuite
 * inter-tenants structurellement impossible. Ici c'est le SQL, et lui seul, qui
 * tient la garantie — d'où ce fichier, qui est la vraie revue de sécurité de la
 * fonctionnalité.
 *
 * Il couvre deux familles de risques :
 *   - la CONFIDENTIALITÉ : seuil de k-anonymat, effet réel du retrait, absence de
 *     ligne individuelle dans les agrégats ;
 *   - la CORRECTION du traitement des index cumulés, où une erreur ne planterait
 *     rien mais publierait un chiffre faux et crédible (remise à zéro comptée
 *     comme consommation, fenêtres d'observation inégales, mois partiels).
 *
 * Le test le plus important est {@see testSqlUnitRateMatchesThePhpDefinition()} :
 * il empêche le SQL agrégé et le PHP du bloc privé de diverger, ce qui afficherait
 * un écart « moi vs la moyenne » inexplicable.
 */
final class StatisticsRepositoryDbTest extends DatabaseTestCase
{
    private const K = StatisticsRepository::MIN_HOUSEHOLDS;

    private StatisticsRepository $stats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stats = new StatisticsRepository($this->pdo());
    }

    protected function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'meters',
            'utility_readings', 'tariff_grid_lines', 'tariff_grids',
            'dynamic_prices', 'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    // ── Confidentialité ────────────────────────────────────────────────────

    public function testCountryBelowThresholdIsFoldedIntoTheOtherBucket(): void
    {
        // BE atteint tout juste le seuil et se publie nommément. FR et NL restent
        // chacun en dessous, mais leur SOMME le franchit : c'est ce qui permet au
        // bucket « Autres » d'exister — le seuil s'applique aussi à lui (cf.
        // testOtherBucketDisappearsWhenItAlsoStaysBelowTheThreshold), de sorte
        // qu'un unique pays résiduel ne peut jamais être publié sous ce déguisement.
        for ($i = 0; $i < self::K; $i++) {
            $this->householdWithGrid('BE', 0.30);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->householdWithGrid('FR', 0.20);
            $this->householdWithGrid('NL', 0.25);
        }

        $rows    = $this->stats->unitRateByCountry();
        $buckets = array_column($rows, 'bucket');

        self::assertContains('BE', $buckets, 'Un pays au seuil doit être publié nommément.');
        self::assertNotContains('FR', $buckets, 'Un pays sous le seuil ne doit jamais être nommé.');
        self::assertNotContains('NL', $buckets);
        self::assertContains(StatisticsRepository::OTHER_BUCKET, $buckets);

        // Le bucket agrège bien les deux pays résiduels, et rien d'autre.
        $other = array_column($rows, 'households', 'bucket')[StatisticsRepository::OTHER_BUCKET];
        self::assertSame(6, $other);
    }

    public function testOtherBucketDisappearsWhenItAlsoStaysBelowTheThreshold(): void
    {
        // Deux pays d'un foyer chacun : leur somme reste sous le seuil, le bucket
        // « Autres » ne doit pas servir de porte dérobée pour les publier.
        $this->householdWithGrid('FR', 0.20);
        $this->householdWithGrid('NL', 0.25);

        self::assertSame([], $this->stats->unitRateByCountry());
    }

    public function testOptingOutRemovesTheHouseholdFromTheThresholdItself(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $ids[] = $this->householdWithGrid('BE', 0.30);
        }
        self::assertContains('BE', array_column($this->stats->unitRateByCountry(), 'bucket'));

        // Un seul retrait fait retomber le pays sous le seuil : le retrait ôte du
        // dénominateur, pas seulement du numérateur. Sans cela, un foyer retiré
        // continuerait de faire publier son pays.
        $this->optOut($ids[0]);

        self::assertNotContains(
            'BE',
            array_column($this->stats->unitRateByCountry(), 'bucket'),
            'Un foyer retiré ne doit plus faire franchir le seuil à son pays.',
        );
    }

    public function testBlockedAccountsDoNotFeedPublishedAggregates(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $ids[] = $this->householdWithGrid('BE', 0.30);
        }
        $this->pdo()->prepare("UPDATE users SET status = 'blocked' WHERE id = :id")
            ->execute(['id' => $ids[0]]);

        self::assertNotContains('BE', array_column($this->stats->unitRateByCountry(), 'bucket'));
    }

    public function testCoverageStaysSilentBelowTheThreshold(): void
    {
        $this->householdWithGrid('BE', 0.30);

        // « 1 foyer dans 1 pays » situerait déjà le corpus.
        self::assertSame(['households' => 0, 'countries' => 0], $this->stats->coverage());
    }

    // ── Tarif au kWh ───────────────────────────────────────────────────────

    public function testSqlUnitRateMatchesThePhpDefinition(): void
    {
        // Grille portant une composante de chaque famille : bihoraire, taxe au
        // kWh, abonnement, crédit d'injection, paramètre de formule dynamique.
        $lines = [
            ['key' => 'energy_day',   'amount' => 0.2000, 'kind' => 'energy_t1',        'label' => null],
            ['key' => 'energy_night', 'amount' => 0.1000, 'kind' => 'energy_t2',        'label' => null],
            ['key' => 'transport',    'amount' => 0.0500, 'kind' => 'per_kwh_t2',       'label' => null],
            ['key' => 'subscription', 'amount' => 12.000, 'kind' => 'fixed_monthly',    'label' => null],
            ['key' => 'injection',    'amount' => 0.0400, 'kind' => 'injection_t1',     'label' => null],
            ['key' => 'spot_coef',    'amount' => 1.0800, 'kind' => 'spot_coefficient', 'label' => null],
        ];

        for ($i = 0; $i < self::K; $i++) {
            $userId = $this->user('rate-' . $i);
            $this->profile($userId, 'BE');
            (new TariffRepository($this->pdo(), $userId))->saveGrid(
                'electricity',
                'Grille test',
                new DateTimeImmutable('-1 month'),
                null,
                $lines,
            );
        }

        $rows = $this->stats->unitRateByCountry();
        self::assertCount(1, $rows);

        $expected = TariffUnitRate::fromCalculationTariff([
            'vat_rate' => 21.0,
            'currency' => 'EUR',
            'lines'    => array_map(
                static fn (array $l): array => $l + ['category' => 'energy'],
                $lines,
            ),
        ]);

        self::assertNotNull($expected);
        self::assertEqualsWithDelta(
            $expected,
            $rows[0]['ttc_per_kwh'],
            1e-6,
            'Le SQL agrégé et TariffUnitRate doivent produire le même tarif : le bloc '
            . 'privé compare directement ces deux chemins.',
        );
        // 0,20×0,5 + 0,10×0,5 + 0,05×0,5 = 0,175 — l'abonnement, l'injection et le
        // coefficient spot sont bien restés dehors.
        self::assertEqualsWithDelta(0.175, $rows[0]['ttc_per_kwh'], 1e-6);
        self::assertEqualsWithDelta(144.0, $rows[0]['fixed_year_ttc'], 1e-6, '12 €/mois → 144 €/an.');
    }

    public function testDynamicGridsAreExcludedFromThePriceButCountedInTheMix(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $userId = $this->user('dyn-' . $i);
            $this->profile($userId, 'BE');
            (new TariffRepository($this->pdo(), $userId))->saveGrid(
                'electricity',
                'Indexée',
                new DateTimeImmutable('-1 month'),
                null,
                [['key' => 'spot_coef', 'amount' => 1.08, 'kind' => 'spot_coefficient', 'label' => null]],
                null,
                null,
                'EUR',
                false,
                21.0,
                'dynamic_hourly',
            );
        }

        self::assertSame([], $this->stats->unitRateByCountry(), 'Publier réseau+taxes seuls serait trompeur.');

        $mix = $this->stats->pricingModeByCountry();
        self::assertCount(1, $mix);
        self::assertSame(0, $mix[0]['fixed']);
        self::assertSame(self::K, $mix[0]['dynamic']);
    }

    public function testSharedCatalogueGridsAreNotCountedAsHouseholds(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $this->householdWithGrid('BE', 0.30);
        }
        // Grille du catalogue partagé (user_id NULL) : ce n'est pas un foyer.
        $admin = $this->user('admin', isAdmin: true);
        $this->profile($admin, 'BE');
        (new TariffRepository($this->pdo(), $admin, true))->saveGrid(
            'electricity',
            'Catalogue',
            new DateTimeImmutable('-1 month'),
            null,
            [['key' => 'energy', 'amount' => 9.99, 'kind' => 'energy_flat', 'label' => null]],
            null,
            'BE',
            'EUR',
            true,
        );

        $rows = $this->stats->unitRateByCountry();
        self::assertCount(1, $rows);
        // L'admin a sa propre grille perso ? Non : seule la partagée existe pour
        // lui, elle est écartée. La moyenne reste celle des K foyers à 0,30.
        self::assertEqualsWithDelta(0.30, $rows[0]['ttc_per_kwh'], 1e-6);
        self::assertSame(self::K, $rows[0]['households']);
    }

    public function testExpiredGridIsIgnoredBecauseTheEndBoundIsExclusive(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $userId = $this->user('exp-' . $i);
            $this->profile($userId, 'BE');
            (new TariffRepository($this->pdo(), $userId))->saveGrid(
                'electricity',
                'Échue',
                new DateTimeImmutable('-2 months'),
                new DateTimeImmutable('today'), // fin EXCLUE : plus active aujourd'hui (#1)
                [['key' => 'energy', 'amount' => 0.99, 'kind' => 'energy_flat', 'label' => null]],
            );
        }

        self::assertSame([], $this->stats->unitRateByCountry());
    }

    public function testTwoCurrenciesInOneCountryAreNeverAddedTogether(): void
    {
        for ($i = 0; $i < self::K; $i++) {
            $this->householdWithGrid('BE', 0.30, 'EUR');
        }
        for ($i = 0; $i < self::K; $i++) {
            $this->householdWithGrid('BE', 3.00, 'CHF');
        }

        $rows = $this->stats->unitRateByCountry();
        self::assertCount(2, $rows, 'Chaque devise doit rester un groupe distinct.');

        $byCurrency = array_column($rows, 'ttc_per_kwh', 'currency');
        self::assertEqualsWithDelta(0.30, $byCurrency['EUR'], 1e-6);
        self::assertEqualsWithDelta(3.00, $byCurrency['CHF'], 1e-6);
    }

    // ── Index cumulés ──────────────────────────────────────────────────────

    public function testMeterResetIsNotCountedAsConsumption(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        for ($i = 0; $i < self::K; $i++) {
            $userId = $this->user('reset-' . $i);
            $this->profile($userId, 'BE');
            $repo = new ElectricityReadingRepository($this->pdo(), $userId);

            // Six mois à 100 kWh/mois, puis remplacement du compteur (l'index
            // repart de zéro) et six mois de plus au même rythme.
            $index = 40000.0;
            for ($m = 11; $m >= 6; $m--) {
                $repo->insertIndexes($this->monthPoint($m), ['import_t1' => $index]);
                $index += 100.0;
            }
            $index = 100.0;
            for ($m = 5; $m >= 0; $m--) {
                $repo->insertIndexes($this->monthPoint($m), ['import_t1' => $index]);
                $index += 100.0;
            }
        }

        $rows = $this->stats->electricityUsageByCountry($from, $to);
        self::assertCount(1, $rows);
        // Sans la garde, la régression d'index compterait ~40 000 kWh de plus.
        self::assertLessThan(
            5000.0,
            $rows[0]['value'],
            'Une remise à zéro de compteur ne doit pas être facturée comme consommation.',
        );
    }

    public function testHouseholdWithASingleReadingDoesNotDragTheAverageDown(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        for ($i = 0; $i < self::K; $i++) {
            $this->steadyHousehold('steady-' . $i, 'BE', 100.0);
        }
        $reference = $this->stats->electricityUsageByCountry($from, $to)[0]['value'];

        // Un foyer qui n'a qu'un relevé n'a aucune consommation mesurable : le
        // compter à zéro écraserait la moyenne des autres.
        $lonely = $this->user('lonely');
        $this->profile($lonely, 'BE');
        (new ElectricityReadingRepository($this->pdo(), $lonely))
            ->insertIndexes($this->monthPoint(3), ['import_t1' => 500.0]);

        $after = $this->stats->electricityUsageByCountry($from, $to)[0]['value'];
        self::assertEqualsWithDelta($reference, $after, 1e-6);
    }

    public function testPartialFirstMonthDoesNotUnderCountAHousehold(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        // Deux groupes consommant à l'identique, mais l'un a commencé à relever
        // en milieu de mois. Sans l'exclusion du premier mois observé, son
        // premier delta serait amputé et sa moyenne annualisée plus basse.
        for ($i = 0; $i < self::K; $i++) {
            $this->steadyHousehold('early-' . $i, 'BE', 100.0);
        }
        $reference = $this->stats->electricityUsageByCountry($from, $to)[0]['value'];

        $this->clean();
        for ($i = 0; $i < self::K; $i++) {
            $this->steadyHousehold('late-' . $i, 'BE', 100.0, dayOfMonth: 18);
        }
        $shifted = $this->stats->electricityUsageByCountry($from, $to)[0]['value'];

        self::assertEqualsWithDelta($reference, $shifted, 1.0);
    }

    public function testHouseholdMonthlySeriesIsReturnedEvenWhenOptedOut(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        $userId = $this->steadyHousehold('mine', 'BE', 100.0);
        $this->optOut($userId);

        $series = $this->stats->householdMonthlySeries($userId, $from, $to);

        self::assertNotSame([], $series, 'Un foyer retiré continue de voir ses propres chiffres.');
        self::assertEqualsWithDelta(100.0, (float) reset($series), 1e-6);
    }

    public function testUtilityDeltasDropNegativeStepsWithTheirDuration(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        for ($i = 0; $i < self::K; $i++) {
            $userId = $this->user('gas-' . $i);
            $this->profile($userId, 'BE');
            $repo = new UtilityReadingRepository($this->pdo(), $userId, 'gas');

            // 10 m³/mois, puis compteur remplacé à mi-parcours.
            $counter = 500.0;
            for ($m = 11; $m >= 6; $m--) {
                $repo->save($this->monthPoint($m), $counter);
                $counter += 10.0;
            }
            $counter = 5.0;
            for ($m = 5; $m >= 0; $m--) {
                $repo->save($this->monthPoint($m), $counter);
                $counter += 10.0;
            }
        }

        $rows = $this->stats->utilityUsageByCountry('gas', $from, $to);
        self::assertCount(1, $rows);
        // ~120 m³/an attendus ; sans la garde, le remplacement en ajouterait ~500.
        self::assertLessThan(300.0, $rows[0]['value']);
        self::assertGreaterThan(50.0, $rows[0]['value']);
    }

    public function testSpotPricesAreGroupedByResolutionToAvoidDoubleCounting(): void
    {
        [$from, $to] = StatisticsRepository::defaultWindow();

        // Même heure publiée en PT60M et en PT15M : les mélanger compterait deux
        // fois la même heure, et pondérerait 4 points de 15 min contre 1 de 60.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO dynamic_prices (energy_type, bidding_zone, period_start, period_end, resolution_min, price_eur_kwh, source)
             VALUES (:t, :z, :ps, :pe, :res, :p, :s)'
        );
        $base = $from->modify('+40 days');
        $stmt->execute(['t' => 'electricity', 'z' => 'ZONE', 'ps' => Dates::toDbString($base),
            'pe' => Dates::toDbString($base->modify('+1 hour')), 'res' => 60, 'p' => 0.10, 's' => 'test']);
        for ($q = 0; $q < 4; $q++) {
            $start = $base->modify('+' . ($q * 15) . ' minutes');
            $stmt->execute(['t' => 'electricity', 'z' => 'ZONE', 'ps' => Dates::toDbString($start),
                'pe' => Dates::toDbString($start->modify('+15 minutes')), 'res' => 15, 'p' => 0.20, 's' => 'test']);
        }

        $rows = $this->stats->spotPriceByZone($from, $to);
        self::assertCount(2, $rows, 'Chaque résolution doit rester un groupe distinct.');

        $byResolution = array_column($rows, 'avg_price', 'resolution_min');
        self::assertEqualsWithDelta(0.10, $byResolution[60], 1e-6);
        self::assertEqualsWithDelta(0.20, $byResolution[15], 1e-6);
    }

    public function testRateRankIsNullWhenTheCountryIsBelowTheThreshold(): void
    {
        for ($i = 0; $i < self::K - 1; $i++) {
            $this->householdWithGrid('BE', 0.30);
        }

        self::assertNull(
            $this->stats->rateRankInCountry('BE', 0.25),
            'Un percentile sur quatre foyers désignerait les autres.',
        );
    }

    public function testRateRankCountsStrictlyCheaperHouseholds(): void
    {
        foreach ([0.10, 0.20, 0.30, 0.40, 0.50] as $i => $rate) {
            $this->householdWithGrid('BE', $rate, 'EUR', 'rank-' . $i);
        }

        $rank = $this->stats->rateRankInCountry('BE', 0.35);
        self::assertNotNull($rank);
        self::assertSame(3, $rank['below']);
        self::assertSame(5, $rank['total']);
    }

    // ── Fabriques ──────────────────────────────────────────────────────────

    private function user(string $sub, bool $isAdmin = false): int
    {
        return (new UserRepository($this->pdo()))
            ->create('https://iss.test', $sub . '-' . uniqid(), 'test', 'Foyer ' . $sub)->id;
    }

    /**
     * Renseigne le pays du foyer.
     *
     * Passe par `updateProfile()` et non par un INSERT direct : `UserRepository::create()`
     * pose déjà la ligne `user_profiles` (cf. UserRepository::create), dont `user_id`
     * est la clé primaire. Un second INSERT violerait la contrainte.
     */
    private function profile(int $userId, string $country): void
    {
        (new UserRepository($this->pdo()))->updateProfile($userId, new UserProfile(
            country: $country,
            timezone: 'UTC',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
        ));
    }

    private function optOut(int $userId): void
    {
        $this->pdo()->prepare('UPDATE user_profiles SET stats_opt_out = 1 WHERE user_id = :u')
            ->execute(['u' => $userId]);
    }

    /** Foyer doté d'une grille fixe mono-horaire au tarif donné. */
    private function householdWithGrid(
        string $country,
        float $ratePerKwh,
        string $currency = 'EUR',
        string $sub = 'h',
    ): int {
        $userId = $this->user($sub . '-' . $country);
        $this->profile($userId, $country);
        (new TariffRepository($this->pdo(), $userId))->saveGrid(
            'electricity',
            'Grille',
            new DateTimeImmutable('-1 month'),
            null,
            [['key' => 'energy', 'amount' => $ratePerKwh, 'kind' => 'energy_flat', 'label' => null]],
            null,
            $country,
            $currency,
        );

        return $userId;
    }

    /** Foyer relevant son index chaque mois, à consommation constante. */
    private function steadyHousehold(string $sub, string $country, float $kwhPerMonth, int $dayOfMonth = 1): int
    {
        $userId = $this->user($sub);
        $this->profile($userId, $country);
        $repo  = new ElectricityReadingRepository($this->pdo(), $userId);
        $index = 1000.0;

        for ($m = 11; $m >= 0; $m--) {
            $repo->insertIndexes($this->monthPoint($m, $dayOfMonth), ['import_t1' => $index]);
            $index += $kwhPerMonth;
        }

        return $userId;
    }

    /** Horodatage dans le mois situé $monthsAgo mois avant le mois courant. */
    private function monthPoint(int $monthsAgo, int $dayOfMonth = 1): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', Dates::utc()))
            ->modify('first day of this month')
            ->setTime(12, 0, 0)
            ->modify('-' . $monthsAgo . ' months')
            ->modify('+' . ($dayOfMonth - 1) . ' days');
    }
}
