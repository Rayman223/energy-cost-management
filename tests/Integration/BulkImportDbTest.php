<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;

/**
 * Import en masse de bout en bout contre une vraie base MariaDB/MySQL.
 *
 * S'AUTO-SKIPPE sans base de test joignable.
 */
final class BulkImportDbTest extends DatabaseTestCase
{
    private BulkImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BulkImportService();
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

    protected function clean(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM meter_readings');
        $pdo->exec('DELETE FROM meter_registers');
        $pdo->exec('DELETE FROM meters');
        $pdo->exec('DELETE FROM utility_readings');
        $pdo->exec('DELETE FROM user_profiles');
        $pdo->exec('DELETE FROM users');
    }
}
