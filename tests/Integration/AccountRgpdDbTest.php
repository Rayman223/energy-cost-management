<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MeterTopology;
use App\Repository\ApiTokenRepository;
use App\Repository\UserIntegrationRepository;
use App\Repository\TariffRepository;
use App\Repository\TariffTemplateRepository;
use App\Repository\TariffTemplateUsageRepository;
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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
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
            'tariff_grid_lines', 'tariff_grids', 'api_tokens', 'user_integrations',
            'webhook_sync_state', 'tariff_template_usages', 'tariff_template_fields',
            'tariff_templates', 'user_profiles', 'users',
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
        (new TariffRepository($pdo, $this->userId, false))->saveGrid('gas', 'Perso', new DateTimeImmutable('2026-01-01'), null, [
            ['key' => 'energy', 'amount' => 0.05, 'kind' => 'energy_flat', 'label' => null],
        ], 10.55);
        (new ApiTokenRepository($pdo))->create($this->userId, 'Agent');
        (new UserIntegrationRepository($pdo))->enable($this->userId, 'energyid', ['device_id' => 'dev-u' . $this->userId]);
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
        // L'export RGPD du profil couvre les 8 colonnes, dont pricing_mode (lacune
        // préexistante #88) et les données économiques par utilisateur (#153).
        self::assertArrayHasKey('pricing_mode', $data['profile']);
        self::assertArrayHasKey('vat_rate', $data['profile']);
        self::assertArrayHasKey('supplier_markup_per_kwh', $data['profile']);
        self::assertCount(1, $data['meters']);
        self::assertCount(1, $data['meter_readings']);
        self::assertSame('import_t1', $data['meter_readings'][0]['register_key']);
        self::assertCount(1, $data['utility_readings']);
        self::assertCount(1, $data['tariff_grids']);
        self::assertCount(1, $data['api_tokens']);
        self::assertArrayNotHasKey('token_hash', $data['api_tokens'][0]); // pas de secret
        self::assertCount(1, $data['integrations']);
        self::assertSame('energyid', $data['integrations'][0]['module_key']);
        self::assertTrue((bool) $data['integrations'][0]['enabled']);
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
            'user_integrations' => 'user_id',
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

    public function testEraseKeepsPublicTemplatesButRemovesPrivateAndUsages(): void
    {
        $owner = new TariffTemplateRepository($this->pdo(), $this->userId);
        $field = [['key' => 'energy', 'kind' => 'energy_flat', 'label' => null]];
        $publicId  = $owner->save('gas', 'BE', 'Public', $field, 'public');
        $privateId = $owner->save('gas', 'BE', 'Privé', $field, 'private');

        $peerId = (new UserRepository($this->pdo()))->create('https://iss.test', 'peer', 'test', 'Peer')->id;
        $usage  = new TariffTemplateUsageRepository($this->pdo());
        $usage->record('user:' . $publicId, $this->userId); // usage du propriétaire
        $usage->record('user:' . $publicId, $peerId);        // usage d'un autre compte
        $usage->record('user:' . $privateId, $this->userId);

        (new AccountEraser($this->pdo()))->erase($this->userId);

        $ownerOf = function (int $id): array {
            $stmt = $this->pdo()->prepare('SELECT user_id FROM tariff_templates WHERE id = :id');
            $stmt->execute(['id' => $id]);

            return $stmt->fetchAll();
        };
        $public = $ownerOf($publicId);
        self::assertCount(1, $public, 'Template public supprimé à tort');
        self::assertNull($public[0]['user_id'], 'Template public non anonymisé (user_id devrait être NULL)');
        self::assertSame([], $ownerOf($privateId), 'Template privé non supprimé');

        // Usages du compte supprimé partis (cascade FK) ; l'usage du pair sur le
        // template public conservé subsiste. Le privé supprimé n'a plus d'usage.
        $counts = $usage->countsByRef();
        self::assertSame(1, $counts['user:' . $publicId] ?? 0);
        self::assertArrayNotHasKey('user:' . $privateId, $counts);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
