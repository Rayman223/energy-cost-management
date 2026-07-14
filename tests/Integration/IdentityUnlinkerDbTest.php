<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Security\IdentityUnlinker;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Déliaison d'identité OIDC (#137) contre une vraie base : refus de la dernière,
 * promotion de la primaire, identité inconnue/étrangère. Le caractère atomique
 * (transaction + FOR UPDATE) exige une vraie base InnoDB. S'auto-skippe sans base.
 */
final class IdentityUnlinkerDbTest extends TestCase
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

        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['user_identities', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function pdo(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }

    public function testUnlinkSecondaryKeepsPrimary(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());
        $unlinker = new IdentityUnlinker($this->pdo(), $identities, $users);

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');
        $identities->link($user->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        $secondary = $this->identityId($identities, $user->id, 'sub-M');
        self::assertSame(IdentityUnlinker::UNLINKED, $unlinker->unlink($user->id, $secondary));

        self::assertSame(1, $identities->countForUser($user->id));
        // La primaire (google) reste intacte.
        self::assertSame('sub-G', $users->findById($user->id)?->oidcSub);
        self::assertNull($identities->findUserIdByOidc('https://login.microsoftonline.com/common/v2.0', 'sub-M'));
    }

    public function testUnlinkPrimaryPromotesRemaining(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());
        $unlinker = new IdentityUnlinker($this->pdo(), $identities, $users);

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');
        $identities->link($user->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        $primary = $this->identityId($identities, $user->id, 'sub-G');
        self::assertSame(IdentityUnlinker::UNLINKED, $unlinker->unlink($user->id, $primary));

        // users pointe désormais vers microsoft ; google/sub-G est libre.
        $refreshed = $users->findById($user->id);
        self::assertSame('sub-M', $refreshed?->oidcSub);
        self::assertSame('microsoft', $refreshed?->provider);
        self::assertNull($identities->findUserIdByOidc('https://accounts.google.com', 'sub-G'));

        // google/sub-G réutilisable sans collision uq_users_oidc.
        $other = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Bob');
        self::assertNotSame($user->id, $other->id);
    }

    public function testRefusesUnlinkingLastIdentity(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());
        $unlinker = new IdentityUnlinker($this->pdo(), $identities, $users);

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');
        $only = $this->identityId($identities, $user->id, 'sub-G');

        self::assertSame(IdentityUnlinker::LAST, $unlinker->unlink($user->id, $only));
        self::assertSame(1, $identities->countForUser($user->id)); // rien supprimé
    }

    public function testUnknownOrForeignIdentityIsNotFound(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());
        $unlinker = new IdentityUnlinker($this->pdo(), $identities, $users);

        $alice = $users->create('https://accounts.google.com', 'sub-A', 'google', 'Alice');
        $identities->link($alice->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-A2', 'microsoft');
        $bob = $users->create('https://accounts.google.com', 'sub-B', 'google', 'Bob');
        $identities->link($bob->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-B2', 'microsoft');

        $bobId = $this->identityId($identities, $bob->id, 'sub-B2');
        // Alice ne peut pas retirer une identité de Bob : NOT_FOUND, et rien n'est supprimé.
        self::assertSame(IdentityUnlinker::NOT_FOUND, $unlinker->unlink($alice->id, $bobId));
        self::assertSame(2, $identities->countForUser($bob->id));
    }

    private function identityId(UserIdentityRepository $identities, int $userId, string $sub): int
    {
        foreach ($identities->listForUser($userId) as $row) {
            if ($row['oidc_sub'] === $sub) {
                return $row['id'];
            }
        }
        self::fail('Identité introuvable pour le sujet ' . $sub);
    }
}
