<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\EnergyIdIntegrationRepository;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in EnergyID par utilisateur. S'auto-skippe sans base ; refuse une base non-test.
 */
final class EnergyIdIntegrationRepositoryDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'eid', 'test', 'EID Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['energyid_integrations', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testEnableDisableAndListEnabled(): void
    {
        $repo = new EnergyIdIntegrationRepository($this->pdo());

        self::assertNull($repo->get($this->userId));
        self::assertSame([], $repo->listEnabled());

        $repo->enable($this->userId, 'dev-u' . $this->userId);
        $state = $repo->get($this->userId);
        self::assertNotNull($state);
        self::assertTrue($state['enabled']);
        self::assertSame('dev-u' . $this->userId, $state['device_id']);
        self::assertNull($state['claimed_at']);

        $enabled = $repo->listEnabled();
        self::assertCount(1, $enabled);
        self::assertSame($this->userId, $enabled[0]['user_id']);

        $repo->markClaimed($this->userId);
        self::assertNotNull($repo->get($this->userId)['claimed_at']);

        $repo->disable($this->userId);
        self::assertFalse($repo->get($this->userId)['enabled']);
        self::assertSame([], $repo->listEnabled());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
