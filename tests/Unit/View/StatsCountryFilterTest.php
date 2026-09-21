<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Domain\EuropeanCountries;
use App\Repository\Contract\StatisticsRepositoryInterface;
use App\Service\StatisticsService;
use App\Support\Url;
use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeStatisticsRepository;

/**
 * Sélecteur de pays et récapitulatif global de `/stats` (#85).
 *
 * Le filtre est **serveur** : chaque pays a son URL, la page reste utilisable
 * sans JavaScript, et Google voit autant de pages de contenu que de pays
 * publiés. Le test rend donc le vrai template, pas un fragment.
 */
final class StatsCountryFilterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server           = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function service(): StatisticsService
    {
        $repo = new FakeStatisticsRepository();
        $repo->rates = [
            $this->rate('BE', 'EUR', 40, 0.34),
            $this->rate('FR', 'EUR', 10, 0.24),
        ];
        $repo->electricity = [
            ['bucket' => 'BE', 'households' => 40, 'value' => 3500.0],
            ['bucket' => 'FR', 'households' => 10, 'value' => 4500.0],
        ];
        $repo->mix      = [['bucket' => 'BE', 'fixed' => 30, 'dynamic' => 10]];
        $repo->coverage = ['households' => 50, 'countries' => 2];

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

    private function render(string $requestedCountry): string
    {
        $stats = $this->service();
        $view  = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        return $view->render('stats', [
            'dbError'          => null,
            'overview'         => $stats->publicOverview(),
            'overall'          => $stats->overallSummary(),
            'countries'        => $stats->publishedCountries(),
            'countryDetail'    => $requestedCountry !== '' ? $stats->countryDetail($requestedCountry) : null,
            'requestedCountry' => $requestedCountry,
            'private'          => null,
            'authenticated'    => false,
            'isAdmin'          => false,
            'currency'         => 'EUR',
            'clockTimezone'    => null,
            'available'        => ['fr', 'en'],
            'discordUrl'       => null,
            'donateUrl'        => null,
            'adsenseClient'    => null,
            'meta'             => null,
        ]);
    }

    public function testTheSelectorListsEveryPublishedCountryAndSubmitsByGet(): void
    {
        $html = $this->render('');

        self::assertStringContainsString('method="get"', $html);
        self::assertStringContainsString('action="' . Url::to('stats') . '"', $html);
        self::assertStringContainsString('<option value="BE"', $html);
        self::assertStringContainsString('<option value="FR"', $html);
        // « Tous les pays » est la valeur vide : elle produit /stats sans paramètre.
        self::assertStringContainsString('<option value="">', $html);
    }

    public function testTheOverallSummaryIsShownWithoutAnySelection(): void
    {
        $html = $this->render('');

        self::assertStringContainsString('Tous pays confondus', $html);
        // Moyenne pondérée : (40 × 0,34 + 10 × 0,24) / 50 = 0,32. Séparateur
        // décimal laissé libre : il dépend de la présence d'ext-intl.
        self::assertMatchesRegularExpression('/0[.,]32/', $html);
    }

    public function testSelectingACountryShowsItsDetailAndMarksTheOption(): void
    {
        $html = $this->render('BE');

        self::assertStringContainsString('<option value="BE" selected', $html);
        // Nom résolu par le référentiel et non codé en dur : sans ext-intl le
        // repli est anglophone, et un littéral « Belgique » ne matcherait rien.
        self::assertStringContainsString(EuropeanCountries::name('BE', 'fr'), $html);
        // L'écart au global est affiché, et il est défavorable : 0,34 contre 0,32.
        self::assertStringContainsString('stat-delta--up', $html);
    }

    public function testAnUnpublishedCountryFallsBackToTheOverallViewWithAnExplanation(): void
    {
        // Pays valide, mais sous le seuil d'anonymat : pas de fiche.
        $html = $this->render('DE');

        self::assertStringContainsString("pas encore assez de foyers contributeurs", $html);
        self::assertStringNotContainsString('<option value="DE" selected', $html);
        // La page reste utile : le récapitulatif global est toujours là.
        self::assertStringContainsString('Tous pays confondus', $html);
    }

    public function testTheResidualBucketCanBeSelectedLikeAnyOtherRow(): void
    {
        $repo = new FakeStatisticsRepository();
        $repo->electricity = [
            ['bucket' => 'BE', 'households' => 10, 'value' => 3000.0],
            ['bucket' => StatisticsRepositoryInterface::OTHER_BUCKET, 'households' => 8, 'value' => 5000.0],
        ];
        $stats = new StatisticsService($repo);

        self::assertContains(
            StatisticsRepositoryInterface::OTHER_BUCKET,
            $stats->publishedCountries(),
        );
        self::assertNotNull($stats->countryDetail(StatisticsRepositoryInterface::OTHER_BUCKET));
    }

    public function testResidualBucketIsNotOfferedToSearchEngines(): void
    {
        // Le bucket « autres pays » a une fiche consultable, mais il n'est pas
        // un pays : le sitemap l'exclut, le canonical ne doit pas le proposer.
        // (La règle vit dans app/routes/stats.php ; on vérifie ici le critère
        // dont elle dépend.)
        $repo = new FakeStatisticsRepository();
        $repo->electricity = [
            ['bucket' => StatisticsRepositoryInterface::OTHER_BUCKET, 'households' => 8, 'value' => 5000.0],
        ];

        $detail = (new StatisticsService($repo))->countryDetail(StatisticsRepositoryInterface::OTHER_BUCKET);

        self::assertNotNull($detail);
        self::assertTrue($detail['is_other']);
    }

    public function testEmptyCorpusShowsNoSelectorAtAll(): void
    {
        $stats = new StatisticsService(new FakeStatisticsRepository());
        $view  = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        $html = $view->render('stats', [
            'dbError'          => null,
            'overview'         => $stats->publicOverview(),
            'overall'          => $stats->overallSummary(),
            'countries'        => $stats->publishedCountries(),
            'countryDetail'    => null,
            'requestedCountry' => '',
            'private'          => null,
            'authenticated'    => false,
            'isAdmin'          => false,
            'currency'         => 'EUR',
            'clockTimezone'    => null,
            'available'        => ['fr'],
            'discordUrl'       => null,
            'donateUrl'        => null,
            'adsenseClient'    => null,
            'meta'             => null,
        ]);

        // Une liste déroulante vide serait une impasse : elle disparaît.
        self::assertStringNotContainsString('country-filter', $html);
        // Mais le contenu éditorial permanent, lui, reste affiché.
        self::assertStringContainsString('Comment ces chiffres sont calculés', $html);
    }
}
