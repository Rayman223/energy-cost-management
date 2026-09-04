<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Infrastructure\MeterTopology;
use App\Repository\AdvanceScheduleRepository;
use App\Repository\ApiTokenRepository;
use App\Repository\BatteryRepository;
use App\Repository\TariffRepository;
use App\Repository\TariffTemplateRepository;
use App\Repository\TariffTemplateUsageRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\AccountDataExporter;
use App\Service\AccountEraser;
use DateTimeImmutable;

/**
 * Export + effacement RGPD contre une vraie base. S'auto-skippe sans base de test
 * joignable.
 */
final class AccountRgpdDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'rgpd', 'test', 'RGPD Tester')->id;
    }

    protected function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'meters', 'utility_readings',
            'battery_readings', 'batteries',
            'tariff_grid_lines', 'tariff_grids', 'api_tokens',
            'energy_advances',
            'tariff_template_usages', 'tariff_template_fields',
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
        (new AdvanceScheduleRepository($pdo, $this->userId))->insert(
            'electricity',
            120.0,
            new DateTimeImmutable('2026-01-01'),
            null,
            5,
            'contrat 2026',
        );

        // Parc de batteries (#26) : le matériel est saisi par l'utilisateur, ses
        // relevés sont rattachés par FK — l'export doit rendre les deux, et
        // l'effacement les emporter tous les deux.
        $batteryId = (new BatteryRepository($pdo, $this->userId))->insert(new Battery(
            id: 0,
            brand: 'BYD',
            model: 'HVS 10.2',
            capacityKwh: 10.24,
            commissionedOn: new DateTimeImmutable('2026-01-01'),
            purchasePrice: 7500.0,
            pvChargeShare: 80,
            dischargeProfile: BatteryDischargeProfile::Ratio,
            dischargeT1Share: 55,
        ));
        $pdo->prepare(
            'INSERT INTO battery_readings (battery_id, reading_at, charge_index_kwh, discharge_index_kwh)
             VALUES (:b, :a, :c, :d)'
        )->execute(['b' => $batteryId, 'a' => '2026-06-01 10:00:00', 'c' => 1200.0, 'd' => 1000.0]);
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
        // L'export RGPD du profil couvre ses colonnes, dont la marge fournisseur par
        // utilisateur (#153). Ni vat_rate ni pricing_mode : ces colonnes ont été
        // supprimées au profit de la grille (#232, #245) — un export qui les
        // mentionnerait encore signalerait une migration incomplète. Le mode ressort
        // désormais dans l'export des grilles, où il vit.
        self::assertArrayNotHasKey('vat_rate', $data['profile']);
        self::assertArrayNotHasKey('pricing_mode', $data['profile']);
        self::assertArrayHasKey('supplier_markup_per_kwh', $data['profile']);
        self::assertArrayHasKey('pricing_mode', $data['tariff_grids'][0]);
        self::assertCount(1, $data['meters']);
        self::assertCount(1, $data['meter_readings']);
        self::assertSame('import_t1', $data['meter_readings'][0]['register_key']);
        self::assertCount(1, $data['utility_readings']);
        self::assertCount(1, $data['tariff_grids']);
        self::assertCount(1, $data['api_tokens']);
        self::assertArrayNotHasKey('token_hash', $data['api_tokens'][0]); // pas de secret
        // Barèmes d'acomptes (#241) : données saisies par l'utilisateur, donc
        // exportables au même titre que ses grilles tarifaires.
        self::assertCount(1, $data['energy_advances']);
        self::assertSame('electricity', $data['energy_advances'][0]['energy_type']);
        self::assertSame(5, (int) $data['energy_advances'][0]['due_day']);
        // Batteries (#26) : le matériel ET ses index. Les hypothèses de calcul en
        // font partie — sans elles, l'export ne permettrait pas de refaire le bilan.
        self::assertCount(1, $data['batteries']);
        self::assertSame('BYD', $data['batteries'][0]['brand']);
        self::assertSame(80, (int) $data['batteries'][0]['pv_charge_share']);
        self::assertSame('ratio', $data['batteries'][0]['discharge_profile']);
        self::assertCount(1, $data['battery_readings']);
        self::assertSame(1000.0, (float) $data['battery_readings'][0]['discharge_index_kwh']);
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
            'energy_advances' => 'user_id',
            'batteries' => 'user_id',
        ] as $table => $col) {
            $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = :uid");
            $stmt->execute(['uid' => $this->userId]);
            self::assertSame(0, (int) $stmt->fetchColumn(), "Table {$table} non vidée");
        }

        // Les relevés de compteur (cascade via meters) ont disparu aussi.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_registers')->fetchColumn());
        // Idem pour les index de batterie (cascade via batteries, #26) : ils ne
        // portent pas de user_id, la boucle ci-dessus ne peut donc pas les couvrir.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM battery_readings')->fetchColumn());
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
}
