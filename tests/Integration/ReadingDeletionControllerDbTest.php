<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controller\ReadingDeletionController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du contrôleur de suppression contre de vrais repositories :
 * couvre le chemin complet (réponse JSON, parsing reading_at, validation d'id) de
 * la suppression d'un relevé, ligne par ligne ou en masse (issue #162).
 * S'auto-skippe sans base ; refuse une base non-test.
 */
final class ReadingDeletionControllerDbTest extends TestCase
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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Base injoignable — test BDD ignoré : ' . $e->getMessage());
        }

        $this->clean();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'del-ctrl-test', 'test', 'Del Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['utility_readings', 'meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function controller(): ReadingDeletionController
    {
        return new ReadingDeletionController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): Request
    {
        return new Request('POST', ['action' => $action], $body);
    }

    public function testDeleteWaterReadingByIdReturnsOk(): void
    {
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);
        $id = $water->getAllReadings()[0]['id'];

        $res = $this->controller()->waterReading($this->post('delete_water_reading', ['id' => $id]));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'deleted' => 1], $res->data);
        self::assertSame([], $water->getAllReadings());
    }

    public function testDeleteWaterReadingWithInvalidIdThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->waterReading($this->post('delete_water_reading', ['id' => 'abc']));
    }

    public function testDeleteWaterReadingUnknownIdReportsZero(): void
    {
        $res = $this->controller()->waterReading($this->post('delete_water_reading', ['id' => 999999]));

        self::assertSame(['ok' => true, 'deleted' => 0], $res->data);
    }

    public function testDeleteWaterAllClearsFluid(): void
    {
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);
        $water->save(new DateTimeImmutable('2026-07-01 10:00:00'), 45.0);

        $res = $this->controller()->waterAll($this->post('delete_water_all', []));

        self::assertSame(['ok' => true, 'deleted' => 2], $res->data);
        self::assertSame([], $water->getAllReadings());
    }

    public function testDeleteElectricityReadingByTimestamp(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0, 'import_t2' => 200.0]);

        $res = $this->controller()->electricityReading($this->post('delete_electricity_reading', ['reading_at' => '2026-06-01 00:00:00']));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'deleted' => 2], $res->data);
        self::assertSame([], (new ElectricityReadingRepository($this->pdo(), $this->userId))->getHistory());
    }

    public function testDeleteElectricityReadingWithBadTimestampThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->electricityReading($this->post('delete_electricity_reading', ['reading_at' => 'not-a-date']));
    }

    /**
     * Régression : sans reading_at, le cast `(string) null` faisait construire un
     * DateTimeImmutable('') — soit « maintenant » — et la suppression partait
     * quand même, en répondant 200 {"deleted":0}. On attend désormais une 422,
     * et surtout aucun relevé touché.
     */
    public function testDeleteElectricityReadingWithoutTimestampThrows(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);

        try {
            $this->controller()->electricityReading($this->post('delete_electricity_reading', []));
            self::fail('Une date requise absente doit lever une ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('Invalid reading_at date format', $e->getMessage());
        }

        self::assertNotSame([], (new ElectricityReadingRepository($this->pdo(), $this->userId))->getHistory());
    }

    public function testDeleteElectricityMeter(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);

        $res = $this->controller()->electricityMeter($this->post('delete_electricity_meter', []));

        self::assertSame(['ok' => true, 'deleted' => 1], $res->data);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meters')->fetchColumn());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
