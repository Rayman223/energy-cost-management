<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration de UserRepository contre une vraie base MySQL/MariaDB.
 *
 * S'AUTO-SKIPPE quand aucune base n'est joignable. Le seed est isolé dans une
 * transaction annulée en tearDown ; on refuse toute base dont le nom ne contient
 * pas « test » (garde anti-destruction).
 */
final class UserRepositoryDbTest extends TestCase
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

        // Nettoyage direct (pas d'isolation par transaction : UserRepository::create()
        // ouvre la sienne). La garde « base de test » protège des données réelles.
        $this->pdo->exec('DELETE FROM user_profiles');
        $this->pdo->exec('DELETE FROM users');
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DELETE FROM user_profiles');
            $this->pdo->exec('DELETE FROM users');
        }
    }

    public function testCreateFindAndProfileDefaults(): void
    {
        $repo = new UserRepository($this->pdo());

        self::assertNull($repo->findByOidc('https://iss.example', 'sub-1'));

        $user = $repo->create('https://iss.example', 'sub-1', 'example', 'Bob');
        self::assertGreaterThan(0, $user->id);
        self::assertSame('Bob', $user->displayName);
        self::assertSame('admin', $user->role); // 1er compte (owner) → admin
        self::assertTrue($user->isActive());

        $found = $repo->findByOidc('https://iss.example', 'sub-1');
        self::assertNotNull($found);
        self::assertSame($user->id, $found->id);

        $profile = $repo->getProfile($user->id);
        self::assertNotNull($profile);
        self::assertSame('EUR', $profile['currency']);
        self::assertSame('fr', $profile['locale']);
        self::assertSame('Europe/Brussels', $profile['timezone']);
    }

    public function testUpdateDisplayName(): void
    {
        $repo = new UserRepository($this->pdo());

        $user = $repo->create('https://iss.example', 'sub-2', 'example', 'Bob');
        $repo->updateDisplayName($user->id, 'Bobby');

        $reloaded = $repo->findById($user->id);
        self::assertNotNull($reloaded);
        self::assertSame('Bobby', $reloaded->displayName);
    }

    public function testListAllReturnsCreatedAccounts(): void
    {
        $repo = new UserRepository($this->pdo());

        self::assertSame([], $repo->listAll());

        $a = $repo->create('https://iss.example', 'sub-a', 'example', 'Alice');
        $b = $repo->create('https://iss.example', 'sub-b', 'example', 'Bob');

        $all = $repo->listAll();
        self::assertCount(2, $all);
        $ids = array_column($all, 'id');
        self::assertContains($a->id, $ids);
        self::assertContains($b->id, $ids);
        // Ordonné par created_at ASC : Alice (1re, owner) est admin ; Bob reste user.
        self::assertSame('admin', $all[0]['role']);
        self::assertSame('user', $all[1]['role']);
        self::assertSame('active', $all[0]['status']);
    }

    public function testSetRoleAndStatus(): void
    {
        $repo = new UserRepository($this->pdo());
        // Le 1er compte est promu admin d'office ; on teste setRole sur un 2e
        // compte (rôle « user »), sinon setRole('admin') serait un no-op (rowCount 0).
        $repo->create('https://iss.example', 'sub-owner', 'example', 'Owner');
        $user = $repo->create('https://iss.example', 'sub-c', 'example', 'Carol');
        self::assertSame('user', $user->role);

        self::assertTrue($repo->setRole($user->id, 'admin'));
        self::assertTrue($repo->findById($user->id)?->isAdmin());

        self::assertTrue($repo->setStatus($user->id, 'blocked'));
        self::assertFalse($repo->findById($user->id)?->isActive());

        // Valeurs invalides rejetées, sans lever d'exception.
        self::assertFalse($repo->setRole($user->id, 'superuser'));
        self::assertFalse($repo->setStatus($user->id, 'paused'));

        // Compte inconnu : aucune ligne affectée.
        self::assertFalse($repo->setRole(999999, 'admin'));
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
