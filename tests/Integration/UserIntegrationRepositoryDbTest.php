<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserIntegrationRepository;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in générique des connecteurs d'export (#70). S'auto-skippe sans base ;
 * refuse une base non-test.
 */
final class UserIntegrationRepositoryDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'uid', 'test', 'UI Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['user_integrations', 'energyid_integrations', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testEnableDisablePatchAndList(): void
    {
        $repo = new UserIntegrationRepository($this->pdo());

        self::assertNull($repo->get($this->userId, 'energyid'));
        self::assertSame([], $repo->listEnabledUsers('energyid'));

        $repo->enable($this->userId, 'energyid', ['device_id' => 'dev-u' . $this->userId]);
        $state = $repo->get($this->userId, 'energyid');
        self::assertNotNull($state);
        self::assertTrue($state['enabled']);
        self::assertSame('dev-u' . $this->userId, $state['settings']['device_id']);
        self::assertArrayNotHasKey('claimed_at', $state['settings']);

        $enabled = $repo->listEnabledUsers('energyid');
        self::assertCount(1, $enabled);
        self::assertSame($this->userId, $enabled[0]['user_id']);
        self::assertSame('dev-u' . $this->userId, $enabled[0]['settings']['device_id']);

        // patchSettings pose claimed_at sans écraser device_id.
        $repo->patchSettings($this->userId, 'energyid', ['claimed_at' => '2026-07-18 01:15:00']);
        $patched = $repo->get($this->userId, 'energyid');
        self::assertNotNull($patched);
        self::assertSame('2026-07-18 01:15:00', $patched['settings']['claimed_at']);
        self::assertSame('dev-u' . $this->userId, $patched['settings']['device_id']);

        // Ré-enable réapplique le device sans effacer claimed_at (JSON_MERGE_PATCH).
        $repo->enable($this->userId, 'energyid', ['device_id' => 'dev-u' . $this->userId]);
        $reEnabled = $repo->get($this->userId, 'energyid');
        self::assertNotNull($reEnabled);
        self::assertSame('2026-07-18 01:15:00', $reEnabled['settings']['claimed_at']);

        $repo->disable($this->userId, 'energyid');
        $disabled = $repo->get($this->userId, 'energyid');
        self::assertNotNull($disabled);
        self::assertFalse($disabled['enabled']);
        self::assertSame([], $repo->listEnabledUsers('energyid'));
    }

    /**
     * Reprise des données energyid_integrations → user_integrations (idempotente).
     */
    public function testMigrationFromLegacyTableIsIdempotent(): void
    {
        $this->pdo()->prepare(
            'INSERT INTO energyid_integrations (user_id, enabled, device_id, claimed_at)
             VALUES (:uid, 1, :dev, :claimed)'
        )->execute(['uid' => $this->userId, 'dev' => 'legacy-u' . $this->userId, 'claimed' => '2026-07-01 00:00:00']);

        $migrate = static fn (PDO $pdo): int => (int) $pdo->exec(
            "INSERT INTO user_integrations (user_id, module_key, enabled, settings, created_at, updated_at)
             SELECT user_id, 'energyid', enabled,
                    JSON_OBJECT('device_id', device_id, 'claimed_at', claimed_at),
                    updated_at, updated_at
             FROM energyid_integrations
             ON DUPLICATE KEY UPDATE user_id = user_integrations.user_id"
        );

        $migrate($this->pdo());
        $migrate($this->pdo()); // 2e passage : idempotent, pas d'erreur ni de doublon.

        $repo = new UserIntegrationRepository($this->pdo());
        $state = $repo->get($this->userId, 'energyid');
        self::assertNotNull($state);
        self::assertTrue($state['enabled']);
        self::assertSame('legacy-u' . $this->userId, $state['settings']['device_id']);
        self::assertSame('2026-07-01 00:00:00', $state['settings']['claimed_at']);

        $rows = $this->pdo()->query(
            'SELECT COUNT(*) AS c FROM user_integrations WHERE user_id = ' . $this->userId
        );
        self::assertNotFalse($rows);
        $count = $rows->fetch();
        self::assertIsArray($count);
        self::assertSame(1, (int) $count['c']);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
