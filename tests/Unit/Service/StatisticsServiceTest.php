<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\TariffCategory;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Repository\Contract\StatisticsRepositoryInterface;
use App\Service\StatisticsService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeStatisticsRepository;

/**
 * Mise en forme des statistiques communautaires (#8). Aucune base : le seuil de
 * k-anonymat est appliqué en SQL et vérifié par StatisticsRepositoryDbTest ; ce
 * qui se joue ici, c'est ce que le service en fait ensuite.
 *
 * Trois familles de pièges y sont couvertes :
 *   - la MOYENNE DE MOYENNES dans le bucket « Autres », qui donnerait un chiffre
 *     faux dès que deux pays n'ont pas le même nombre de foyers ;
 *   - les DIVISIONS PAR ZÉRO des écarts en %, qui doivent rendre null (« — »)
 *     et jamais 0 % — lequel se lirait « identique à la moyenne » ;
 *   - la MÉMOÏSATION, qui n'est pas une optimisation cosmétique : sans elle la
 *     page rejoue tout l'agrégat une seconde fois pour le bloc privé.
 */
final class StatisticsServiceTest extends TestCase
{
    private const K = StatisticsRepositoryInterface::MIN_HOUSEHOLDS;
    private const OTHER = StatisticsRepositoryInterface::OTHER_BUCKET;

    public function testPricesArePublishedWithTheCountryExpectedCurrency(): void
    {
        $repo = new FakeStatisticsRepository();
        // Un pays peut porter deux devises (foyer expatrié, grille saisie en CHF).
        // Le projet ne convertit jamais : la devise attendue du pays l'emporte.
        $repo->rates = [
            $this->rate('BE', 'CHF', 12, 3.0),
            $this->rate('BE', 'EUR', 6, 0.30),
        ];

        $prices = $this->service($repo)->publicOverview()['prices'];

        self::assertCount(1, $prices);
        self::assertSame('EUR', $prices[0]['currency']);
        self::assertEqualsWithDelta(0.30, $prices[0]['ttc_per_kwh'], 1e-9);
    }

    public function testCountryOutsideTheReferenceFallsBackToItsLargestCurrencyGroup(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates = [
            $this->rate('XX', 'USD', 3, 0.15),
            $this->rate('XX', 'EUR', 9, 0.25),
        ];

        $prices = $this->service($repo)->publicOverview()['prices'];

        self::assertSame('EUR', $prices[0]['currency']);
        self::assertSame(9, $prices[0]['households']);
    }

    public function testOtherBucketReportsHouseholdsButNeverAPrice(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates = [
            $this->rate('BE', 'EUR', 10, 0.30),
            $this->rate(self::OTHER, 'EUR', 4, 0.20),
            $this->rate(self::OTHER, 'PLN', 3, 0.90),
        ];

        $prices = $this->service($repo)->publicOverview()['prices'];
        $other  = $this->find($prices, self::OTHER);

        self::assertNotNull($other);
        self::assertTrue($other['is_other']);
        self::assertSame(7, $other['households'], 'Les foyers des devises se cumulent.');
        self::assertNull($other['ttc_per_kwh'], 'Mélanger des devises ne ferait pas un prix.');
        // Le bucket « Autres » ferme la liste : c'est un résidu, pas un pays.
        self::assertSame(self::OTHER, $prices[count($prices) - 1]['country']);
    }

    public function testPublishedValuesAreRoundedToBlurRepeatedObservation(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates       = [$this->rate('BE', 'EUR', 10, 0.3047321)];
        $repo->electricity = [['bucket' => 'BE', 'households' => 10, 'value' => 3417.6]];

        $overview = $this->service($repo)->publicOverview();

        // Le k-anonymat protège mal contre l'observation répétée : entre deux
        // publications, la différence des moyennes livrerait la valeur d'un
        // nouvel arrivant. L'arrondi rend cette soustraction imprécise.
        self::assertEqualsWithDelta(0.305, $overview['prices'][0]['ttc_per_kwh'], 1e-9);
        self::assertEqualsWithDelta(3420.0, $overview['electricity'][0]['value'], 1e-9);
    }

    public function testHourlySpotResolutionWinsOverQuarterHourly(): void
    {
        $repo = new FakeStatisticsRepository();
        // ENTSO-E publie les deux résolutions sur les mêmes heures pour certaines
        // zones : en afficher une seule évite de compter l'heure deux fois.
        $repo->spot = [
            $this->spotRow('ZONE_A', 15, 0.20),
            $this->spotRow('ZONE_A', 60, 0.10),
            $this->spotRow('ZONE_B', 15, 0.30),
        ];

        $spot = $this->service($repo)->publicOverview()['spot'];

        self::assertCount(2, $spot);
        self::assertSame(60, $spot[0]['resolution_min']);
        self::assertSame(15, $spot[1]['resolution_min'], 'Une zone sans série horaire garde la 15 min.');
    }

    public function testOverviewIsComputedOnceEvenWhenReadTwice(): void
    {
        $repo = new FakeStatisticsRepository();
        $service = $this->service($repo);

        $service->publicOverview();
        $service->publicOverview();

        self::assertSame(1, $repo->calls['unitRateByCountry'] ?? 0);
        self::assertSame(1, $repo->calls['electricityUsageByCountry'] ?? 0);
    }

    public function testNoDataIsReportedAsSuchRatherThanAsEmptyTables(): void
    {
        $overview = $this->service(new FakeStatisticsRepository())->publicOverview();

        self::assertFalse($overview['has_data']);
        self::assertSame(self::K, $overview['k']);
    }

    // ── Bloc privé ─────────────────────────────────────────────────────────

    public function testComparisonIsRefusedWhenTheProfileHasNoCountry(): void
    {
        $service = $this->service(new FakeStatisticsRepository());

        // Sans pays, il n'y a rien à quoi se comparer : la vue doit inviter à le
        // renseigner, pas afficher un bloc de tirets.
        self::assertNull($service->privateComparison(1, null, 'EUR', false, null, null));
        self::assertNull($service->privateComparison(1, '', 'EUR', false, null, null));
    }

    public function testComparisonExposesTheGapToTheCountryAverage(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates       = [$this->rate('BE', 'EUR', 10, 0.30)];
        $repo->electricity = [['bucket' => 'BE', 'households' => 10, 'value' => 3000.0]];
        $repo->rateRank    = ['below' => 7, 'total' => 10];
        $repo->householdSeries = ['2026-01' => 300.0, '2026-02' => 300.0, '2026-03' => 300.0];

        $result = $this->service($repo)->privateComparison(
            1,
            'BE',
            'EUR',
            false,
            $this->grid([['energy_flat', 0.33]]),
            null,
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(0.33, $result['my_rate'], 1e-9);
        self::assertEqualsWithDelta(0.30, $result['country_rate'], 1e-9);
        self::assertEqualsWithDelta(10.0, $result['rate_delta_pct'], 1e-9);
        self::assertSame(70, $result['rate_percentile']);
        self::assertEqualsWithDelta(3600.0, $result['my_usage'], 1e-9);
    }

    public function testPercentileIsWithheldWhenTheCountryIsBelowTheThreshold(): void
    {
        $repo = new FakeStatisticsRepository();
        // Le repository refuse déjà de classer sous le seuil : le service ne doit
        // pas fabriquer un rang à partir de rien.
        $repo->rateRank  = null;
        $repo->usageRank = null;

        $result = $this->service($repo)->privateComparison(
            1,
            'BE',
            'EUR',
            false,
            $this->grid([['energy_flat', 0.30]]),
            null,
        );

        self::assertNotNull($result);
        self::assertNull($result['rate_percentile']);
        self::assertNull($result['usage_percentile']);
    }

    public function testOptedOutHouseholdStillSeesItsOwnComparison(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates = [$this->rate('BE', 'EUR', 10, 0.30)];

        $result = $this->service($repo)->privateComparison(
            1,
            'BE',
            'EUR',
            true,
            $this->grid([['energy_flat', 0.30]]),
            null,
        );

        self::assertNotNull($result, 'Se retirer des statistiques publiques ne prive pas de son propre bilan.');
        self::assertTrue($result['opted_out'], 'La vue doit pouvoir dire « vous n\'êtes pas compté ».');
    }

    public function testMissingMonthsAreHolesInTheCurveNotZeroes(): void
    {
        $repo = new FakeStatisticsRepository();
        $from = new DateTimeImmutable('2026-01-01 00:00:00');
        $to   = new DateTimeImmutable('2026-04-01 00:00:00');

        $repo->householdSeries = ['2026-01' => 300.0, '2026-03' => 200.0];
        // Février manque côté pays : sous le seuil ce mois-là.
        $repo->countrySeries   = ['2026-01' => 250.0, '2026-02' => 240.0];

        $result = $this->service($repo)->privateComparison(
            1,
            'BE',
            'EUR',
            false,
            null,
            null,
            $from,
            $to,
        );

        self::assertNotNull($result);
        $monthly = $result['monthly'];
        self::assertCount(3, $monthly, 'Un point par mois de la fenêtre, présent ou non.');

        self::assertEqualsWithDelta(20.0, $monthly[0]['delta_pct'], 1e-9);
        self::assertNull($monthly[1]['mine'], 'Mois sans relevé : trou, pas zéro.');
        self::assertNull($monthly[2]['average'], 'Mois sous le seuil : trou, pas zéro.');
        self::assertNull($monthly[2]['delta_pct'], 'Sans référence, pas de pourcentage.');
    }

    public function testDeltaAgainstAZeroReferenceIsUndefinedRatherThanZeroPercent(): void
    {
        // 0 % se lirait « identique à la moyenne », ce qui serait faux.
        self::assertNull(StatisticsService::deltaPct(120.0, 0.0));
        self::assertNull(StatisticsService::deltaPct(120.0, null));
        self::assertNull(StatisticsService::deltaPct(null, 100.0));
        self::assertEqualsWithDelta(-25.0, StatisticsService::deltaPct(75.0, 100.0), 1e-9);
    }

    public function testCategoryComparisonExcludesNonVariableComponents(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->categories = [
            ['bucket' => 'BE', 'currency' => 'EUR', 'category' => 'energy', 'households' => 10, 'ttc_per_kwh' => 0.20],
            ['bucket' => 'BE', 'currency' => 'EUR', 'category' => 'taxes',  'households' => 10, 'ttc_per_kwh' => 0.05],
            ['bucket' => 'FR', 'currency' => 'EUR', 'category' => 'energy', 'households' => 8,  'ttc_per_kwh' => 0.18],
        ];

        $result = $this->service($repo)->privateComparison(
            1,
            'BE',
            'EUR',
            false,
            $this->grid([['energy_flat', 0.25], ['per_kwh', 0.04], ['fixed_monthly', 12.0], ['injection_t1', 0.03]]),
            null,
        );

        self::assertNotNull($result);
        $byCategory = array_column($result['categories'], null, 'category');

        self::assertEqualsWithDelta(0.25, $byCategory['energy']['mine'], 1e-9);
        self::assertEqualsWithDelta(0.20, $byCategory['energy']['average'], 1e-9);
        self::assertEqualsWithDelta(0.04, $byCategory['taxes']['mine'], 1e-9);
        // L'abonnement et le crédit d'injection ne sont pas des €/kWh : ils
        // n'apparaissent ni chez moi, ni dans la moyenne du pays.
        self::assertArrayNotHasKey('fixed', $byCategory);
        self::assertArrayNotHasKey('injection', $byCategory);
        // Les catégories d'un autre pays ne fuitent pas dans ma comparaison.
        self::assertEqualsWithDelta(0.20, $byCategory['energy']['average'], 1e-9);
    }

    public function testUsageIsNotAnnualisedFromTooFewMonths(): void
    {
        $repo = new FakeStatisticsRepository();
        // Deux mois observés : les multiplier par six projetterait une saison sur
        // l'année entière. Même règle que le SQL agrégé, sans quoi « moi » et « la
        // moyenne » ne seraient pas calculés pareil.
        $repo->householdSeries = ['2026-01' => 500.0, '2026-02' => 480.0];

        $result = $this->service($repo)->privateComparison(1, 'BE', 'EUR', false, null, null);

        self::assertNotNull($result);
        self::assertNull($result['my_usage']);
        self::assertNull($result['usage_delta_pct']);
    }

    // ── Fabriques ──────────────────────────────────────────────────────────

    private function service(FakeStatisticsRepository $repo): StatisticsService
    {
        return new StatisticsService($repo);
    }

    /** @return array{bucket: string, currency: string, households: int, ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float} */
    private function rate(string $bucket, string $currency, int $households, float $ttc): array
    {
        return [
            'bucket'         => $bucket,
            'currency'       => $currency,
            'households'     => $households,
            'ttc_per_kwh'    => $ttc,
            'htva_per_kwh'   => $ttc / 1.21,
            'fixed_year_ttc' => 144.0,
        ];
    }

    /** @return array{zone: string, resolution_min: int, avg_price: float, points: int, first_at: string, last_at: string} */
    private function spotRow(string $zone, int $resolution, float $price): array
    {
        return [
            'zone'           => $zone,
            'resolution_min' => $resolution,
            'avg_price'      => $price,
            'points'         => 100,
            'first_at'       => '2026-01-01 00:00:00',
            'last_at'        => '2026-12-31 23:00:00',
        ];
    }

    /** @param list<array{0: string, 1: float}> $lines */
    private function grid(array $lines): TariffGrid
    {
        $built = [];
        foreach ($lines as $i => [$kind, $amount]) {
            $componentKind = ComponentKind::from($kind);
            $key = 'line_' . $i;
            $built[$key] = new TariffLine(
                key: $key,
                amount: $amount,
                kind: $componentKind,
                label: null,
                sortOrder: $i,
                category: TariffCategory::defaultForKind($componentKind),
            );
        }

        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Grille test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: $built,
            pcsCoefficient: null,
            userId: 1,
            country: 'BE',
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function find(array $rows, string $country): ?array
    {
        foreach ($rows as $row) {
            if ($row['country'] === $country) {
                return $row;
            }
        }

        return null;
    }
}
