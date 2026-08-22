<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de déploiement sur le seed `INSERT IGNORE INTO schema_migrations` de
 * schema.sql, qui marque des migrations comme déjà appliquées.
 *
 * Le seed est un piège quand la migration porte des DONNÉES : schema.sql est rejoué
 * sur base EXISTANTE, si bien qu'y inscrire une migration la marque appliquée AVANT
 * que migrate.php ne la lance — elle ne s'exécute alors jamais sur les bases qui en
 * avaient besoin, sans le moindre message.
 *
 * Ce test tourne SANS base : il protège l'exception documentée dans l'en-tête de la
 * migration contre un rangement bien intentionné, y compris dans le job CI qui n'a
 * pas de MariaDB.
 */
final class MigrationSeedGuardTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../app/sql/schema.sql';

    /**
     * Migrations de données à ne JAMAIS baseliner, avec la conséquence d'un oubli.
     *
     * @return array<string, array{string, string}>
     */
    public static function unbaselinableMigrations(): array
    {
        return [
            'bornes de fin exclues (#1)' => [
                '2026-08-21_exclusive_end_dates.sql',
                'les bases existantes ne seraient jamais migrées et toutes les grilles '
                . 'y perdraient leur dernier jour, en silence',
            ],
        ];
    }

    #[DataProvider('unbaselinableMigrations')]
    public function testDataMigrationsAreNotBaselinedInSchemaSeed(string $migration, string $consequence): void
    {
        $schema = file_get_contents(self::SCHEMA);
        self::assertIsString($schema, 'schema.sql introuvable : ' . self::SCHEMA);

        self::assertStringNotContainsString(
            $migration,
            $schema,
            $migration . ' ne doit pas être baselinée dans schema.sql : ' . $consequence . '.',
        );
    }

    /**
     * La migration existe bien là où le garde-fou la cherche : sans ce contrôle, un
     * fichier renommé rendrait le test ci-dessus vert pour de mauvaises raisons.
     */
    public function testGuardedMigrationsExist(): void
    {
        foreach (self::unbaselinableMigrations() as $case) {
            self::assertFileExists(__DIR__ . '/../../../app/sql/migrations/' . $case[0]);
        }
    }
}
