<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * dashboard.js rend tous les blocs de coûts côté client (#223) : ses libellés
 * viennent du sous-catalogue `dash.*` / `common.*` sérialisé par le template dans
 * #dashboard-data. Rien à l'exécution ne signale une clé manquante — `tr()`
 * retombe silencieusement sur la clé brute, qui s'afficherait à l'écran. Ce test
 * ferme les deux sens : aucune clé référencée sans traduction, aucune traduction
 * hors du périmètre exporté.
 */
final class DashboardJsCatalogTest extends TestCase
{
    private const JS = __DIR__ . '/../../../app/public/assets/js/dashboard.js';

    /** Préfixes réellement exportés par dashboard.php (`translations(...)`). */
    private const EXPORTED_PREFIXES = ['dash.', 'common.'];

    /**
     * Clés construites par concaténation, invisibles à une regex sur `tr('…')`.
     * `daysLabel()` choisit le suffixe via Intl.PluralRules.
     */
    private const DYNAMIC_KEYS = ['dash.meta.days_one', 'dash.meta.days_other'];

    /** @return list<string> */
    private function referencedKeys(): array
    {
        $source = file_get_contents(self::JS);
        self::assertIsString($source, 'dashboard.js doit être lisible.');

        preg_match_all("/\btr\('([^']+)'/", $source, $matches);
        $keys = array_merge($matches[1], self::DYNAMIC_KEYS, $this->fallbackKeys());

        // Les clés concaténées apparaissent tronquées dans la capture ('dash.meta.days_') :
        // seules les formes complètes déclarées ci-dessus sont vérifiables.
        $keys = array_filter($keys, static fn (string $k): bool => !str_ends_with($k, '_'));

        return array_values(array_unique($keys));
    }

    /**
     * Clés de repli passées à `reasonHtml(data, 'clé')` (#6) : elles ne traversent
     * `tr()` qu'à l'intérieur de cette fonction, donc la regex sur `tr('…')` ne les
     * voit pas. Sans cette capture, supprimer `dash.empty.water_reason` d'un
     * catalogue passerait la CI et afficherait la clé brute à l'écran.
     *
     * @return list<string>
     */
    private function fallbackKeys(): array
    {
        $source = (string) file_get_contents(self::JS);

        preg_match_all("/\breasonHtml\([^,)]+,\s*'([^']+)'\s*\)/", $source, $matches);
        self::assertNotEmpty($matches[1], 'Aucun repli reasonHtml() détecté : la regex a dérivé.');

        return array_values(array_unique($matches[1]));
    }

    /**
     * Repli sur la locale elle-même, et non sur le français par défaut : sinon
     * `allWithPrefix()` fusionne le catalogue fr par-dessus les trous de la locale
     * testée, et une clé réellement absente de en/nl/de passerait inaperçue ici.
     */
    private function translator(string $locale): Translator
    {
        return new Translator(__DIR__ . '/../../../app/translations', $locale, $locale);
    }

    public function testEveryKeyUsedByDashboardJsIsTranslatedInEveryLocale(): void
    {
        $keys = $this->referencedKeys();
        self::assertNotEmpty($keys, 'La détection des clés tr() ne doit pas rendre une liste vide.');

        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($keys as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "Clé {$key} utilisée par dashboard.js mais absente de {$locale}.php",
                );
            }
        }
    }

    public function testEveryKeyUsedByDashboardJsIsActuallyExportedToTheClient(): void
    {
        foreach ($this->referencedKeys() as $key) {
            $exported = array_filter(
                self::EXPORTED_PREFIXES,
                static fn (string $prefix): bool => str_starts_with($key, $prefix),
            );

            self::assertNotEmpty(
                $exported,
                sprintf(
                    'Clé %s hors des préfixes exportés (%s) : elle ne serait pas dans #dashboard-data.',
                    $key,
                    implode(', ', self::EXPORTED_PREFIXES),
                ),
            );
        }
    }

    public function testPlaceholdersMatchTheFrenchReference(): void
    {
        $reference = $this->translator('fr');
        $keys      = $this->referencedKeys();

        foreach (['en', 'nl', 'de'] as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($keys as $key) {
                // Assertion explicite : sans elle, une clé absente sort en `''`,
                // donc en liste de paramètres vide, et l'échec parlerait à tort
                // de paramètres divergents plutôt que de traduction manquante.
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
     * Un `tr()` dont les paramètres ne correspondent pas à ceux du message laisse
     * un `{name}` littéral à l'écran, ou perd une valeur silencieusement. Les deux
     * passent le lint et les autres tests.
     */
    public function testParametersPassedByDashboardJsMatchTheMessages(): void
    {
        $source  = (string) file_get_contents(self::JS);
        $catalog = $this->translator('fr')->allWithPrefix('');

        // Appels avec objet de paramètres : `tr('clé', { a: …, b: … })`.
        preg_match_all("/\btr\('([^']+)',\s*\{(.*?)\}\s*\)/s", $source, $calls, PREG_SET_ORDER);
        self::assertNotEmpty($calls, 'Aucun appel tr() paramétré détecté : la regex a dérivé.');

        foreach ($calls as [, $key, $objectLiteral]) {
            preg_match_all('/(?:^|,)\s*(\w+)\s*:/', $objectLiteral, $names);
            $passed = array_unique($names[1]);
            sort($passed);

            self::assertSame(
                $this->placeholders($catalog[$key] ?? ''),
                array_values($passed),
                "Paramètres passés à tr('{$key}') différents des {name} du message français",
            );
        }
    }

    public function testMessagesUsedWithoutParametersHaveNoPlaceholder(): void
    {
        $source  = (string) file_get_contents(self::JS);
        $catalog = $this->translator('fr')->allWithPrefix('');

        // Les replis de reasonHtml() sont rendus par `tr(fallbackKey)`, donc eux
        // aussi sans paramètre.
        preg_match_all("/\btr\('([^']+)'\)/", $source, $calls);
        foreach (array_unique([...$calls[1], ...$this->fallbackKeys()]) as $key) {
            self::assertSame(
                [],
                $this->placeholders($catalog[$key] ?? ''),
                "tr('{$key}') est appelé sans paramètre alors que le message en attend",
            );
        }
    }

    /** @return list<string> Noms de paramètres `{name}` triés. */
    private function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return array_values($names);
    }
}
