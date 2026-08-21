<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\TariffLineCatalog;
use App\Infrastructure\MigrationRunner;
use PDO;

/**
 * Test d'intégration de la migration 2026-07-31_purge_malformed_line_keys.sql (#269).
 * S'auto-skippe sans base de test joignable.
 *
 * Le prédicat SQL de la migration est le miroir de
 * {@see TariffLineCatalog::KEY_PATTERN}, avec deux subtilités faciles à casser sans
 * que rien ne le signale : `BINARY` (sans lui, la collation utf8mb4_general_ci rend
 * REGEXP insensible à la casse et « Energy_T1 » survit) et `\z` au lieu de `$` (qui
 * tolère un `\n` final). D'où ce test : la purge doit supprimer exactement les clés
 * que l'API refuse désormais, ni plus ni moins.
 */
final class MalformedLineKeyPurgeDbTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/sql/migrations/2026-07-31_purge_malformed_line_keys.sql';

    protected function clean(): void
    {
        foreach (['tariff_grid_lines', 'tariff_grids'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    /** Rejoue la migration telle que le runner l'exécuterait. */
    private function runMigration(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        self::assertIsString($sql, 'Migration introuvable : ' . self::MIGRATION);

        foreach (MigrationRunner::splitStatements($sql) as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    /** @param list<string> $keys */
    private function seedGrid(array $keys): int
    {
        $this->pdo()->exec(
            "INSERT INTO tariff_grids (energy_type, name, valid_from) VALUES ('electricity', 'Purge #269', '2026-01-01')"
        );
        $gridId = (int) $this->pdo()->lastInsertId();

        $stmt = $this->pdo()->prepare(
            'INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, component_kind, amount_per_kwh)
             VALUES (:gid, :key, :kind, :amount)'
        );
        foreach ($keys as $key) {
            $stmt->execute(['gid' => $gridId, 'key' => $key, 'kind' => 'per_kwh', 'amount' => 0.1]);
        }

        return $gridId;
    }

    /** @return list<string> */
    private function remainingKeys(int $gridId): array
    {
        $stmt = $this->pdo()->prepare('SELECT line_key FROM tariff_grid_lines WHERE tariff_grid_id = :gid ORDER BY id');
        $stmt->execute(['gid' => $gridId]);

        return array_map(static fn (mixed $v): string => (string) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testPurgeRemovesExactlyTheKeysTheApiNowRejects(): void
    {
        $valid = ['energy_t1', 'custom_ma_ligne', 'a' . str_repeat('b', 99)];
        // « 0 » / « 1 » : `lines` envoyé comme liste JSON. « Energy_T1 » : ne tombe
        // QUE si le prédicat porte BINARY. « energy_t1\n » : ne tombe QUE avec \z.
        $malformed = ['Energy_T1', 'Energy T1', '0', '1', '0bad', 'energy-t1', "energy_t1\n"];

        $gridId = $this->seedGrid([...$valid, ...$malformed]);

        $this->runMigration();

        self::assertSame($valid, $this->remainingKeys($gridId));
    }

    /**
     * Le prédicat SQL et KEY_PATTERN doivent trancher à l'identique : une clé
     * survivant à la purge est une clé que l'API accepte, et réciproquement.
     */
    public function testSurvivingKeysAreExactlyThoseMatchingKeyPattern(): void
    {
        $keys = [
            'energy_t1', 'custom_ma_ligne', 'spot_coefficient', 'a', 'a' . str_repeat('b', 99),
            'Energy_T1', 'ENERGY_T1', 'Energy T1', '0bad', '_energy', 'energy-t1', 'énergie',
            "energy_t1\n", "energy_t1\t", 'energy t1', '0', '1',
        ];

        $gridId = $this->seedGrid($keys);

        $this->runMigration();

        $expected = array_values(array_filter(
            $keys,
            static fn (string $k): bool => preg_match(TariffLineCatalog::KEY_PATTERN, $k) === 1,
        ));

        self::assertSame($expected, $this->remainingKeys($gridId));
    }

    /** Rejouée sur une base déjà purgée, la migration ne touche plus rien. */
    public function testPurgeIsIdempotent(): void
    {
        $gridId = $this->seedGrid(['energy_t1', 'Energy T1']);

        $this->runMigration();
        $afterFirst = $this->remainingKeys($gridId);

        $this->runMigration();

        self::assertSame(['energy_t1'], $afterFirst);
        self::assertSame($afterFirst, $this->remainingKeys($gridId));
    }

    /** Une grille saine n'est pas amputée au passage. */
    public function testPurgeLeavesHealthyGridUntouched(): void
    {
        $keys   = ['energy_t1', 'energy_t2', 'subscription', 'custom_ma_ligne'];
        $gridId = $this->seedGrid($keys);

        $this->runMigration();

        self::assertSame($keys, $this->remainingKeys($gridId));
    }
}
