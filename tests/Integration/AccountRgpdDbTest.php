<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MeterTopology;
use App\Repository\ApiTokenRepository;
use App\Repository\EnergyIdIntegrationRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Service\AccountDataExporter;
use App\Service\AccountEraser;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Export + effacement RGPD contre une vraie base. S'auto-skippe sans base ;
 * refuse une base non-test.
 */
final class AccountRgpdDbTest extends TestCase
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
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'rgpd', 'test', 'RGPD Tester')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'meters', 'utility_readings',
            'tariff_grid_lines', 'tariff_grids', 'api_tokens', 'energyid_integrations',
            'webhook_sync_state', 'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function seedAllData(): void
    {
        $pdo = $this->pdo();
        $topology = new MeterTopology($pdo);
        $meterId = $topology->ensureElectricityMeter($this->userId);
        $registers = $topology->ensureRegisters($meterId);
        $pdo->prepare('INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:r, :a, :v)')
            ->execute(['r' => $registers['import_t1'], 'a' => '2026-06-01 10:00:00', 'v' => 100.0]);

        (new UtilityReadingRepository($pdo, $this->userId, 'gas'))->save(new DateTimeImmutable('2026-06-01 10:00:00'), 50.0);
        (new TariffRepository($pdo, $this->userId, false))->saveGrid('gas', 'Perso', new DateTimeImmutable('2026-01-01'), null, ['energy' => 0.05], 10.55);
        (new ApiTokenRepository($pdo))->create($this->userId, 'Agent');
        (new EnergyIdIntegrationRepository($pdo))->enable($this->userId, 'dev-u' . $this->userId);
        (new WebhookSyncStateRepository($pdo, $this->userId))->saveLastSentAt('gas-index', new DateTimeImmutable('2026-06-02 01:00:00'));
    }

    public function testExportContainsAllUserData(): void
    {
        $this->seedAllData();

        ob_start();
        (new AccountDataExporter($this->pdo()))->stream($this->userId);
        $json = (string) ob_get_clean();

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        self::assertSame($this->userId, $data['user']['id']);
        self::assertSame('rgpd', $data['user']['oidc_sub']);
        self::assertNotNull($data['profile']);
        self::assertCount(1, $data['meters']);
        self::assertCount(1, $data['meter_readings']);
        self::assertSame('import_t1', $data['meter_readings'][0]['register_key']);
        self::assertCount(1, $data['utility_readings']);
        self::assertCount(1, $data['tariff_grids']);
        self::assertCount(1, $data['api_tokens']);
        self::assertArrayNotHasKey('token_hash', $data['api_tokens'][0]); // pas de secret
        self::assertTrue((bool) $data['energyid']['enabled']);
        self::assertCount(1, $data['sync_state']);
    }

    public function testEraseRemovesEverything(): void
    {
        $this->seedAllData();

        (new AccountEraser($this->pdo()))->erase($this->userId);

        foreach ([
            'users' => 'id',
            'user_profiles' => 'user_id',
            'meters' => 'user_id',
            'utility_readings' => 'user_id',
            'tariff_grids' => 'user_id',
            'api_tokens' => 'user_id',
            'energyid_integrations' => 'user_id',
            'webhook_sync_state' => 'user_id',
        ] as $table => $col) {
            $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = :uid");
            $stmt->execute(['uid' => $this->userId]);
            self::assertSame(0, (int) $stmt->fetchColumn(), "Table {$table} non vidée");
        }

        // Les relevés de compteur (cascade via meters) ont disparu aussi.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_registers')->fetchColumn());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
