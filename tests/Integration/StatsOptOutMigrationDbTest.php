<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\UserProfile;
use App\Infrastructure\MigrationRunner;
use App\Repository\UserRepository;

/**
 * Test d'intégration de la migration 2026-08-28_user_stats_opt_out.sql (#8).
 * S'auto-skippe sans base de test joignable.
 *
 * Deux propriétés y sont vérifiées, et l'une n'est pas cosmétique.
 *
 * L'IDEMPOTENCE d'abord : cette migration est laissée HORS du seed de schema.sql,
 * donc `migrate.php` l'applique sur toutes les bases existantes, et la garde CI C1
 * la rejoue une seconde fois pour vérifier que l'application converge. Une garde
 * `IF NOT EXISTS` manquante ne se verrait qu'à ce moment-là.
 *
 * Le DÉFAUT ensuite : les profils déjà en base doivent hériter de « contribue »,
 * c'est-à-dire du même traitement que les nouveaux comptes. La valeur par défaut
 * d'une colonne ajoutée est un choix de fond ici — c'est elle qui décide si les
 * statistiques publiques démarrent avec un corpus ou vides.
 */
final class StatsOptOutMigrationDbTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/sql/migrations/2026-08-28_user_stats_opt_out.sql';

    protected function clean(): void
    {
        foreach (['user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testMigrationCanBeReplayedWithoutError(): void
    {
        // Le schéma de test porte déjà la colonne (schema.sql) : rejouer la
        // migration doit donc être un no-op silencieux, deux fois de suite.
        $this->applyMigration();
        $this->applyMigration();

        $columns = $this->pdo()
            ->query("SHOW COLUMNS FROM user_profiles LIKE 'stats_opt_out'")
            ->fetchAll();

        self::assertCount(1, $columns, 'La colonne doit exister exactement une fois.');
    }

    public function testExistingProfilesContributeByDefault(): void
    {
        $users  = new UserRepository($this->pdo());
        $userId = $users->create('https://iss.test', 'optout-default', 'test', 'Testeur')->id;

        // `create()` pose la ligne user_profiles avec le seul user_id, laissant
        // toutes les autres colonnes à leur DEFAULT — c'est exactement l'état
        // d'une ligne antérieure à la migration, qui ne mentionnait pas encore
        // stats_opt_out. Inutile de le simuler par un INSERT : ce serait d'ailleurs
        // impossible, user_id étant la clé primaire de la table.
        $this->pdo()->prepare('UPDATE user_profiles SET country = :c WHERE user_id = :u')
            ->execute(['c' => 'BE', 'u' => $userId]);

        $profile = $users->getProfile($userId);

        self::assertNotNull($profile);
        self::assertFalse($profile->statsOptOut, 'L\'absence de choix vaut contribution, pas retrait.');
    }

    public function testOptOutSurvivesAProfileRoundTrip(): void
    {
        $users  = new UserRepository($this->pdo());
        $userId = $users->create('https://iss.test', 'optout-roundtrip', 'test', 'Testeur')->id;

        $users->updateProfile($userId, new UserProfile(
            country: 'BE',
            timezone: 'UTC',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
            statsOptOut: true,
        ));

        self::assertTrue($users->getProfile($userId)?->statsOptOut);

        // Et la réinscription doit être aussi simple que le retrait : une
        // préférence qu'on ne peut pas défaire n'en est pas une.
        $users->updateProfile($userId, new UserProfile(
            country: 'BE',
            timezone: 'UTC',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
            statsOptOut: false,
        ));

        self::assertFalse($users->getProfile($userId)?->statsOptOut);
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
