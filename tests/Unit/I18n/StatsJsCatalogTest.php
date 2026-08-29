<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\Domain\TariffCategory;
use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * stats.js étiquette tous les graphiques de /stats (#8) depuis le sous-catalogue
 * `stats.chart.*` / `common.*` sérialisé par le template dans #stats-data. Rien à
 * l'exécution ne signale une clé manquante : `tr()` retombe sur son repli anglais
 * codé en dur, qui s'afficherait tel quel à un lecteur néerlandophone.
 *
 * Pendant de {@see DashboardJsCatalogTest}, avec une différence assumée : le
 * périmètre exporté est `stats.chart.` et non `stats.`. Les libellés serveur de
 * la page vivent sous `stats.` et n'ont rien à faire dans le navigateur ; les
 * confondre obligerait à exporter tout le catalogue de la page au client.
 */
final class StatsJsCatalogTest extends TestCase
{
    private const JS = __DIR__ . '/../../../app/public/assets/js/stats.js';

    /** Préfixes réellement exportés par stats.php (`translations(...)`). */
    private const EXPORTED_PREFIXES = ['stats.chart.', 'common.'];

    /** @var list<string> */
    private const LOCALES = ['fr', 'en', 'nl', 'de'];

    /**
     * Clés construites par concaténation (`'stats.chart.category_' + r.category`),
     * invisibles à une regex sur `tr('…')`. Les catégories viennent de
     * {@see TariffCategory} : les dériver de l'enum plutôt que de les lister à la
     * main garantit qu'une catégorie ajoutée réclame sa traduction.
     *
     * @return list<string>
     */
    private function dynamicKeys(): array
    {
        $keys = [];
        foreach (TariffCategory::cases() as $category) {
            $keys[] = 'stats.chart.category_' . $category->value;
        }

        return $keys;
    }

    /** @return list<string> */
    private function referencedKeys(): array
    {
        $source = file_get_contents(self::JS);
        self::assertIsString($source, 'stats.js doit être lisible.');

        preg_match_all("/\btr\('([^']+)'/", $source, $matches);
        self::assertNotEmpty($matches[1], 'Aucun appel tr() détecté : la regex a dérivé.');

        $keys = array_merge($matches[1], $this->dynamicKeys());

        // Les clés concaténées apparaissent tronquées dans la capture
        // ('stats.chart.category_') : seules les formes complètes déclarées
        // ci-dessus sont vérifiables.
        $keys = array_filter($keys, static fn (string $k): bool => !str_ends_with($k, '_'));

        return array_values(array_unique($keys));
    }

    /**
     * Repli sur la locale elle-même, et non sur le français : sinon
     * `allWithPrefix()` comblerait les trous de la locale testée avec fr.php, et
     * une clé réellement absente de en/nl/de passerait inaperçue.
     */
    private function translator(string $locale): Translator
    {
        return new Translator(__DIR__ . '/../../../app/translations', $locale, $locale);
    }

    public function testEveryKeyUsedByStatsJsIsTranslatedInEveryLocale(): void
    {
        $keys = $this->referencedKeys();

        foreach (self::LOCALES as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($keys as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "Clé {$key} utilisée par stats.js mais absente de {$locale}.php",
                );
            }
        }
    }

    public function testEveryKeyUsedByStatsJsIsActuallyExportedToTheClient(): void
    {
        foreach ($this->referencedKeys() as $key) {
            $exported = array_filter(
                self::EXPORTED_PREFIXES,
                static fn (string $prefix): bool => str_starts_with($key, $prefix),
            );

            self::assertNotEmpty(
                $exported,
                sprintf(
                    'Clé %s hors des préfixes exportés (%s) : elle ne serait pas dans #stats-data.',
                    $key,
                    implode(', ', self::EXPORTED_PREFIXES),
                ),
            );
        }
    }

    public function testPlaceholdersMatchTheFrenchReference(): void
    {
        $reference = $this->translator('fr');

        foreach (['en', 'nl', 'de'] as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($this->referencedKeys() as $key) {
                // Assertion explicite : sans elle, une clé absente sort en '' et
                // l'échec parlerait de paramètres divergents plutôt que de
                // traduction manquante.
                self::assertArrayHasKey($key, $catalog, "Clé {$key} absente de {$locale}.php");

                self::assertSame(
                    $this->placeholders($reference->t($key)),
                    $this->placeholders($catalog[$key]),
                    "Paramètres {name} divergents entre fr.php et {$locale}.php pour {$key}",
                );
            }
        }
    }

    /**
     * Le sens inverse : une clé du périmètre exporté que plus personne n'utilise
     * alourdit #stats-data à chaque chargement de page pour rien.
     */
    public function testExportedChartCatalogHasNoUnusedKey(): void
    {
        $referenced = $this->referencedKeys();

        foreach ($this->translator('fr')->allWithPrefix('stats.chart.') as $key => $_) {
            self::assertContains(
                $key,
                $referenced,
                "Clé {$key} exportée vers #stats-data mais utilisée par personne.",
            );
        }
    }

    /** @return list<string> */
    private function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return array_values($names);
    }
}
