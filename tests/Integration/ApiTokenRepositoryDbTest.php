<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;

/**
 * Test d'intégration des jetons API (création, authentification, révocation,
 * rate-limit, isolation). S'auto-skippe sans base de test joignable.
 */
final class ApiTokenRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'token-test', 'test', 'Token Tester')->id;
    }

    protected function clean(): void
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
}
