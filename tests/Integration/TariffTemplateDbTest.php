<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\TariffTemplateRepository;
use App\Repository\TariffTemplateUsageRepository;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Visibilité public/privé des templates + compteur d'utilisation (Issue #84).
 * S'auto-skippe sans base ; refuse une base non-test.
 */
final class TariffTemplateDbTest extends TestCase
{
    private ?PDO $pdo = null;

    private int $ownerId = 0;

    private int $otherId = 0;

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
        $this->ownerId = $users->create('https://iss.test', 'owner', 'test', 'Owner')->id;
        $this->otherId = $users->create('https://iss.test', 'other', 'test', 'Other')->id;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        foreach (['tariff_template_usages', 'tariff_template_fields', 'tariff_templates', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    /** @return list<array{key:string, kind:string, label:?string}> */
    private function fields(): array
    {
        return [['key' => 'energy', 'kind' => 'energy_flat', 'label' => null]];
    }

    public function testPublicTemplateVisibleToOthersButPrivateIsNot(): void
    {
        $owner = new TariffTemplateRepository($this->pdo(), $this->ownerId);
        $publicId  = $owner->save('gas', 'BE', 'Public', $this->fields(), 'public');
        $privateId = $owner->save('gas', 'BE', 'Privé', $this->fields(), 'private');

        $other = new TariffTemplateRepository($this->pdo(), $this->otherId);

        // L'autre compte voit le public, pas le privé.
        $visibleIds = array_map(static fn (array $t): int => $t['id'], $other->findForEnergy('gas'));
        self::assertContains($publicId, $visibleIds);
        self::assertNotContains($privateId, $visibleIds);

        // Import direct : public accessible, privé introuvable.
        self::assertNotNull($other->findById($publicId));
        self::assertNull($other->findById($privateId));

        // Le propriétaire voit ses deux templates, avec is_owner à true.
        $mine = $owner->findForEnergy('gas');
        self::assertCount(2, $mine);
        foreach ($mine as $t) {
            self::assertTrue($t['is_owner']);
        }
    }

    public function testUsageCountsDistinctUsersAndIsIdempotent(): void
    {
        $usage = new TariffTemplateUsageRepository($this->pdo());

        // Le même utilisateur enregistre deux fois → compté une seule fois.
        $usage->record('builtin:be_electricity', $this->ownerId);
        $usage->record('builtin:be_electricity', $this->ownerId);
        // Un autre utilisateur → deuxième unité.
        $usage->record('builtin:be_electricity', $this->otherId);

        $counts = $usage->countsByRef();
        self::assertSame(2, $counts['builtin:be_electricity'] ?? 0);
    }

    public function testDeletingTemplatePurgesItsUsages(): void
    {
        $owner = new TariffTemplateRepository($this->pdo(), $this->ownerId);
        $id = $owner->save('gas', 'BE', 'Public', $this->fields(), 'public');

        $usage = new TariffTemplateUsageRepository($this->pdo());
        $usage->record('user:' . $id, $this->ownerId);
        $usage->record('user:' . $id, $this->otherId);
        self::assertSame(2, $usage->countsByRef()['user:' . $id] ?? 0);

        $owner->delete($id);

        self::assertArrayNotHasKey('user:' . $id, $usage->countsByRef());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
