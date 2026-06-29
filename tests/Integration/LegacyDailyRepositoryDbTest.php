<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\LegacyDailyRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration de getMonthlyDeltasForMonth (interpolation à minuit) contre
 * une vraie base MySQL/MariaDB.
 *
 * S'AUTO-SKIPPE quand aucune base n'est joignable (CI, dev sans BDD) : le seed
 * est isolé dans une transaction annulée en fin de test (jamais commité), et on
 * refuse toute base dont le nom ne contient pas « test » (garde anti-destruction).
 *
 * Les relevés encadrent minuit (23:00 / 01:00) pour vérifier que les bornes du
 * mois sont bien interpolées à 00:00 le 1er du mois (et non clampées au relevé).
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

        // Relevés encadrant minuit (23:00 la veille / 01:00 le lendemain) → l'index
        // à 00:00 le 1er du mois est la moyenne des deux (span 2 h, minuit à mi-chemin).
        $rows = [
            // Bascule décembre → janvier
            ['2025-11-30 23:00:00', 50.0],
            ['2025-12-01 01:00:00', 52.0],   // 1er déc. 00:00 → 51
            ['2025-12-31 23:00:00', 80.0],
            ['2026-01-01 01:00:00', 82.0],   // 1er janv. 00:00 → 81
            // Mois complet (mai), bornes interpolées des deux côtés
            ['2026-04-30 23:00:00', 100.0],
            ['2026-05-01 01:00:00', 102.0],  // 1er mai 00:00 → 101
            ['2026-05-31 23:00:00', 200.0],
            ['2026-06-01 01:00:00', 202.0],  // 1er juin 00:00 → 201
            // Mois en cours (juillet) : aucun relevé après → fin = dernier relevé
            ['2026-06-30 23:00:00', 300.0],
            ['2026-07-01 01:00:00', 302.0],  // 1er juil. 00:00 → 301
            ['2026-07-10 12:00:00', 350.0],  // dernier relevé disponible
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

    public function testCompletedMonthInterpolatesBothBoundariesToMidnight(): void
    {
        // Mai : bornes interpolées à minuit (1er mai → 101, 1er juin → 201).
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 5);

        self::assertSame('2026-05-01 00:00:00', $r['from']);
        self::assertSame('2026-06-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(100.0, $r['prelev_jour'], 0.001); // 201 - 101
    }

    public function testDecemberRollsOverToNextJanuary(): void
    {
        // Décembre : borne de fin = minuit le 1er janvier de l'année suivante.
        $r = $this->repo()->getMonthlyDeltasForMonth(2025, 12);

        self::assertSame('2025-12-01 00:00:00', $r['from']);
        self::assertSame('2026-01-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(30.0, $r['prelev_jour'], 0.001); // 81 - 51
    }

    public function testOngoingMonthEndsAtLatestReading(): void
    {
        // Juillet : aucun relevé après → début interpolé à minuit (301), fin = dernier relevé (350).
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 7);

        self::assertSame('2026-07-01 00:00:00', $r['from']);
        self::assertSame('2026-07-10 12:00:00', $r['to']);
        self::assertEqualsWithDelta(49.0, $r['prelev_jour'], 0.001); // 350 - 301
    }

    public function testEmptyMonthReturnsEmpty(): void
    {
        // Mars : aucun relevé dans le mois.
        self::assertSame([], $this->repo()->getMonthlyDeltasForMonth(2026, 3));
    }
}
