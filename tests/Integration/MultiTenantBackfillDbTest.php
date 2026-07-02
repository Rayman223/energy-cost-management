<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MultiTenantBackfill;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du backfill multi-tenant contre une vraie base MySQL/MariaDB.
 * S'auto-skippe sans base joignable ; refuse une base dont le nom ne contient pas « test ».
 */
final class MultiTenantBackfillDbTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $configPath = __DIR__ . '/../../app/config/config.php';
        if (!is_file($configPath)) {
            self::markTestSkipped('app/config/config.php absent — test BDD ignoré.');
        }

        /** @var array{database: array<string, mixed>} $config */
        $config = require $configPath;
        $db = $config['database'];

        if (!str_contains((string) $db['name'], 'test')) {
            self::markTestSkipped('Base "' . $db['name'] . '" non-test — seed destructif refusé.');
        }

        try {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'),
                (string) $db['user'],
                (string) $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Base injoignable — test BDD ignoré : ' . $e->getMessage());
        }

        // Les tables legacy ne font plus partie de schema.sql : le backfill ne
        // s'exécute que sur une installation existante qui les possède encore.
        // On les recrée ici pour reproduire ce scénario.
        $this->createLegacyTables();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
            $this->pdo()->exec('DROP TABLE IF EXISTS Data_Dries, Data_Solaire, Data_gaz, Data_eau');
        }
    }

    private function createLegacyTables(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS Data_Dries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                timestamp DATETIME NOT NULL,
                Prelev_jour DECIMAL(12,3) NOT NULL, Prelev_nuit DECIMAL(12,3) NOT NULL,
                Injec_jour DECIMAL(12,3) NOT NULL, Injec_nuit DECIMAL(12,3) NOT NULL,
                UNIQUE KEY uq_data_dries_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS Data_Solaire (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                timestamp DATETIME NOT NULL,
                production DECIMAL(12,3) NOT NULL,
                UNIQUE KEY uq_data_solaire_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (['Data_gaz', 'Data_eau'] as $table) {
            $this->pdo()->exec(
                "CREATE TABLE IF NOT EXISTS {$table} (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reading_at DATETIME NOT NULL,
                    counter_m3 DECIMAL(12,3) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_{$table}_reading (reading_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
    }

    private function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'meters', 'utility_readings',
            'user_profiles', 'users',
            'Data_Dries', 'Data_Solaire', 'Data_gaz', 'Data_eau',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testBackfillAttachesLegacyDataToFirstUser(): void
    {
        $users = new UserRepository($this->pdo());
        $me = $users->create('https://accounts.google.com', 'my-sub', 'google', 'Moi');

        $this->seedLegacy();

        $counts = (new MultiTenantBackfill($this->pdo(), $users))->run();

        self::assertSame($me->id, $counts['owner_user_id']);
        self::assertSame(5, $this->countRows('meter_readings'));   // 4 registres Dries + production
        self::assertSame(2, $this->countRows('utility_readings')); // gaz + eau
        self::assertSame($me->id, $this->meterOwnerId());

        // Idempotence : un second passage ne recopie rien.
        $again = (new MultiTenantBackfill($this->pdo(), $users))->run();
        self::assertSame(0, $again['import_t1']);
        self::assertSame(0, $again['gas']);
        self::assertSame(5, $this->countRows('meter_readings'));
        self::assertSame(2, $this->countRows('utility_readings'));
    }

    public function testBackfillRequiresAnExistingUser(): void
    {
        $users = new UserRepository($this->pdo());

        $this->expectException(\RuntimeException::class);

        (new MultiTenantBackfill($this->pdo(), $users))->run();
    }

    private function seedLegacy(): void
    {
        $this->pdo()->exec(
            "INSERT INTO Data_Dries (timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit)
             VALUES ('2026-05-01 10:00:00', 100.000, 50.000, 10.000, 5.000)"
        );
        $this->pdo()->exec("INSERT INTO Data_Solaire (timestamp, production) VALUES ('2026-05-01 10:00:00', 200.000)");
        $this->pdo()->exec("INSERT INTO Data_gaz (reading_at, counter_m3) VALUES ('2026-05-01 10:00:00', 1234.500)");
        $this->pdo()->exec("INSERT INTO Data_eau (reading_at, counter_m3) VALUES ('2026-05-01 10:00:00', 67.800)");
    }

    private function countRows(string $table): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function meterOwnerId(): int
    {
        $stmt = $this->pdo()->query('SELECT user_id FROM meters ORDER BY id LIMIT 1');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
