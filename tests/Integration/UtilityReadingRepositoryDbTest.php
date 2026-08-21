<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;

/**
 * Test d'intégration du repository unifié gaz/eau (utility_readings), scopé
 * par utilisateur. S'auto-skippe sans base de test joignable.
 */
final class UtilityReadingRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'util-test', 'test', 'Util Tester')->id;
    }

    protected function clean(): void
    {
        foreach (['utility_readings', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testSaveLatestAndDeltas(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 112.5);

        $latest = $gas->getLatest();
        self::assertNotNull($latest);
        self::assertSame(112.5, (float) $latest['counter_m3']);

        $all = $gas->getAllReadings();
        self::assertCount(2, $all);
        self::assertSame(12.5, $all[0]['delta_m3']);  // plus récent d'abord
        self::assertNull($all[1]['delta_m3']);

        $lastTwo = $gas->getLastTwoReadings();
        self::assertSame(100.0, (float) $lastTwo['from']['counter_m3']);
        self::assertSame(112.5, (float) $lastTwo['to']['counter_m3']);
    }

    public function testGasAndWaterAreIsolatedFlows(): void
    {
        $gas   = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);

        self::assertCount(1, $gas->getAllReadings());
        self::assertCount(1, $water->getAllReadings());
        self::assertSame(100.0, (float) ($gas->getLatest()['counter_m3'] ?? 0));
        self::assertSame(40.0, (float) ($water->getLatest()['counter_m3'] ?? 0));
    }

    public function testDataIsScopedPerUser(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other', 'test', 'Autre')->id;

        $mine  = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $their = new UtilityReadingRepository($this->pdo(), $otherId, 'gas');

        $mine->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);

        self::assertCount(1, $mine->getAllReadings());
        self::assertSame([], $their->getAllReadings());
        self::assertNull($their->getLatest());

        // Même horodatage autorisé pour deux utilisateurs distincts (UNIQUE composite).
        $their->save(new DateTimeImmutable('2026-06-01 10:00:00'), 55.0);
        self::assertCount(1, $their->getAllReadings());
    }

    public function testReadingBeforeAndAfterAroundBackdatedDate(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        // Date encadrée par les deux relevés existants.
        $ts = new DateTimeImmutable('2026-06-15 10:00:00');
        $before = $gas->getReadingBefore($ts);
        $after  = $gas->getReadingAfter($ts);
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(100.0, (float) $before['counter_m3']);
        self::assertSame(150.0, (float) $after['counter_m3']);

        // Horodatage exact : bornes inclusives → renvoie ce même relevé des deux côtés.
        $exact = new DateTimeImmutable('2026-06-01 10:00:00');
        self::assertSame('2026-06-01 10:00:00', $gas->getReadingBefore($exact)['reading_at']);
        self::assertSame('2026-06-01 10:00:00', $gas->getReadingAfter($exact)['reading_at']);

        // Hors plage : pas de voisin de ce côté.
        self::assertNull($gas->getReadingBefore(new DateTimeImmutable('2026-05-01 10:00:00')));
        self::assertNull($gas->getReadingAfter(new DateTimeImmutable('2026-08-01 10:00:00')));
    }

    public function testInterpolationWindow(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        $gas->save(new DateTimeImmutable('2026-05-20 10:00:00'), 90.0);   // avant le mois
        $gas->save(new DateTimeImmutable('2026-06-10 10:00:00'), 100.0);  // dans le mois
        $gas->save(new DateTimeImmutable('2026-07-05 10:00:00'), 110.0);  // après le mois

        $window = $gas->getReadingsForInterpolation(2026, 6);

        self::assertCount(3, $window);
        self::assertSame('2026-05-20 10:00:00', $window[0]['reading_at']);
        self::assertSame('2026-07-05 10:00:00', $window[2]['reading_at']);
    }

    public function testSaveIgnoreReplaceOverwritesExistingValue(): void
    {
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $ts    = new DateTimeImmutable('2026-06-01 10:00:00');

        // Import fautif (mauvaise unité), puis correction avec « écraser ».
        self::assertTrue($water->saveIgnore($ts, 100.0));
        self::assertFalse($water->saveIgnore($ts, 10.0), 'sans replace : doublon ignoré');
        self::assertSame(100.0, (float) $water->getLatest()['counter_m3']);

        self::assertTrue($water->saveIgnore($ts, 10.0, true), 'avec replace : valeur mise à jour');
        self::assertSame(10.0, (float) $water->getLatest()['counter_m3']);
        self::assertCount(1, $water->getAllReadings(), 'aucun doublon créé');

        // Réécrire la même valeur ne touche aucune ligne.
        self::assertFalse($water->saveIgnore($ts, 10.0, true));
    }

    public function testDeleteReadingRemovesOnlyTargetRow(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        $all = $gas->getAllReadings();
        $targetId = $all[0]['id']; // plus récent (150.0)

        self::assertTrue($gas->deleteReading($targetId));
        $remaining = $gas->getAllReadings();
        self::assertCount(1, $remaining);
        self::assertSame(100.0, $remaining[0]['counter_m3']);

        // Supprimer un id déjà parti est un no-op.
        self::assertFalse($gas->deleteReading($targetId));
    }

    public function testDeleteReadingIsScopedPerUserAndFluid(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other-del', 'test', 'Autre')->id;

        $mineGas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $mineGas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $mineId = $mineGas->getAllReadings()[0]['id'];

        // Un autre utilisateur ne peut pas supprimer mon relevé.
        $theirGas = new UtilityReadingRepository($this->pdo(), $otherId, 'gas');
        self::assertFalse($theirGas->deleteReading($mineId));

        // L'autre fluide (eau) du même utilisateur ne peut pas non plus.
        $mineWater = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        self::assertFalse($mineWater->deleteReading($mineId));

        // Le bon repo, lui, supprime bien.
        self::assertTrue($mineGas->deleteReading($mineId));
    }

    public function testDeleteAllClearsOnlyThatFluid(): void
    {
        $gas   = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);

        self::assertSame(2, $gas->deleteAll());
        self::assertSame([], $gas->getAllReadings());
        self::assertCount(1, $water->getAllReadings(), 'l\'eau est intacte');
    }

    // ── Pagination de l'historique (#257) ───────────────────────────────────

    /** Enregistre $count relevés journaliers, index croissant de 10 en 10. */
    private function seedGas(int $count): UtilityReadingRepository
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        for ($day = 1; $day <= $count; $day++) {
            $gas->save(new DateTimeImmutable(sprintf('2026-06-%02d 10:00:00', $day)), 100.0 + 10.0 * $day);
        }

        return $gas;
    }

    public function testCountReadingsIsScopedToTheFlow(): void
    {
        $gas   = $this->seedGas(3);
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);

        self::assertSame(3, $gas->countReadings());
        self::assertSame(1, $water->countReadings());
    }

    public function testGetReadingsPageSlicesFromMostRecent(): void
    {
        $gas = $this->seedGas(7);

        $page = $gas->getReadingsPage(3, 0);
        self::assertCount(3, $page);
        self::assertSame('2026-06-07 10:00:00', $page[0]['reading_at']);
        self::assertSame('2026-06-05 10:00:00', $page[2]['reading_at']);

        $last = $gas->getReadingsPage(3, 6);
        self::assertCount(1, $last, 'dernière page partielle');
        self::assertSame('2026-06-01 10:00:00', $last[0]['reading_at']);
        self::assertNull($last[0]['delta_m3'], 'le tout premier relevé n\'a pas de précédent');
    }

    /**
     * Le delta de la ligne de frontière doit être celui de l'historique complet :
     * c'est ce que garantit la lecture d'une ligne de plus que la page.
     */
    public function testPageDeltasMatchFullHistory(): void
    {
        $gas = $this->seedGas(7);
        $all = $gas->getAllReadings();

        $paginated = [];
        foreach ([0, 3, 6] as $offset) {
            foreach ($gas->getReadingsPage(3, $offset) as $row) {
                $paginated[] = $row;
            }
        }

        self::assertSame($all, $paginated);
        self::assertSame(10.0, $paginated[2]['delta_m3'], 'dernière ligne de la page 1');
    }

    public function testGetReadingsPageBeyondTheEndIsEmpty(): void
    {
        self::assertSame([], $this->seedGas(3)->getReadingsPage(25, 100));
    }
}
