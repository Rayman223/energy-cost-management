<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserRepository;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Orchestration transactionnelle {@see ImportRunner::run()} contre une vraie
 * base : plafond stop-and-report + commit/rollback (dry-run).
 *
 * S'AUTO-SKIPPE sans base joignable. Garde anti-destruction : refuse toute base
 * dont le nom ne contient pas « test ».
 */
final class ImportRunnerDbTest extends TestCase
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

        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    public function testCapStopsAndReportsWithoutLosingImportedRows(): void
    {
        $userId = (new UserRepository($this->pdo()))->create('https://iss.example', 'cap-owner', 'example', 'Cap')->id;

        $rows = [];
        for ($day = 1; $day <= 5; $day++) {
            $rows[$day + 1] = ['timestamp' => sprintf('2026-01-%02d 08:00:00', $day), 'value' =>(string) (100 + $day)];
        }

        // Plafond volontairement bas (2 lignes) → tronqué mais N premières gardées.
        $runner = new ImportRunner(new BulkImportService(), 8_388_608, 2);
        $report = $runner->run($this->pdo(), ImportMapping::preset('gas'), $rows, $userId, 'gas', false);

        self::assertTrue($report->truncated());
        self::assertSame(2, $report->imported());
        self::assertSame(2, $this->countRows('utility_readings', $userId), 'les 2 lignes sont bien committées');
    }

    public function testDryRunRollsBackWhileReportingCounts(): void
    {
        $userId = (new UserRepository($this->pdo()))->create('https://iss.example', 'dry-owner', 'example', 'Dry')->id;

        $rows = [
            2 => ['timestamp' => '2026-02-01 08:00:00', 'value' =>'10'],
            3 => ['timestamp' => '2026-02-02 08:00:00', 'value' =>'11'],
        ];

        $report = (new ImportRunner())->run($this->pdo(), ImportMapping::preset('gas'), $rows, $userId, 'gas', true);

        self::assertSame(2, $report->imported());
        self::assertFalse($report->truncated());
        self::assertSame(0, $this->countRows('utility_readings', $userId), 'dry-run : rien en base');
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
