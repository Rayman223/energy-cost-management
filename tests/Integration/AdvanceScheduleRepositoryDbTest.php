<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\AdvanceScheduleRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Barèmes d'acomptes mensuels (#241). S'auto-skippe sans base ;
 * refuse une base non-test.
 */
final class AdvanceScheduleRepositoryDbTest extends TestCase
{
    private ?PDO $pdo = null;

    private int $userId = 0;

    private int $otherUserId = 0;

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

        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'adv-owner', 'test', 'Advance Owner')->id;
        $this->otherUserId = $users->create('https://iss.test', 'adv-other', 'test', 'Other Owner')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['energy_advances', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function testInsertListUpdateAndDelete(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);

        self::assertSame([], $repo->listFor('electricity'));

        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), $this->at('2026-12-31'), 5, 'contrat 2026');
        $rows = $repo->listFor('electricity');

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(120.0, $rows[0]->amountMonthly, 0.0001);
        self::assertSame('2026-01-01', $rows[0]->validFrom->format('Y-m-d'));
        self::assertSame('2026-12-31', $rows[0]->validTo?->format('Y-m-d'));
        self::assertSame(5, $rows[0]->dueDay);
        self::assertSame('contrat 2026', $rows[0]->note);

        $repo->update($rows[0]->id, 'electricity', 135.0, $this->at('2026-01-01'), null, 10, 'révision');
        $rows = $repo->listFor('electricity');

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(135.0, $rows[0]->amountMonthly, 0.0001);
        self::assertNull($rows[0]->validTo);
        self::assertSame(10, $rows[0]->dueDay);

        $repo->delete($rows[0]->id);
        self::assertSame([], $repo->listFor('electricity'));
    }

    public function testListWithoutEnergyTypeReturnsEveryEnergy(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);

        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), null, 1);
        $repo->insert('gas', 80.0, $this->at('2026-01-01'), null, 1);
        $repo->insert('water', 20.0, $this->at('2026-01-01'), null, 1);

        self::assertCount(3, $repo->listFor());
        self::assertCount(1, $repo->listFor('gas'));
    }

    /**
     * Le scope tenant est appliqué à chaque requête : un identifiant deviné ne doit
     * ni lire, ni modifier, ni supprimer le barème d'un autre utilisateur.
     */
    public function testTenantScopeIsolatesEveryOperation(): void
    {
        $mine   = new AdvanceScheduleRepository($this->pdo(), $this->userId);
        $theirs = new AdvanceScheduleRepository($this->pdo(), $this->otherUserId);

        $theirs->insert('electricity', 200.0, $this->at('2026-01-01'), null, 1, 'chez eux');
        $foreignId = $theirs->listFor('electricity')[0]->id;

        self::assertSame([], $mine->listFor('electricity'));

        $mine->update($foreignId, 'electricity', 1.0, $this->at('2026-01-01'), null, 1, 'piraté');
        $mine->delete($foreignId);

        $still = $theirs->listFor('electricity');
        self::assertCount(1, $still);
        self::assertEqualsWithDelta(200.0, $still[0]->amountMonthly, 0.0001);
        self::assertSame('chez eux', $still[0]->note);
    }

    /**
     * `owns()` distingue « barème introuvable » de « réenregistré à l'identique » :
     * sans `MYSQL_ATTR_FOUND_ROWS`, un UPDATE sans changement rapporte zéro ligne
     * affectée, si bien que `rowCount()` ne peut pas trancher.
     */
    public function testOwnsDistinguishesMissingFromForeignAndOwnRows(): void
    {
        $mine   = new AdvanceScheduleRepository($this->pdo(), $this->userId);
        $theirs = new AdvanceScheduleRepository($this->pdo(), $this->otherUserId);

        $mine->insert('electricity', 120.0, $this->at('2026-01-01'), null, 1);
        $mineId = $mine->listFor('electricity')[0]->id;

        $theirs->insert('electricity', 200.0, $this->at('2026-01-01'), null, 1);
        $foreignId = $theirs->listFor('electricity')[0]->id;

        self::assertTrue($mine->owns($mineId));
        self::assertFalse($mine->owns($foreignId));
        self::assertFalse($mine->owns(999999));

        // Réenregistrement à l'identique : aucune valeur ne change, la ligne existe
        // pourtant toujours — c'est précisément le cas que rowCount() confondrait.
        $mine->update($mineId, 'electricity', 120.0, $this->at('2026-01-01'), null, 1);
        self::assertTrue($mine->owns($mineId));

        $mine->delete($mineId);
        self::assertFalse($mine->owns($mineId));
    }

    public function testFindOverlappingDetectsIntersectingRanges(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);

        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), $this->at('2026-06-30'), 1);

        // Consécutif : pas de chevauchement.
        self::assertSame([], $repo->findOverlapping('electricity', $this->at('2026-07-01'), $this->at('2026-12-31')));

        // Intersecte le dernier mois.
        self::assertCount(1, $repo->findOverlapping('electricity', $this->at('2026-06-15'), $this->at('2026-12-31')));

        // Autre énergie : indépendante.
        self::assertSame([], $repo->findOverlapping('gas', $this->at('2026-01-01'), $this->at('2026-06-30')));
    }

    /** Une plage ouverte court indéfiniment : tout barème ultérieur la chevauche. */
    public function testFindOverlappingHandlesOpenEndedRanges(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);

        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), null, 1);

        self::assertCount(1, $repo->findOverlapping('electricity', $this->at('2030-01-01'), null));
    }

    /** Le barème en cours d'édition ne doit pas se déclarer en conflit avec lui-même. */
    public function testFindOverlappingExcludesTheEditedRow(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);

        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), $this->at('2026-12-31'), 1);
        $id = $repo->listFor('electricity')[0]->id;

        self::assertSame(
            [],
            $repo->findOverlapping('electricity', $this->at('2026-01-01'), $this->at('2026-12-31'), $id),
        );
    }

    /** La FK ON DELETE CASCADE emporte les barèmes avec le compte (RGPD). */
    public function testDeletingTheUserCascadesToItsSchedules(): void
    {
        $repo = new AdvanceScheduleRepository($this->pdo(), $this->userId);
        $repo->insert('electricity', 120.0, $this->at('2026-01-01'), null, 1);

        $stmt = $this->pdo()->prepare('DELETE FROM users WHERE id = :uid');
        $stmt->execute(['uid' => $this->userId]);

        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM energy_advances WHERE user_id = :uid');
        $count->execute(['uid' => $this->userId]);

        self::assertSame(0, (int) $count->fetchColumn());
    }
}
