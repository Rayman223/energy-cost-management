<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Seo\PageMeta;
use App\View\GuideContent;
use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Contenu et maillage de la page d'accueil publique (#85).
 *
 * La landing n'offrait que quatre tuiles et un bouton « Se connecter » : aucun
 * lien vers `/stats`, la seule page riche du site. Pour un crawler entrant par
 * la racine, le reste du site n'existait pas. Ces tests verrouillent les liens
 * autant que le contenu.
 */
final class LandingContentTest extends TestCase
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

    /**
     * @param array{households: int, countries: int}|null $coverage
     */
    private function render(?array $coverage): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        return $view->render('welcome', [
            'available'     => ['fr', 'en'],
            'discordUrl'    => null,
            'donateUrl'     => null,
            'adsenseClient' => null,
            'coverage'      => $coverage,
            'meta'          => PageMeta::indexable(
                ['seo' => ['base_url' => 'https://energy-cost.eu'], 'i18n' => ['available' => ['fr']]],
                '',
                'fr',
                'Résumé.',
            ),
        ]);
    }

    public function testTheLandingLinksToStatsAndToEveryGuide(): void
    {
        $html = $this->render(['households' => 42, 'countries' => 3]);

        self::assertStringContainsString('href="/stats"', $html);
        self::assertStringContainsString('href="/guides"', $html);
        foreach (GuideContent::SLUGS as $slug) {
            self::assertStringContainsString('href="/guides/' . $slug . '"', $html);
        }
    }

    public function testRealFiguresAreShownWhenTheCorpusIsPublished(): void
    {
        $html = $this->render(['households' => 42, 'countries' => 3]);

        self::assertStringContainsString('landing-figure-value', $html);
        self::assertStringContainsString('42', $html);
    }

    public function testFiguresAreHiddenBelowTheAnonymityThreshold(): void
    {
        // coverage() renvoie 0 sous le seuil : afficher « 0 foyer » serait à la
        // fois faux et décourageant. La section reste, sans les chiffres.
        $html = $this->render(['households' => 0, 'countries' => 0]);

        self::assertStringNotContainsString('landing-figure-value', $html);
        self::assertStringContainsString('href="/stats"', $html);
    }

    public function testTheLandingStillRendersWhenTheDatabaseIsDown(): void
    {
        // La page d'accueil est la porte d'entrée du site : une base tombée ne
        // doit pas la faire disparaître.
        $html = $this->render(null);

        self::assertStringContainsString('<h1>', $html);
        self::assertStringNotContainsString('landing-figure-value', $html);
        self::assertStringContainsString('href="/guides"', $html);
    }

    public function testTheLandingIsIndexable(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        self::assertStringContainsString('<link rel="canonical"', $html);
    }
}
