<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Garantit la parité des clés entre tous les catalogues de traduction
 * (app/translations/*.php). Empêche la récidive du décalage NL/DE de l'audit
 * #130 C2 : une page rendue dans une langue au catalogue incomplet retombe
 * silencieusement sur le fallback (français), ce qui n'est visible qu'à l'usage.
 */
final class TranslationParityTest extends TestCase
{
    private const REFERENCE = 'en';

    /** @return array<string, array<string, string>> locale => (clé => valeur) */
    private function catalogs(): array
    {
        $dir = __DIR__ . '/../../../app/translations';
        $catalogs = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            $data = require $file;
            self::assertIsArray($data, "Le catalogue {$locale} doit retourner un tableau.");
            $catalogs[$locale] = $data;
        }

        return $catalogs;
    }

    public function testReferenceCatalogExists(): void
    {
        self::assertArrayHasKey(self::REFERENCE, $this->catalogs());
    }

    public function testAllCatalogsShareTheSameKeysAsReference(): void
    {
        $catalogs = $this->catalogs();
        $referenceKeys = array_keys($catalogs[self::REFERENCE]);

        foreach ($catalogs as $locale => $data) {
            if ($locale === self::REFERENCE) {
                continue;
            }

            $keys = array_keys($data);
            $missing = array_diff($referenceKeys, $keys);
            $extra   = array_diff($keys, $referenceKeys);

            self::assertSame(
                [],
                array_values($missing),
                sprintf('Clés manquantes dans %s.php : %s', $locale, implode(', ', $missing)),
            );
            self::assertSame(
                [],
                array_values($extra),
                sprintf('Clés en trop dans %s.php (absentes de %s.php) : %s', $locale, self::REFERENCE, implode(', ', $extra)),
            );
        }
    }
}
