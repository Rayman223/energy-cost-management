<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MeterTopology;
use App\Infrastructure\MigrationRunner;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;

/**
 * Test d'intégration de la migration 2026-09-06_meters_multi_energy.sql (#55).
 * S'auto-skippe sans base de test joignable.
 *
 * Quatre propriétés, dont aucune n'est cosmétique.
 *
 * L'IDEMPOTENCE : la migration est laissée HORS du seed de schema.sql, donc
 * `migrate.php` l'applique sur toutes les bases existantes et la garde CI la
 * rejoue pour vérifier la convergence. Neuf instructions dont un MODIFY sans
 * `IF NOT EXISTS`, un backfill et un UPDATE : une seule non rejouable casserait
 * la garde, et le DDL MySQL committant implicitement, il n'y aurait pas de
 * rollback pour rattraper.
 *
 * LE BACKFILL : c'est lui qui décide si les relevés gaz/eau déjà en base
 * survivent au passage. Un rattachement tronqué ne se verrait pas — les lectures
 * filtrent encore sur user_id — jusqu'à ce que P3 bascule sur meter_id.
 *
 * L'INVARIANT de dénormalisation : `meter_id` est la source de vérité, mais
 * `user_id` et `energy_type` restent sur la table. Deux sources de vérité qui
 * divergent, c'est le risque assumé de ce choix ; ce test est la contrepartie.
 *
 * L'UNICITÉ : elle passe de (user, énergie, horodatage) à (compteur, horodatage).
 * Elle doit continuer de refuser le doublon sur un même compteur.
 */
final class MetersMultiEnergyMigrationDbTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/sql/migrations/2026-09-06_meters_multi_energy.sql';

    protected function clean(): void
    {
        foreach (['utility_readings', 'meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testMigrationCanBeReplayedWithoutError(): void
    {
        // Le schéma de test porte déjà la cible (schema.sql) : rejouer doit être
        // un no-op silencieux, deux fois de suite.
        $this->applyMigration();
        $this->applyMigration();

        $columns = $this->pdo()->query("SHOW COLUMNS FROM utility_readings LIKE 'meter_id'")->fetchAll();
        self::assertCount(1, $columns, 'La colonne meter_id doit exister exactement une fois.');

        $closed = $this->pdo()->query("SHOW COLUMNS FROM meters LIKE 'closed_on'")->fetchAll();
        self::assertCount(1, $closed, 'La colonne closed_on doit exister exactement une fois.');
    }

    public function testMetersAcceptsTheThreeEnergies(): void
    {
        $userId   = $this->createUser('multi-energy');
        $topology = new MeterTopology($this->pdo());

        $ids = [];
        foreach (MeterTopology::ENERGIES as $energy) {
            $ids[$energy] = $topology->ensureMeter($userId, $energy);
        }

        self::assertCount(3, array_unique($ids), 'Chaque énergie doit avoir son propre compteur.');

        // Et la résolution doit retomber sur le même compteur, sans en créer un
        // second : c'est ce qui garde la migration invisible au parc existant.
        foreach (MeterTopology::ENERGIES as $energy) {
            self::assertSame($ids[$energy], $topology->ensureMeter($userId, $energy));
            self::assertSame($ids[$energy], $topology->findDefaultMeter($userId, $energy));
        }
    }

    public function testBackfillAttachesOrphanReadingsToAFreshMeter(): void
    {
        $userId = $this->createUser('backfill');

        // On rejoue l'état d'avant migration : un relevé sans compteur. La
        // colonne étant NOT NULL depuis, il faut la relâcher le temps du seed —
        // c'est exactement ce que la migration fait en phase « expand ».
        $this->pdo()->exec('ALTER TABLE utility_readings MODIFY COLUMN meter_id BIGINT UNSIGNED NULL');
        $this->pdo()->exec('ALTER TABLE utility_readings DROP FOREIGN KEY fk_utility_readings_meter');

        foreach ([['gas', '2026-01-01 00:00:00', 100.0], ['water', '2026-01-01 00:00:00', 40.0]] as [$energy, $at, $value]) {
            $this->pdo()
                ->prepare('INSERT INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
                           VALUES (:uid, NULL, :etype, :at, :v)')
                ->execute(['uid' => $userId, 'etype' => $energy, 'at' => $at, 'v' => $value]);
        }

        $this->applyMigration();

        $orphans = (int) $this->pdo()->query('SELECT COUNT(*) FROM utility_readings WHERE meter_id IS NULL')->fetchColumn();
        self::assertSame(0, $orphans, 'Aucun relevé ne doit rester orphelin après le backfill.');

        // Un compteur par énergie relevée, pas un par relevé.
        $stmt = $this->pdo()->prepare('SELECT energy_type, COUNT(*) AS n FROM meters WHERE user_id = :uid GROUP BY energy_type');
        $stmt->execute(['uid' => $userId]);
        $byEnergy = [];
        foreach ($stmt->fetchAll() as $row) {
            $byEnergy[(string) $row['energy_type']] = (int) $row['n'];
        }

        self::assertSame(['gas' => 1, 'water' => 1], $byEnergy);
        self::assertSame(0, $this->incoherentRows(), 'Le backfill ne doit pas croiser les compteurs.');
    }

    public function testWritesKeepTheDenormalisedColumnsInSync(): void
    {
        $userId = $this->createUser('invariant');

        $gas = new UtilityReadingRepository($this->pdo(), $userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-02-01 06:00:00'), 120.0);
        $gas->saveIgnore(new DateTimeImmutable('2026-02-02 06:00:00'), 123.5);

        $water = new UtilityReadingRepository($this->pdo(), $userId, 'water');
        $water->save(new DateTimeImmutable('2026-02-01 06:00:00'), 12.0);

        self::assertSame(0, $this->incoherentRows(), 'meter_id doit toujours désigner le compteur du bon utilisateur et du bon fluide.');

        // Le même horodatage sur deux fluides est légitime : c'est précisément ce
        // que l'ancienne clé (user, énergie, horodatage) autorisait déjà, et que
        // la nouvelle ne doit pas casser.
        self::assertSame(3, (int) $this->pdo()->query('SELECT COUNT(*) FROM utility_readings')->fetchColumn());
    }

    public function testUniquenessStillRejectsADuplicateOnTheSameMeter(): void
    {
        $userId = $this->createUser('duplicate');
        $repo   = new UtilityReadingRepository($this->pdo(), $userId, 'gas');

        $at = new DateTimeImmutable('2026-03-01 06:00:00');
        self::assertTrue($repo->saveIgnore($at, 200.0));
        self::assertFalse($repo->saveIgnore($at, 999.0), 'Un doublon doit rester ignoré, pas dupliqué.');

        $stmt = $this->pdo()->prepare('SELECT counter_m3 FROM utility_readings WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(200.0, (float) $rows[0]['counter_m3'], 0.001);
    }

    /** Lignes dont les colonnes dénormalisées contredisent leur compteur. */
    private function incoherentRows(): int
    {
        return (int) $this->pdo()->query(
            'SELECT COUNT(*) FROM utility_readings ur
             JOIN meters m ON m.id = ur.meter_id
             WHERE m.user_id <> ur.user_id OR m.energy_type <> ur.energy_type'
        )->fetchColumn();
    }

    private function createUser(string $subject): int
    {
        return (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'meters-' . $subject, 'test', 'Testeur')->id;
    }

    private function applyMigration(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        self::assertIsString($sql, 'Migration introuvable : ' . self::MIGRATION);

        foreach (MigrationRunner::splitStatements($sql) as $statement) {
            $this->pdo()->exec($statement);
        }
    }
}
