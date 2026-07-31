<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\TariffLineCatalog;
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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
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

    /**
     * Convertit une grille plate `clé => montant` au format structuré attendu par
     * saveGrid (kind déduit du catalogue).
     *
     * @param  array<string,float> $flat
     * @return list<array{key: string, amount: float, kind: string, label: null}>
     */
    private function lines(array $flat, string $energy = 'electricity'): array
    {
        $out = [];
        foreach ($flat as $key => $amount) {
            $out[] = [
                'key'    => $key,
                'amount' => (float) $amount,
                'kind'   => TariffLineCatalog::kindFor($energy, $key)->value,
                'label'  => null,
            ];
        }

        return $out;
    }

    public function testPersonalOverrideBeatsSharedCatalog(): void
    {
        $admin = new TariffRepository($this->pdo(), $this->adminId, true);
        $user  = new TariffRepository($this->pdo(), $this->userId, false);

        // Catalogue partagé (admin) + surcharge perso du membre.
        $admin->saveGrid('electricity', 'Catalogue BE', new DateTimeImmutable('2026-01-01'), null, $this->lines(['energy_t1' => 0.10]), null, 'BE', 'EUR', true);
        $user->saveGrid('electricity', 'Ma grille', new DateTimeImmutable('2026-01-01'), null, $this->lines(['energy_t1' => 0.12]));

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
        $gridId = $user->saveGrid('gas', 'Ma grille gaz', new DateTimeImmutable('2026-01-01'), null, $this->lines(['energy' => 0.05], 'gas'), 10.55, null, 'PLN');

        $admin = new TariffRepository($this->pdo(), $this->adminId, true);

        self::assertNull($admin->findById($gridId));            // invisible
        self::assertSame([], $admin->findAll('gas'));           // absent des listes

        // Et non modifiable par un tiers (même admin) : « introuvable ».
        $this->expectException(\RuntimeException::class);
        $admin->deleteGrid($gridId);
    }

    /**
     * #205 : un membre non-admin peut réutiliser une grille du catalogue partagé
     * — la lire intégralement (montants inclus) via findById, puis en réécrire
     * une copie personnelle qui prend le pas au calcul. C'est le chemin emprunté
     * par « Dupliquer » (route /tariff : ?duplicate=<id> puis save).
     */
    public function testSharedGridCanBeDuplicatedAsPersonalGrid(): void
    {
        $admin = new TariffRepository($this->pdo(), $this->adminId, true);
        $user  = new TariffRepository($this->pdo(), $this->userId, false);

        $sharedId = $admin->saveGrid(
            'electricity',
            'Catalogue BE',
            new DateTimeImmutable('2026-01-01'),
            null,
            $this->lines(['energy_t1' => 0.10, 'excise_duty' => 0.02]),
            null,
            'BE',
            'EUR',
            true,
            6.0,
        );

        // Le membre voit la grille partagée avec ses montants (source de la copie).
        $source = $user->findById($sharedId);
        self::assertNotNull($source);
        self::assertTrue($source->isShared());
        self::assertSame(0.10, $source->lines['energy_t1']->amount);

        // Copie : mêmes lignes/pays/devise/TVA, mais grille personnelle.
        $copyLines = [];
        foreach ($source->lines as $line) {
            $copyLines[] = ['key' => $line->key, 'amount' => $line->amount, 'kind' => $line->kind->value, 'label' => $line->label];
        }
        $copyId = $user->saveGrid(
            'electricity',
            'Copie de Catalogue BE',
            new DateTimeImmutable('2026-01-01'),
            null,
            $copyLines,
            null,
            $source->country,
            $source->currency,
            false,
            $source->vatRate,
        );

        $copy = $user->findById($copyId);
        self::assertNotNull($copy);
        self::assertFalse($copy->isShared());
        self::assertSame('BE', $copy->country);
        self::assertSame(6.0, $copy->vatRate);
        self::assertSame(0.10, $copy->lines['energy_t1']->amount);
        self::assertSame(0.02, $copy->lines['excise_duty']->amount);

        // Et la copie l'emporte sur le catalogue pour ce membre.
        $active = $user->findActiveGrid('electricity', new DateTimeImmutable('2026-06-01'));
        self::assertNotNull($active);
        self::assertSame('Copie de Catalogue BE', $active->name);
    }

    /**
     * Duplication de sa propre grille le jour même : les deux grilles partagent
     * `valid_from`, seul `id DESC` départage. Sans lui, findActiveGrid pourrait
     * rendre l'ancienne et le calcul resterait sur les prix d'avant.
     */
    public function testMostRecentGridWinsOnEqualValidFrom(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $from = new DateTimeImmutable('2026-01-01');

        $user->saveGrid('electricity', 'Origine', $from, null, $this->lines(['energy_t1' => 0.10]));
        $user->saveGrid('electricity', 'Copie', $from, null, $this->lines(['energy_t1' => 0.14]));

        $active = $user->findActiveGrid('electricity', new DateTimeImmutable('2026-06-01'));
        self::assertNotNull($active);
        self::assertSame('Copie', $active->name);
        self::assertSame(0.14, $active->lines['energy_t1']->amount);

        // findAll suit le même ordre : $latest (grids[0]) reprend bien la copie.
        $all = $user->findAll('electricity');
        self::assertSame('Copie', $all[0]->name);
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

    public function testVatRateAndLineMetadataRoundTrip(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $id = $user->saveGrid(
            'water',
            'Eau DE',
            new DateTimeImmutable('2026-01-01'),
            null,
            [
                ['key' => 'water_supply', 'amount' => 2.10, 'kind' => 'per_m3', 'label' => null],
                ['key' => 'custom_taxe', 'amount' => 0.50, 'kind' => 'fixed_annual', 'label' => 'Taxe locale'],
            ],
            null,
            'DE',
            'EUR',
            false,
            19.0,
        );

        $grid = $user->findById($id);
        self::assertNotNull($grid);
        self::assertSame(19.0, $grid->vatRate);
        self::assertSame('per_m3', $grid->lines['water_supply']->kind->value);
        self::assertNull($grid->lines['water_supply']->label);
        self::assertSame('fixed_annual', $grid->lines['custom_taxe']->kind->value);
        self::assertSame('Taxe locale', $grid->lines['custom_taxe']->label);
    }

    public function testLineCategoryRoundTripAndFallback(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $id = $user->saveGrid(
            'electricity',
            'Grille catégorisée',
            new DateTimeImmutable('2026-01-01'),
            null,
            [
                // Distribution rangée manuellement hors des taxes.
                ['key' => 'distribution_t1', 'amount' => 0.05, 'kind' => 'per_kwh_t1', 'label' => null, 'category' => 'distribution'],
                // Aucune catégorie fournie → NULL stocké → dérivée du kind au read.
                ['key' => 'excise_duty', 'amount' => 0.02, 'kind' => 'per_kwh', 'label' => null],
            ],
        );

        $grid = $user->findById($id);
        self::assertNotNull($grid);

        // Catégorie explicite conservée.
        self::assertSame('distribution', $grid->lines['distribution_t1']->category()->value);

        // Ligne sans catégorie : le VO garde NULL mais expose le défaut dérivé du kind.
        self::assertNull($grid->lines['excise_duty']->category);
        self::assertSame('taxes', $grid->lines['excise_duty']->category()->value);
    }

    /**
     * Proration multi-grilles (#196) : findActiveGridsBetween renvoie toutes les
     * grilles chevauchant la période, dans l'ordre de priorité de findActiveGrid.
     */
    public function testFindActiveGridsBetweenReturnsOverlappingGridsByPriority(): void
    {
        $admin = new TariffRepository($this->pdo(), $this->adminId, true);
        $user  = new TariffRepository($this->pdo(), $this->userId, false);

        $user->saveGrid('electricity', 'A', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-15'), $this->lines(['energy_t1' => 0.10]));
        $user->saveGrid('electricity', 'B', new DateTimeImmutable('2026-01-16'), null, $this->lines(['energy_t1' => 0.20]));
        // Hors période : ne doit pas ressortir.
        $user->saveGrid('electricity', 'Ancienne', new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'), $this->lines(['energy_t1' => 0.05]));
        // Catalogue partagé : visible, mais après la surcharge personnelle.
        $admin->saveGrid('electricity', 'Catalogue', new DateTimeImmutable('2026-01-01'), null, $this->lines(['energy_t1' => 0.15]), null, 'BE', 'EUR', true);

        $grids = $user->findActiveGridsBetween('electricity', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

        self::assertSame(['B', 'A', 'Catalogue'], array_map(static fn ($g): string => $g->name, $grids));
        // Les lignes sont hydratées (chargement groupé).
        self::assertSame(0.20, $grids[0]->getLine('energy_t1'));
    }

    public function testFindActiveGridsBetweenIsEmptyOutsideAnyValidity(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);
        $user->saveGrid('electricity', 'A', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-15'), $this->lines(['energy_t1' => 0.10]));

        $grids = $user->findActiveGridsBetween('electricity', new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));

        self::assertSame([], $grids);
    }

    /**
     * Le mode de tarification fait l'aller-retour, y compris à la mise à jour (#245).
     * C'est ce qui permet de dater une bascule de contrat : deux grilles successives,
     * deux modes, les périodes passées restant calculées comme elles l'étaient.
     */
    public function testPricingModeRoundTrips(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);

        $id = $user->saveGrid(
            'electricity',
            'Dynamique',
            new DateTimeImmutable('2026-01-01'),
            null,
            $this->lines(['energy_t1' => 0.10]),
            pricingMode: 'dynamic_quarter',
        );

        $grid = $user->findById($id);
        self::assertNotNull($grid);
        self::assertSame('dynamic_quarter', $grid->pricingMode);
        self::assertTrue($grid->isDynamic());

        $user->updateGrid(
            $id,
            'electricity',
            'Repassée en fixe',
            new DateTimeImmutable('2026-01-01'),
            null,
            $this->lines(['energy_t1' => 0.10]),
            pricingMode: 'fixed',
        );

        $updated = $user->findById($id);
        self::assertNotNull($updated);
        self::assertSame('fixed', $updated->pricingMode);
        self::assertFalse($updated->isDynamic());
    }

    /**
     * Normalisation en écriture : valeur hors liste blanche → 'fixed', et mode forcé à
     * 'fixed' hors électricité. L'API accepte des grilles importées sans valider ce
     * champ, et un ENUM MySQL sans STRICT_TRANS_TABLES tronquerait en silence.
     */
    public function testPricingModeIsNormalisedOnWrite(): void
    {
        $user = new TariffRepository($this->pdo(), $this->userId, false);

        $bogus = $user->saveGrid(
            'electricity',
            'Mode inconnu',
            new DateTimeImmutable('2026-01-01'),
            null,
            $this->lines(['energy_t1' => 0.10]),
            pricingMode: 'dynamic',
        );
        self::assertSame('fixed', $user->findById($bogus)?->pricingMode);

        $water = $user->saveGrid(
            'water',
            'Eau dynamique ?',
            new DateTimeImmutable('2026-01-01'),
            null,
            $this->lines(['water_variable' => 3.0]),
            pricingMode: 'dynamic_hourly',
        );
        self::assertSame('fixed', $user->findById($water)?->pricingMode);
    }

    /**
     * `hasDynamicGrid()` décide de l'accès à /reconciliation : il doit voir les
     * grilles du catalogue partagé comme les personnelles, et ne pas confondre les
     * énergies.
     */
    public function testHasDynamicGridSeesPersonalAndSharedGrids(): void
    {
        $user  = new TariffRepository($this->pdo(), $this->userId, false);
        $admin = new TariffRepository($this->pdo(), $this->adminId, true);

        self::assertFalse($user->hasDynamicGrid());

        $user->saveGrid('electricity', 'Fixe', new DateTimeImmutable('2026-01-01'), null, $this->lines(['energy_t1' => 0.10]));
        self::assertFalse($user->hasDynamicGrid());

        $admin->saveGrid(
            'electricity',
            'Catalogue dynamique',
            new DateTimeImmutable('2026-02-01'),
            null,
            $this->lines(['energy_t1' => 0.10]),
            null,
            'BE',
            'EUR',
            true,
            21.0,
            'dynamic_hourly',
        );
        self::assertTrue($user->hasDynamicGrid());
        self::assertFalse($user->hasDynamicGrid('gas'));
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
