<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MeterTopology;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration de l'interpolation à minuit sur le modèle à registres
 * (port des scénarios de l'ancien test Data_Dries). S'auto-skippe sans base ;
 * refuse une base dont le nom ne contient pas « test ».
 */
final class ElectricityReadingRepositoryDbTest extends TestCase
{
    private ?PDO $pdo = null;

    private int $userId = 0;

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

        $users = new UserRepository($this->pdo());
        $this->userId = $users->create('https://iss.test', 'elec-test', 'test', 'Elec Tester')->id;

        // Seed : relevés encadrant minuit (23:00 la veille / 01:00 le lendemain)
        // → l'index à 00:00 le 1er du mois est la moyenne des deux.
        $topology = new MeterTopology($this->pdo());
        $meterId = $topology->ensureElectricityMeter($this->userId);
        $registers = $topology->ensureRegisters($meterId);

        $rows = [
            ['2025-11-30 23:00:00', 50.0],
            ['2025-12-01 01:00:00', 52.0],   // 1er déc. 00:00 → 51
            ['2025-12-31 23:00:00', 80.0],
            ['2026-01-01 01:00:00', 82.0],   // 1er janv. 00:00 → 81
            ['2026-04-30 23:00:00', 100.0],
            ['2026-05-01 01:00:00', 102.0],  // 1er mai 00:00 → 101
            ['2026-05-31 23:00:00', 200.0],
            ['2026-06-01 01:00:00', 202.0],  // 1er juin 00:00 → 201
            ['2026-06-30 23:00:00', 300.0],
            ['2026-07-01 01:00:00', 302.0],  // 1er juil. 00:00 → 301
            ['2026-07-10 12:00:00', 350.0],  // dernier relevé disponible
        ];

        $ins = $this->pdo()->prepare(
            'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)'
        );
        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2'] as $key) {
            foreach ($rows as [$ts, $v]) {
                $ins->execute(['rid' => $registers[$key], 'at' => $ts, 'val' => $v]);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function repo(): ElectricityReadingRepository
    {
        return new ElectricityReadingRepository($this->pdo(), $this->userId);
    }

    public function testCompletedMonthInterpolatesBothBoundariesToMidnight(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 5);

        self::assertSame('2026-05-01 00:00:00', $r['from']);
        self::assertSame('2026-06-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(100.0, $r['prelev_jour'], 0.001); // 201 - 101
    }

    public function testDecemberRollsOverToNextJanuary(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2025, 12);

        self::assertSame('2025-12-01 00:00:00', $r['from']);
        self::assertSame('2026-01-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(30.0, $r['prelev_jour'], 0.001); // 81 - 51
    }

    public function testOngoingMonthEndsAtLatestReading(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 7);

        self::assertSame('2026-07-01 00:00:00', $r['from']);
        self::assertSame('2026-07-10 12:00:00', $r['to']);
        self::assertEqualsWithDelta(49.0, $r['prelev_jour'], 0.001); // 350 - 301
    }

    public function testEmptyMonthReturnsEmpty(): void
    {
        self::assertSame([], $this->repo()->getMonthlyDeltasForMonth(2026, 3));
    }

    public function testDataIsScopedPerUser(): void
    {
        // Un second utilisateur, sans compteur : aucune donnée visible.
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other', 'test', 'Autre')->id;
        $other = new ElectricityReadingRepository($this->pdo(), $otherId);

        self::assertSame([], $other->getMonthlyDeltasForMonth(2026, 5));
        self::assertNull($other->getTodayIndexValues()['dries']);
    }

    public function testInsertIndexesIsIdempotent(): void
    {
        $repo = $this->repo();
        $ts = new \DateTimeImmutable('2026-07-11 08:00:00');

        $repo->insertIndexes($ts, ['import_t1' => 360.0, 'import_t2' => 360.0]);
        $repo->insertIndexes($ts, ['import_t1' => 360.0, 'import_t2' => 360.0]); // re-run : ignoré

        $stmt = $this->pdo()->query(
            "SELECT COUNT(*) FROM meter_readings WHERE reading_at = '2026-07-11 08:00:00'"
        );
        self::assertNotFalse($stmt);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
