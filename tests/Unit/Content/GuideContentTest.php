<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\I18n\Locale;
use App\View\GuideContent;
use PHPUnit\Framework\TestCase;

/**
 * Contenu éditorial des guides (#85).
 *
 * Deux garanties : le contenu existe **dans les quatre langues** — un guide qui
 * retombe silencieusement en français est exactement la régression que
 * `TranslationParityTest` empêche côté catalogues — et le slug venu de l'URL ne
 * peut pas servir à lire un fichier arbitraire.
 */
final class GuideContentTest extends TestCase
{
    /** @return list<string> */
    private function locales(): array
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/app/config/config.example.php';

        return Locale::available($config);
    }

    public function testEveryGuideExistsInEveryLocale(): void
    {
        foreach (GuideContent::SLUGS as $slug) {
            foreach ($this->locales() as $locale) {
                $path = \dirname(__DIR__, 3) . '/app/content/guides/' . $slug . '/' . $locale . '.php';
                self::assertFileExists($path, "Guide {$slug} manquant en {$locale}.");
            }
        }
    }

    public function testEveryTranslationHasTheSameSectionCountAsFrench(): void
    {
        foreach (GuideContent::SLUGS as $slug) {
            $reference = GuideContent::load($slug, 'fr');
            self::assertNotNull($reference);

            foreach ($this->locales() as $locale) {
                $guide = GuideContent::load($slug, $locale);
                self::assertNotNull($guide);
                self::assertCount(
                    \count($reference['sections']),
                    $guide['sections'],
                    "Le guide {$slug} n'a pas le même nombre de sections en {$locale} qu'en français.",
                );
            }
        }
    }

    public function testEveryGuideCarriesTheFieldsThePagesNeed(): void
    {
        foreach (GuideContent::SLUGS as $slug) {
            foreach ($this->locales() as $locale) {
                $guide = GuideContent::load($slug, $locale);
                self::assertNotNull($guide);

                self::assertNotSame('', $guide['title'], "{$slug}/{$locale} : titre vide.");
                // La description alimente la meta description et l'index.
                self::assertNotSame('', $guide['description'], "{$slug}/{$locale} : description vide.");
                self::assertNotSame('', $guide['intro'], "{$slug}/{$locale} : chapô vide.");
                self::assertNotSame([], $guide['sections'], "{$slug}/{$locale} : aucune section.");

                foreach ($guide['sections'] as $section) {
                    self::assertNotSame('', $section['h'], "{$slug}/{$locale} : section sans titre.");
                    self::assertNotSame([], $section['p'], "{$slug}/{$locale} : section sans paragraphe.");
                }
            }
        }
    }

    public function testDescriptionsStayWithinWhatSearchResultsShow(): void
    {
        foreach (GuideContent::SLUGS as $slug) {
            foreach ($this->locales() as $locale) {
                $guide = GuideContent::load($slug, $locale);
                self::assertNotNull($guide);
                // Au-delà d'environ 160 caractères, Google tronque.
                self::assertLessThanOrEqual(
                    200,
                    mb_strlen($guide['description']),
                    "{$slug}/{$locale} : description trop longue pour un résultat de recherche.",
                );
            }
        }
    }

    public function testUnknownSlugIsRejected(): void
    {
        self::assertNull(GuideContent::load('inconnu', 'fr'));
        self::assertNull(GuideContent::load('', 'fr'));
    }

    public function testSlugCannotEscapeTheContentDirectory(): void
    {
        // Le slug vient de l'URL et sert à construire un chemin : seule la
        // liste blanche doit pouvoir l'atteindre.
        foreach (['../../config/config', '../../../composer', 'kwh-price/../../../composer'] as $attempt) {
            self::assertNull(GuideContent::load($attempt, 'fr'));
        }
    }

    public function testMalformedLocaleIsRejectedButFallsBackToFrench(): void
    {
        $guide = GuideContent::load('kwh-price', '../fr');

        // La locale malformée est refusée ; le repli sert le français.
        self::assertNotNull($guide);
        self::assertSame(GuideContent::load('kwh-price', 'fr'), $guide);
    }

    public function testMissingTranslationFallsBackRatherThanFailing(): void
    {
        // Locale valide mais sans fichier : mieux vaut un guide en français
        // qu'une page d'erreur.
        $guide = GuideContent::load('kwh-price', 'it');

        self::assertNotNull($guide);
        self::assertSame(GuideContent::load('kwh-price', 'fr')['title'], $guide['title']);
    }

    public function testAllReturnsEveryGuideInOrder(): void
    {
        self::assertSame(
            GuideContent::SLUGS,
            array_column(GuideContent::all('fr'), 'slug'),
        );
    }
}
