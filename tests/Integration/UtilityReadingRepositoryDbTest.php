<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du repository unifié gaz/eau (utility_readings), scopé
 * par utilisateur. S'auto-skippe sans base ; refuse une base non-test.
 */
final class UtilityReadingRepositoryDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'util-test', 'test', 'Util Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['utility_readings', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testSaveLatestAndDeltas(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 112.5);

        $latest = $gas->getLatest();
        self::assertNotNull($latest);
        self::assertSame(112.5, (float) $latest['counter_m3']);

        $all = $gas->getAllReadings();
        self::assertCount(2, $all);
        self::assertSame(12.5, $all[0]['delta_m3']);  // plus récent d'abord
        self::assertNull($all[1]['delta_m3']);

        $lastTwo = $gas->getLastTwoReadings();
        self::assertSame(100.0, (float) $lastTwo['from']['counter_m3']);
        self::assertSame(112.5, (float) $lastTwo['to']['counter_m3']);
    }

    public function testGasAndWaterAreIsolatedFlows(): void
    {
        $gas   = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);

        self::assertCount(1, $gas->getAllReadings());
        self::assertCount(1, $water->getAllReadings());
        self::assertSame(100.0, (float) ($gas->getLatest()['counter_m3'] ?? 0));
        self::assertSame(40.0, (float) ($water->getLatest()['counter_m3'] ?? 0));
    }

    public function testDataIsScopedPerUser(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other', 'test', 'Autre')->id;

        $mine  = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $their = new UtilityReadingRepository($this->pdo(), $otherId, 'gas');

        $mine->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);

        self::assertCount(1, $mine->getAllReadings());
        self::assertSame([], $their->getAllReadings());
        self::assertNull($their->getLatest());

        // Même horodatage autorisé pour deux utilisateurs distincts (UNIQUE composite).
        $their->save(new DateTimeImmutable('2026-06-01 10:00:00'), 55.0);
        self::assertCount(1, $their->getAllReadings());
    }

    public function testReadingBeforeAndAfterAroundBackdatedDate(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        // Date encadrée par les deux relevés existants.
        $ts = new DateTimeImmutable('2026-06-15 10:00:00');
        $before = $gas->getReadingBefore($ts);
        $after  = $gas->getReadingAfter($ts);
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(100.0, (float) $before['counter_m3']);
        self::assertSame(150.0, (float) $after['counter_m3']);

        // Horodatage exact : bornes inclusives → renvoie ce même relevé des deux côtés.
        $exact = new DateTimeImmutable('2026-06-01 10:00:00');
        self::assertSame('2026-06-01 10:00:00', $gas->getReadingBefore($exact)['reading_at']);
        self::assertSame('2026-06-01 10:00:00', $gas->getReadingAfter($exact)['reading_at']);

        // Hors plage : pas de voisin de ce côté.
        self::assertNull($gas->getReadingBefore(new DateTimeImmutable('2026-05-01 10:00:00')));
        self::assertNull($gas->getReadingAfter(new DateTimeImmutable('2026-08-01 10:00:00')));
    }

    public function testInterpolationWindow(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-05-20 10:00:00'), 90.0);   // avant le mois
        $gas->save(new DateTimeImmutable('2026-06-10 10:00:00'), 100.0);  // dans le mois
        $gas->save(new DateTimeImmutable('2026-07-05 10:00:00'), 110.0);  // après le mois

        $window = $gas->getReadingsForInterpolation(2026, 6);

        self::assertCount(3, $window);
        self::assertSame('2026-05-20 10:00:00', $window[0]['reading_at']);
        self::assertSame('2026-07-05 10:00:00', $window[2]['reading_at']);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
