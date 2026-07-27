<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\DynamicPriceRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration des lectures de `dynamic_prices` : la séparation des deux
 * résolutions natives (PT60M / PT15M) ne tient qu'au SQL — un `resolution_min`
 * oublié dans un WHERE ferait servir des points 15 min comme prix horaire, sans
 * qu'aucun test unitaire ne le voie. S'auto-skippe sans base ; refuse une base
 * dont le nom ne contient pas « test ».
 */
final class DynamicPriceRepositoryDbTest extends TestCase
{
    private const ZONE = '10YBE----------2';

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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
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
        $this->pdo()->exec('DELETE FROM dynamic_prices');
    }

    private function repo(): DynamicPriceRepository
    {
        return new DynamicPriceRepository($this->pdo(), self::ZONE);
    }

    /**
     * Les deux résolutions coexistent pour la même heure (la clé unique inclut
     * `resolution_min`) et chaque lecture ne voit que la sienne : le prix horaire
     * natif d'ENTSO-E n'est pas la moyenne de ses quarts, les confondre fausserait
     * silencieusement les deux modes de tarification.
     */
    public function testQuarterAndHourlyPricesAreServedSeparately(): void
    {
        $repo = $this->repo();
        $repo->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 10:00:00'), 'resolution_min' => 60, 'price_eur_kwh' => 0.20],
        ], 'entsoe');
        $repo->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 11:00:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.10],
            ['period_start' => new DateTimeImmutable('2026-08-01 11:15:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.30],
            ['period_start' => new DateTimeImmutable('2026-08-01 11:30:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.40],
            ['period_start' => new DateTimeImmutable('2026-08-01 11:45:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.50],
        ], 'entsoe');

        $from = new DateTimeImmutable('2026-08-01 00:00:00');
        $to   = new DateTimeImmutable('2026-08-02 00:00:00');

        self::assertSame(['2026-08-01 10:00:00' => 0.20], $repo->getHourlyPrices($from, $to));
        self::assertSame([
            '2026-08-01 11:00:00' => 0.10,
            '2026-08-01 11:15:00' => 0.30,
            '2026-08-01 11:30:00' => 0.40,
            '2026-08-01 11:45:00' => 0.50,
        ], $repo->getQuarterPrices($from, $to));

        // La moyenne horaire, elle, agrège toutes résolutions confondues.
        self::assertEqualsWithDelta(0.325, $repo->getAveragePriceByHour($from, $to)['2026-08-01 11:00:00'], 0.0001);
    }

    /** Bornes [from, to[ : la borne haute est exclue, comme pour les prix horaires. */
    public function testQuarterPricesRespectHalfOpenRange(): void
    {
        $repo = $this->repo();
        $repo->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 09:45:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.11],
            ['period_start' => new DateTimeImmutable('2026-08-01 10:00:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.22],
        ], 'entsoe');

        $prices = $repo->getQuarterPrices(
            new DateTimeImmutable('2026-08-01 09:45:00'),
            new DateTimeImmutable('2026-08-01 10:00:00'),
        );

        self::assertSame(['2026-08-01 09:45:00' => 0.11], $prices);
    }

    /**
     * Bascule de résolution (rollout MTU 15 min) : écrire un point 15 min à un
     * horodatage déjà couvert par une ligne 60 min purge cette dernière. Sans quoi
     * `getHourlyPrices()` continuerait à servir un prix horaire périmé comme s'il
     * était le prix natif du moment.
     */
    public function testWritingAQuarterPointPurgesTheStaleHourlyRow(): void
    {
        $repo = $this->repo();
        $repo->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 10:00:00'), 'resolution_min' => 60, 'price_eur_kwh' => 0.20],
        ], 'entsoe');
        $repo->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 10:00:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.05],
        ], 'entsoe');

        $from = new DateTimeImmutable('2026-08-01 00:00:00');
        $to   = new DateTimeImmutable('2026-08-02 00:00:00');

        self::assertSame([], $repo->getHourlyPrices($from, $to));
        self::assertSame(['2026-08-01 10:00:00' => 0.05], $repo->getQuarterPrices($from, $to));
    }

    /** La lecture est scopée par zone de marché : une autre zone ne fuit pas. */
    public function testQuarterPricesAreScopedToTheBiddingZone(): void
    {
        $this->repo()->upsertPrices([
            ['period_start' => new DateTimeImmutable('2026-08-01 10:00:00'), 'resolution_min' => 15, 'price_eur_kwh' => 0.15],
        ], 'entsoe');

        $other = new DynamicPriceRepository($this->pdo(), '10YNL----------L');

        self::assertSame([], $other->getQuarterPrices(
            new DateTimeImmutable('2026-08-01 00:00:00'),
            new DateTimeImmutable('2026-08-02 00:00:00'),
        ));
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
