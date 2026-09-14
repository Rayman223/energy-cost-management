<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Meter;
use App\Infrastructure\MigrationRunner;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test d'intégration de la migration 2026-09-14_meters_opened_on.sql (#81).
 * S'auto-skippe sans base de test joignable.
 *
 * L'IDEMPOTENCE d'abord : la migration est laissée HORS du seed de schema.sql,
 * donc `migrate.php` l'applique sur toutes les bases existantes, et la garde CI C1
 * la rejoue pour vérifier que l'application converge. Un `IF NOT EXISTS` manquant
 * ne se verrait qu'à ce moment-là.
 *
 * Le DÉFAUT ensuite, et c'est un choix de fond : les compteurs déjà déclarés
 * restent à `NULL`, c'est-à-dire « en service depuis toujours ». Un backfill
 * depuis le premier relevé aurait inventé une date de pose à partir d'une absence
 * de donnée — exactement la déduction que cette colonne existe pour éviter. Le
 * parc existant garde donc le comportement d'avant tant que rien n'est saisi.
 */
final class MetersOpenedOnMigrationDbTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/sql/migrations/2026-09-14_meters_opened_on.sql';

    protected function clean(): void
    {
        foreach (['meter_readings', 'meter_registers', 'utility_readings', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testMigrationCanBeReplayedWithoutError(): void
    {
        // Le schéma de test porte déjà la colonne (schema.sql) : rejouer doit être
        // un no-op silencieux, deux fois de suite.
        $this->applyMigration();
        $this->applyMigration();

        $columns = $this->pdo()->query("SHOW COLUMNS FROM meters LIKE 'opened_on'")->fetchAll();

        self::assertCount(1, $columns, 'La colonne doit exister exactement une fois.');
    }

    /**
     * Une base migrée doit être structurellement identique à une base fraîchement
     * importée : même type, même nullabilité, même défaut. Sans quoi la garde de
     * convergence ne prouve plus rien.
     */
    public function testTheColumnIsANullableDateWithoutDefault(): void
    {
        $column = $this->pdo()->query("SHOW COLUMNS FROM meters LIKE 'opened_on'")->fetch();

        self::assertIsArray($column);
        self::assertSame('date', $column['Type']);
        self::assertSame('YES', $column['Null'], 'NULL = en service depuis toujours : la colonne doit l’accepter.');
        self::assertNull($column['Default'], 'Aucun défaut : une date de pose ne s’invente pas.');
    }

    /** Un compteur déclaré avant la migration reste « depuis toujours ». */
    public function testExistingMetersKeepNoCommissioningDate(): void
    {
        $userId = (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'opened-default', 'test', 'Testeur')->id;

        // INSERT ne mentionnant pas la colonne : l'état exact d'une ligne
        // antérieure à la migration.
        $this->pdo()
            ->prepare("INSERT INTO meters (user_id, energy_type, label) VALUES (:uid, 'electricity', 'Historique')")
            ->execute(['uid' => $userId]);

        $meters = (new MeterRepository($this->pdo(), $userId))->listAll();

        self::assertCount(1, $meters);
        self::assertNull($meters[0]->openedOn, 'Un compteur hérité doit rester en service depuis toujours.');
        self::assertTrue($meters[0]->isInServiceOn(new DateTimeImmutable('1999-01-01', new DateTimeZone('UTC'))));
    }

    /** Aller-retour complet : la date saisie revient telle quelle du repository. */
    public function testTheCommissioningDateSurvivesARoundTrip(): void
    {
        $userId = (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'opened-roundtrip', 'test', 'Testeur')->id;

        $repo = new MeterRepository($this->pdo(), $userId);
        $id   = $repo->insert(new Meter(
            id:         0,
            energyType: 'electricity',
            label:      'Atelier',
            closedOn:   new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC')),
            openedOn:   new DateTimeImmutable('2026-06-15', new DateTimeZone('UTC')),
        ));

        $meter = $repo->find($id);

        self::assertNotNull($meter);
        self::assertNotNull($meter->openedOn);
        self::assertSame('2026-06-15', $meter->openedOn->format('Y-m-d'));
        self::assertNotNull($meter->closedOn);
        self::assertSame('2026-07-01', $meter->closedOn->format('Y-m-d'));

        // Et la mise à jour la vide aussi bien qu'elle la pose : la date est
        // réversible, comme la fermeture.
        $repo->update($id, new Meter(id: $id, energyType: 'electricity', label: 'Atelier'));

        $reopened = $repo->find($id);
        self::assertNotNull($reopened);
        self::assertNull($reopened->openedOn);
        self::assertNull($reopened->closedOn);
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
