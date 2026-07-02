<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration des jetons API (création, authentification, révocation,
 * rate-limit, isolation). S'auto-skippe sans base ; refuse une base non-test.
 */
final class ApiTokenRepositoryDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'token-test', 'test', 'Token Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['api_tokens', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function repo(): ApiTokenRepository
    {
        return new ApiTokenRepository($this->pdo());
    }

    public function testCreateAuthenticateAndRevoke(): void
    {
        $repo = $this->repo();

        $created = $repo->create($this->userId, 'Agent test');
        self::assertStringStartsWith('mec_', $created['token']);

        // Authentification OK → résout l'utilisateur.
        $auth = $repo->authenticate($created['token']);
        self::assertNotNull($auth);
        self::assertSame($this->userId, $auth['user_id']);

        // last_used_at mis à jour.
        $list = $repo->listForUser($this->userId);
        self::assertNotNull($list[0]['last_used_at']);

        // Révocation → refus immédiat.
        self::assertTrue($repo->revoke($created['id'], $this->userId));
        self::assertNull($repo->authenticate($created['token']));
    }

    public function testUnknownAndMalformedTokensAreRejected(): void
    {
        $repo = $this->repo();

        self::assertNull($repo->authenticate('mec_' . str_repeat('0', 40))); // inconnu
        self::assertNull($repo->authenticate('nimporte-quoi'));              // mal formé
    }

    public function testRevokeIsScopedToOwner(): void
    {
        $repo = $this->repo();
        $created = $repo->create($this->userId, 'Mine');

        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other', 'test', 'Autre')->id;

        // Un autre utilisateur ne peut pas révoquer mon jeton.
        self::assertFalse($repo->revoke($created['id'], $otherId));
        self::assertNotNull($repo->authenticate($created['token']));
    }

    public function testRateLimitFixedWindow(): void
    {
        $repo = $this->repo();
        $created = $repo->create($this->userId, 'Limited');

        // Limite 3/h : les 3 premières passent, la 4e est refusée.
        self::assertTrue($repo->consumeRateLimit($created['id'], 3));
        self::assertTrue($repo->consumeRateLimit($created['id'], 3));
        self::assertTrue($repo->consumeRateLimit($created['id'], 3));
        self::assertFalse($repo->consumeRateLimit($created['id'], 3));
    }

    public function testBlockedUserTokenIsRejected(): void
    {
        $repo = $this->repo();
        $created = $repo->create($this->userId, 'Blocked user token');

        $this->pdo()->prepare("UPDATE users SET status = 'blocked' WHERE id = :id")
            ->execute(['id' => $this->userId]);

        self::assertNull($repo->authenticate($created['token']));
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
