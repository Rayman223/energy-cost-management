<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\LegacyDailyRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration des requêtes sargables de getMonthlyDeltasForMonth contre
 * une vraie base MySQL/MariaDB.
 *
 * S'AUTO-SKIPPE quand aucune base n'est joignable (CI, dev sans BDD) : le seed
 * est isolé dans une transaction annulée en fin de test (jamais commité), et on
 * refuse toute base dont le nom ne contient pas « test » (garde anti-destruction).
 */
final class LegacyDailyRepositoryDbTest extends TestCase
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

        // Seed isolé dans une transaction (annulée en tearDown) : ne touche
        // jamais durablement la base, même si elle contient des données.
        $this->pdo->beginTransaction();
        $this->pdo->exec('DELETE FROM Data_Dries');
        $this->pdo->exec('DELETE FROM Data_Solaire');
        $this->pdo->exec('DELETE FROM Data_Brusol');

        $rows = [
            ['2025-12-10 06:00:00', 110.0],
            ['2025-12-20 06:00:00', 120.0],
            ['2026-01-05 06:00:00', 130.0],
            ['2026-05-15 06:00:00', 140.0],
            ['2026-06-01 06:00:00', 150.0],
        ];
        $ins = $this->pdo->prepare(
            'INSERT INTO Data_Dries (timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit) VALUES (?,?,?,?,?)'
        );
        foreach ($rows as [$ts, $v]) {
            $ins->execute([$ts, $v, $v, $v, $v]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function repo(): LegacyDailyRepository
    {
        \assert($this->pdo !== null);

        return new LegacyDailyRepository($this->pdo);
    }

    public function testMonthBoundedByNextMonthFirstReading(): void
    {
        // Mai : premier relevé 15-mai, borne = premier relevé de juin (1er-juin).
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 5);

        self::assertSame('2026-05-15 06:00:00', $r['from']);
        self::assertSame('2026-06-01 06:00:00', $r['to']);
        self::assertSame(10.0, $r['prelev_jour']); // 150 - 140
    }

    public function testDecemberRollsOverToNextJanuary(): void
    {
        // Décembre : borne = premier relevé de janvier de l'année suivante.
        $r = $this->repo()->getMonthlyDeltasForMonth(2025, 12);

        self::assertSame('2025-12-10 06:00:00', $r['from']);
        self::assertSame('2026-01-05 06:00:00', $r['to']);
        self::assertSame(20.0, $r['prelev_jour']); // 130 - 110
    }

    public function testMonthWithoutNextMonthFallsBackToLastReading(): void
    {
        // Janvier : pas de relevé en février -> repli sur le dernier relevé de janvier.
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 1);

        self::assertSame('2026-01-05 06:00:00', $r['from']);
        self::assertSame('2026-01-05 06:00:00', $r['to']);
        self::assertSame(0.0, $r['prelev_jour']);
    }

    public function testEmptyMonthReturnsEmpty(): void
    {
        self::assertSame([], $this->repo()->getMonthlyDeltasForMonth(2026, 7));
    }
}
