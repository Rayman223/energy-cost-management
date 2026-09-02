<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Les graphiques de /stats se sont un jour étirés sur des milliers de pixels (#42).
 *
 * La cause tient à un couplage que rien ne rend visible : stats.js trace avec
 * `maintainAspectRatio: false`, ce qui délègue le dimensionnement du canvas à son
 * parent — et `.chart-box` est une cellule de grid dont la hauteur vient de son
 * contenu. Sans hauteur imposée en CSS, le canvas se nourrit de sa propre hauteur à
 * chaque redimensionnement. L'option JS et la règle CSS vivent dans deux fichiers
 * qu'aucun outil ne relie, et la page reste parfaitement fonctionnelle en test :
 * seul un œil humain devant un navigateur voit la régression.
 *
 * Ce test tient donc le contrat entre les deux fichiers, dans l'esprit de
 * {@see \Tests\Unit\I18n\StatsJsCatalogTest}, qui lit lui aussi le source JS.
 */
final class StatsChartHeightTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../app/public/assets/js/stats.js';
    private const CSS = __DIR__ . '/../../../app/public/assets/css/stats.css';

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, basename($path) . ' doit être lisible.');

        return $source;
    }

    public function testChartCanvasesHaveAFixedHeightWheneverAspectRatioIsDisabled(): void
    {
        if (!str_contains($this->read(self::JS), 'maintainAspectRatio: false')) {
            // Chart.js redimensionne alors à ratio constant : la boucle de
            // croissance n'existe plus, la règle CSS n'est plus obligatoire.
            self::markTestSkipped('stats.js ne désactive plus maintainAspectRatio.');
        }

        self::assertMatchesRegularExpression(
            '/\.chart-box\s+canvas\s*\{[^}]*height:\s*\d+px\s*!important/',
            $this->read(self::CSS),
            'stats.js trace avec maintainAspectRatio:false : sans hauteur fixe et '
            . '!important sur .chart-box canvas, le canvas croît sans fin (#42).',
        );
    }

    /**
     * La classe est posée par le JS et stylée nulle part ailleurs : sans sa règle,
     * le message d'état vide s'affiche sans hauteur et désaligne la grille.
     */
    public function testTheEmptyStateClassPostedByTheScriptIsStyled(): void
    {
        self::assertStringContainsString(
            "note.className = 'chart-empty'",
            $this->read(self::JS),
            'renderEmpty() doit poser la classe .chart-empty.',
        );

        self::assertMatchesRegularExpression(
            '/\.chart-empty\s*\{[^}]*height:\s*\d+px/',
            $this->read(self::CSS),
            '.chart-empty doit occuper la hauteur du canvas qu\'elle remplace.',
        );
    }

    /**
     * Les deux hauteurs doivent coïncider, sinon un cadre vide et un cadre tracé
     * côte à côte dans la même ligne de grid ne s'alignent pas.
     */
    public function testTheEmptyStateMatchesTheCanvasHeight(): void
    {
        $css = $this->read(self::CSS);

        preg_match('/\.chart-box\s+canvas\s*\{[^}]*height:\s*(\d+)px/', $css, $canvas);
        preg_match('/\.chart-empty\s*\{[^}]*height:\s*(\d+)px/', $css, $empty);

        self::assertNotEmpty($canvas, 'Hauteur de .chart-box canvas introuvable.');
        self::assertNotEmpty($empty, 'Hauteur de .chart-empty introuvable.');
        self::assertSame($canvas[1], $empty[1]);
    }
}
