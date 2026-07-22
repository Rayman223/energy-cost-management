<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Import en masse de bout en bout contre une vraie base MariaDB/MySQL.
 *
 * S'AUTO-SKIPPE sans base joignable. Garde anti-destruction : refuse toute base
 * dont le nom ne contient pas « test ».
 */
final class BulkImportDbTest extends TestCase
{
    private ?PDO $pdo = null;
    private BulkImportService $service;

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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Base injoignable — test BDD ignoré : ' . $e->getMessage());
        }

        $this->clean();
        $this->service = new BulkImportService();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    public function testGasImportThenReimportIsIdempotent(): void
    {
        $users  = new UserRepository($this->pdo());
        $userId = $users->create('https://iss.example', 'gas-owner', 'example', 'Gas')->id;
        $sink   = new UtilityReadingRepository($this->pdo(), $userId, 'gas');

        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' =>'100.5'],
            3 => ['timestamp' => '2026-01-02T08:00:00Z', 'value' =>'101.0'],
            4 => ['timestamp' => 'oops',                'value' =>'5'], // erreur rapportée
        ];

        $first = $this->service->importUtility($rows, ImportMapping::preset('gas'), $sink);
        self::assertSame(2, $first->imported());
        self::assertSame(1, $first->errors());
        self::assertSame(2, $this->countRows('utility_readings', $userId));

        // Réimport → aucun doublon en base.
        $second = $this->service->importUtility($rows, ImportMapping::preset('gas'), $sink);
        self::assertSame(0, $second->imported());
        self::assertSame(2, $second->duplicates());
        self::assertSame(2, $this->countRows('utility_readings', $userId));
    }

    public function testElectricityImportByRegisters(): void
    {
        $users  = new UserRepository($this->pdo());
        $userId = $users->create('https://iss.example', 'elec-owner', 'example', 'Elec')->id;
        $sink   = new ElectricityReadingRepository($this->pdo(), $userId);

        $rows = [
            2 => ['timestamp' => '2026-01-01T00:00:00Z', 'import_t1' => '1000', 'import_t2' => '2000'],
            3 => ['timestamp' => '2026-01-02T00:00:00Z', 'import_t1' => '1010', 'import_t2' => '2020'],
        ];

        $report = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), $sink);
        self::assertSame(4, $report->imported()); // 2 lignes × 2 registres
        self::assertSame(0, $report->errors());

        // Réimport → tout en doublon.
        $again = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), $sink);
        self::assertSame(0, $again->imported());
        self::assertSame(4, $again->duplicates());
    }

    private function countRows(string $table, int $userId): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function clean(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM meter_readings');
        $pdo->exec('DELETE FROM meter_registers');
        $pdo->exec('DELETE FROM meters');
        $pdo->exec('DELETE FROM utility_readings');
        $pdo->exec('DELETE FROM user_profiles');
        $pdo->exec('DELETE FROM users');
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
