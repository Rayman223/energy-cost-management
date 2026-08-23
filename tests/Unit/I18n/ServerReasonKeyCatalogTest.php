<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * `CostCalculationService` ne traduit pas : il pose une clé de catalogue
 * (`reason_key`) que dashboard.js rend côté client (#6). Ces clés-là échappent à
 * {@see DashboardJsCatalogTest}, qui ne voit que les `tr('…')` littéraux du JS —
 * une clé serveur orpheline traverserait donc toutes les vérifications et
 * s'afficherait telle quelle à l'écran.
 */
final class ServerReasonKeyCatalogTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../../app/src/Service/CostCalculationService.php';

    /** Préfixes réellement exportés par dashboard.php (`translations(...)`). */
    private const EXPORTED_PREFIXES = ['dash.', 'common.'];

    /** @return list<string> */
    private function reasonKeys(): array
    {
        $source = file_get_contents(self::SERVICE);
        self::assertIsString($source, 'CostCalculationService.php doit être lisible.');

        preg_match_all("/'(dash\.[a-z0-9_.]+)'/", $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        self::assertNotEmpty($keys, 'Aucune clé de catalogue détectée : la regex a dérivé.');

        return $keys;
    }

    public function testEveryServerReasonKeyIsTranslatedInEveryLocale(): void
    {
        $keys = $this->reasonKeys();

        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            // Repli sur la locale elle-même : sinon le catalogue fr comblerait les
            // trous des autres langues, et une clé absente passerait inaperçue.
            $catalog = (new Translator(__DIR__ . '/../../../app/translations', $locale, $locale))->allWithPrefix('');

            foreach ($keys as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "Clé {$key} posée par CostCalculationService mais absente de {$locale}.php",
                );
            }
        }
    }

    public function testEveryServerReasonKeyIsExportedToTheClient(): void
    {
        foreach ($this->reasonKeys() as $key) {
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

    /**
     * Ces messages sont rendus sans paramètre : le service ne transmet aucune
     * valeur à substituer, un `{name}` resté dans la traduction s'afficherait brut.
     */
    public function testServerReasonMessagesTakeNoParameter(): void
    {
        $reference = new Translator(__DIR__ . '/../../../app/translations', 'fr', 'fr');

        foreach ($this->reasonKeys() as $key) {
            self::assertSame(
                0,
                preg_match('/\{\w+\}/', $reference->t($key)),
                "Le message {$key} attend un paramètre, que le serveur ne transmet pas.",
            );
        }
    }
}
