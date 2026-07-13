<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controller\MeterEntryController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du contrôleur de saisie manuelle contre de vrais
 * repositories : couvre la détection de doublon (comparaison d'horodatage
 * réelle + contrainte UNIQUE) et la validation encadrante des relevés
 * antidatés, que les tests unitaires à doublure ne peuvent exercer.
 * S'auto-skippe sans base ; refuse une base non-test.
 */
final class MeterEntryControllerDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'ctrl-test', 'test', 'Ctrl Tester')->id;
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

    private function controller(): MeterEntryController
    {
        return new MeterEntryController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
        );
    }

    /** @param array<string, mixed> $body */
    private function gasPost(array $body): Request
    {
        return new Request('POST', ['action' => 'gas_entry'], $body);
    }

    public function testRejectsExactDuplicateThroughRealRepository(): void
    {
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'))
            ->save(new DateTimeImmutable('2026-06-15 10:00:00'), 100.0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists at this date');
        $this->controller()->gas($this->gasPost(['counter_m3' => 100.0, 'reading_at' => '2026-06-15 10:00:00']));
    }

    public function testAcceptsBackdatedReadingBetweenTwoExisting(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        $res = $this->controller()->gas($this->gasPost(['counter_m3' => 120.0, 'reading_at' => '2026-06-15 10:00:00']));

        self::assertSame(200, $res->status);
        self::assertCount(3, $gas->getAllReadings());
    }

    public function testRejectsBackdatedValueAboveNextThroughRealRepository(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≤ next reading');
        $this->controller()->gas($this->gasPost(['counter_m3' => 999.0, 'reading_at' => '2026-06-15 10:00:00']));
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
