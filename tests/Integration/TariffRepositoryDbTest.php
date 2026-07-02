<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du catalogue tarifaire partagé + surcharges personnelles.
 * S'auto-skippe sans base ; refuse une base non-test.
 */
final class TariffRepositoryDbTest extends TestCase
{
    private ?PDO $pdo = null;

    private int $userId = 0;

    private int $adminId = 0;

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

        $users = new UserRepository($this->pdo());
        $this->adminId = $users->create('https://iss.test', 'admin', 'test', 'Admin')->id;
        $this->userId  = $users->create('https://iss.test', 'user', 'test', 'Membre')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['tariff_grid_lines', 'tariff_grids', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testPersonalOverrideBeatsSharedCatalog(): void
    {
        $admin = new TariffRepository($this->pdo(), $this->adminId, true);
        $user  = new TariffRepository($this->pdo(), $this->userId, false);

        // Catalogue partagé (admin) + surcharge perso du membre.
        $admin->saveGrid('electricity', 'Catalogue BE', new DateTimeImmutable('2026-01-01'), null, ['energy_t1' => 0.10], null, 'BE', 'EUR', true);
        $user->saveGrid('electricity', 'Ma grille', new DateTimeImmutable('2026-01-01'), null, ['energy_t1' => 0.12]);

        $active = $user->findActiveGrid('electricity', new DateTimeImmutable('2026-06-01'));

        self::assertNotNull($active);
        self::assertSame('Ma grille', $active->name);
        self::assertFalse($active->isShared());

        // Un autre membre sans surcharge tombe sur le catalogue.
        $users = new UserRepository($this->pdo());
        $thirdId = $users->create('https://iss.test', 'third', 'test', 'Tiers')->id;
        $third = new TariffRepository($this->pdo(), $thirdId, false);

        $fallback = $third->findActiveGrid('electricity', new DateTimeImmutable('2026-06-01'));
        self::assertNotNull($fallback);
        self::assertSame('Catalogue BE', $fallback->name);
        self::assertTrue($fallback->isShared());
        self::assertSame('BE', $fallback->country);
        self::assertSame('EUR', $fallback->currency);
    }

    public function testNonAdminCannotCreateSharedGrid(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);

        $this->expectException(\RuntimeException::class);

        $user->saveGrid('electricity', 'Pirate', new DateTimeImmutable('2026-01-01'), null, [], null, null, 'EUR', true);
    }

    public function testPersonalGridsAreInvisibleToOthers(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $gridId = $user->saveGrid('gas', 'Ma grille gaz', new DateTimeImmutable('2026-01-01'), null, ['energy' => 0.05], 10.55, null, 'PLN');

        $admin = new TariffRepository($this->pdo(), $this->adminId, true);

        self::assertNull($admin->findById($gridId));            // invisible
        self::assertSame([], $admin->findAll('gas'));           // absent des listes

        // Et non modifiable par un tiers (même admin) : « introuvable ».
        $this->expectException(\RuntimeException::class);
        $admin->deleteGrid($gridId);
    }

    public function testCurrencyIsPersisted(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $id = $user->saveGrid('electricity', 'PL grid', new DateTimeImmutable('2026-01-01'), null, [], null, 'PL', 'PLN');

        $grid = $user->findById($id);
        self::assertNotNull($grid);
        self::assertSame('PLN', $grid->currency);
        self::assertSame('PL', $grid->country);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
