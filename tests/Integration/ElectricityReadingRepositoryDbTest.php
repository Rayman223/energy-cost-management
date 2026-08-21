<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ReadingGranularity;
use App\Infrastructure\MeterTopology;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Support\Dates;

/**
 * Test d'intégration de l'interpolation à minuit sur le modèle à registres
 * (port des scénarios de l'ancien test Data_Dries). S'auto-skippe sans base de test
 * joignable.
 */
final class ElectricityReadingRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new UserRepository($this->pdo());
        $this->userId = $users->create('https://iss.test', 'elec-test', 'test', 'Elec Tester')->id;

        // Seed : relevés encadrant minuit (23:00 la veille / 01:00 le lendemain)
        // → l'index à 00:00 le 1er du mois est la moyenne des deux.
        $topology = new MeterTopology($this->pdo());
        $meterId = $topology->ensureElectricityMeter($this->userId);
        $registers = $topology->ensureRegisters($meterId);

        $rows = [
            ['2025-11-30 23:00:00', 50.0],
            ['2025-12-01 01:00:00', 52.0],   // 1er déc. 00:00 → 51
            ['2025-12-31 23:00:00', 80.0],
            ['2026-01-01 01:00:00', 82.0],   // 1er janv. 00:00 → 81
            ['2026-04-30 23:00:00', 100.0],
            ['2026-05-01 01:00:00', 102.0],  // 1er mai 00:00 → 101
            ['2026-05-31 23:00:00', 200.0],
            ['2026-06-01 01:00:00', 202.0],  // 1er juin 00:00 → 201
            ['2026-06-30 23:00:00', 300.0],
            ['2026-07-01 01:00:00', 302.0],  // 1er juil. 00:00 → 301
            ['2026-07-10 12:00:00', 350.0],  // dernier relevé disponible
        ];

        $ins = $this->pdo()->prepare(
            'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)'
        );
        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2'] as $key) {
            foreach ($rows as [$ts, $v]) {
                $ins->execute(['rid' => $registers[$key], 'at' => $ts, 'val' => $v]);
            }
        }
    }

    protected function clean(): void
    {
        foreach (['meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function repo(): ElectricityReadingRepository
    {
        return new ElectricityReadingRepository($this->pdo(), $this->userId);
    }

    public function testCompletedMonthInterpolatesBothBoundariesToMidnight(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 5);

        self::assertSame('2026-05-01 00:00:00', $r['from']);
        self::assertSame('2026-06-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(100.0, $r['prelev_jour'], 0.001); // 201 - 101
    }

    public function testDecemberRollsOverToNextJanuary(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2025, 12);

        self::assertSame('2025-12-01 00:00:00', $r['from']);
        self::assertSame('2026-01-01 00:00:00', $r['to']);
        self::assertEqualsWithDelta(30.0, $r['prelev_jour'], 0.001); // 81 - 51
    }

    public function testOngoingMonthEndsAtLatestReading(): void
    {
        $r = $this->repo()->getMonthlyDeltasForMonth(2026, 7);

        self::assertSame('2026-07-01 00:00:00', $r['from']);
        self::assertSame('2026-07-10 12:00:00', $r['to']);
        self::assertEqualsWithDelta(49.0, $r['prelev_jour'], 0.001); // 350 - 301
    }

    public function testEmptyMonthReturnsEmpty(): void
    {
        self::assertSame([], $this->repo()->getMonthlyDeltasForMonth(2026, 3));
    }

    public function testDataIsScopedPerUser(): void
    {
        // Un second utilisateur, sans compteur : aucune donnée visible.
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other', 'test', 'Autre')->id;
        $other = new ElectricityReadingRepository($this->pdo(), $otherId);

        self::assertSame([], $other->getMonthlyDeltasForMonth(2026, 5));
        self::assertNull($other->getTodayIndexValues()['dries']);
    }

    public function testInsertIndexesIsIdempotent(): void
    {
        $repo = $this->repo();
        $ts = new \DateTimeImmutable('2026-07-11 08:00:00');

        $repo->insertIndexes($ts, ['import_t1' => 360.0, 'import_t2' => 360.0]);
        $repo->insertIndexes($ts, ['import_t1' => 360.0, 'import_t2' => 360.0]); // re-run : ignoré

        $stmt = $this->pdo()->query(
            "SELECT COUNT(*) FROM meter_readings WHERE reading_at = '2026-07-11 08:00:00'"
        );
        self::assertNotFalse($stmt);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testReadingBoundsSurroundBackdatedTimestamp(): void
    {
        // Date encadrée par '2026-06-01 01:00:00' (202) et '2026-06-30 23:00:00' (300).
        $bounds = $this->repo()->readingBounds(new \DateTimeImmutable('2026-06-15 10:00:00'), ['import_t1', 'production']);

        self::assertEqualsWithDelta(202.0, $bounds['import_t1']['min'], 0.001);
        self::assertEqualsWithDelta(300.0, $bounds['import_t1']['max'], 0.001);
        self::assertFalse($bounds['import_t1']['exists']);
        // 'production' n'a aucun relevé seedé → pas de borne.
        self::assertNull($bounds['production']['min']);
        self::assertNull($bounds['production']['max']);
        self::assertFalse($bounds['production']['exists']);
    }

    public function testReadingBoundsFlagsExistingTimestamp(): void
    {
        // Horodatage exact d'un relevé seedé : exists=true (bornes strictes → voisins).
        $bounds = $this->repo()->readingBounds(new \DateTimeImmutable('2026-06-01 01:00:00'), ['import_t1']);

        self::assertTrue($bounds['import_t1']['exists']);
        self::assertEqualsWithDelta(200.0, $bounds['import_t1']['min'], 0.001); // relevé strictement antérieur (2026-05-31 23:00)
        self::assertEqualsWithDelta(300.0, $bounds['import_t1']['max'], 0.001); // relevé strictement postérieur (2026-06-30 23:00)
    }

    public function testReadingBoundsOpenEndedOutsideRange(): void
    {
        // Avant tout relevé : que la borne supérieure (premier relevé, 50).
        $before = $this->repo()->readingBounds(new \DateTimeImmutable('2025-01-01 00:00:00'), ['import_t1']);
        self::assertNull($before['import_t1']['min']);
        self::assertEqualsWithDelta(50.0, $before['import_t1']['max'], 0.001);

        // Après tout relevé : que la borne inférieure (dernier relevé, 350).
        $after = $this->repo()->readingBounds(new \DateTimeImmutable('2027-01-01 00:00:00'), ['import_t1']);
        self::assertEqualsWithDelta(350.0, $after['import_t1']['min'], 0.001);
        self::assertNull($after['import_t1']['max']);
    }

    public function testReadingsPresentInDayBucketDetectsSameRegisterDifferentInstant(): void
    {
        $repo = $this->repo();
        // import_t1 possède un relevé seedé le 2026-07-10 à 12:00:00.
        $day = ReadingGranularity::Day;

        // Même registre, même jour, autre heure → présent.
        $onDay = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-10 18:00:00'), 'UTC', $day, ['import_t1', 'production']);
        self::assertTrue($onDay['import_t1']);
        self::assertFalse($onDay['production'], 'production non seedé → aucun relevé ce jour');

        // Instant exact exclu (idempotence) : pas d'« autre » relevé ce jour-là.
        $exact = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-10 12:00:00'), 'UTC', $day, ['import_t1']);
        self::assertFalse($exact['import_t1']);

        // Jour suivant → aucun relevé import_t1.
        $nextDay = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-11 08:00:00'), 'UTC', $day, ['import_t1']);
        self::assertFalse($nextDay['import_t1']);
    }

    public function testReadingsPresentInDayBucketUsesUserTimezone(): void
    {
        $repo = $this->repo();
        $day  = ReadingGranularity::Day;
        // Relevé solaire à 23:30 UTC le 10/07 → 01:30 le 11/07 en Europe/Brussels (+02).
        $repo->insertIndexes(new \DateTimeImmutable('2026-07-10 23:30:00', new \DateTimeZone('UTC')), ['production' => 12.0]);

        // Vu depuis Bruxelles, ce relevé tombe le 11/07 : un relevé à 07:00 local le
        // 11/07 le trouve donc comme « déjà présent ce jour-là ».
        $brussels = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-11 07:00:00', new \DateTimeZone('UTC')), 'Europe/Brussels', $day, ['production']);
        self::assertTrue($brussels['production']);

        // En UTC, le relevé reste le 10/07 : le jour 11/07 est vide pour production.
        $utc = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-11 07:00:00', new \DateTimeZone('UTC')), 'UTC', $day, ['production']);
        self::assertFalse($utc['production']);
    }

    public function testReadingsPresentInQuarterHourBucketAlignsToClock(): void
    {
        $repo = $this->repo();
        $quarter = ReadingGranularity::QuarterHour;
        // Relevé solaire seedé à 07:02 → créneau aligné [07:00–07:15).
        $repo->insertIndexes(new \DateTimeImmutable('2026-07-12 07:02:00', new \DateTimeZone('UTC')), ['production' => 5.0]);

        // Autre instant du même créneau → présent.
        $sameSlot = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-12 07:12:00', new \DateTimeZone('UTC')), 'UTC', $quarter, ['production']);
        self::assertTrue($sameSlot['production']);

        // Créneau suivant [07:15–07:30) → absent.
        $nextSlot = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-12 07:20:00', new \DateTimeZone('UTC')), 'UTC', $quarter, ['production']);
        self::assertFalse($nextSlot['production']);

        // Instant exact exclu (idempotence).
        $exact = $repo->readingsPresentInBucket(new \DateTimeImmutable('2026-07-12 07:02:00', new \DateTimeZone('UTC')), 'UTC', $quarter, ['production']);
        self::assertFalse($exact['production']);
    }

    public function testDailyChartGroupsByUserLocalDay(): void
    {
        // Utilisateur isolé (le seed 2025/2026 de la classe ne doit pas interférer :
        // la fenêtre du graphe est relative à « maintenant »).
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'chart-tz', 'test', 'Chart TZ')->id;
        $utc     = new \DateTimeZone('UTC');
        $tz      = new \DateTimeZone('Europe/Brussels');

        // Trois relevés à 23:30 UTC sur des jours récents consécutifs. En Europe/
        // Brussels (+01/+02), 23:30 UTC tombe le JOUR LOCAL SUIVANT (00:30 ou 01:30)
        // → le jour local diffère du jour UTC, ce qui distingue les deux regroupements.
        $r1 = (new \DateTimeImmutable('now', $utc))->modify('-6 days')->setTime(23, 30, 0);
        $r2 = (new \DateTimeImmutable('now', $utc))->modify('-5 days')->setTime(23, 30, 0);
        $r3 = (new \DateTimeImmutable('now', $utc))->modify('-4 days')->setTime(23, 30, 0);

        $repo = new ElectricityReadingRepository($this->pdo(), $otherId, 'Europe/Brussels');
        $repo->insertIndexes($r1, ['import_t1' => 100.0]);
        $repo->insertIndexes($r2, ['import_t1' => 130.0]);
        $repo->insertIndexes($r3, ['import_t1' => 175.0]);

        // Jours LOCAUX attendus (calculés avec le même fuseau → robuste au DST).
        $localDay2 = $r2->setTimezone($tz)->format('Y-m-d');
        $localDay3 = $r3->setTimezone($tz)->format('Y-m-d');
        self::assertNotSame($r2->format('Y-m-d'), $localDay2, 'pré-condition : jour UTC ≠ jour local');

        $byDay = array_column($repo->getDailyDeltasForChart(30), null, 'day');

        // Deltas rattachés au jour LOCAL du relevé le plus récent de la paire.
        self::assertArrayHasKey($localDay2, $byDay);
        self::assertEqualsWithDelta(30.0, $byDay[$localDay2]['import_t1'], 0.001);  // 130 - 100
        self::assertArrayHasKey($localDay3, $byDay);
        self::assertEqualsWithDelta(45.0, $byDay[$localDay3]['import_t1'], 0.001);  // 175 - 130
        // Le jour UTC de r2 (la veille du jour local) n'est PAS une clé → regroupement local.
        self::assertArrayNotHasKey($r2->format('Y-m-d'), $byDay);

        // Contraste : le même relevé, vu par un repo en UTC, se regroupe sur le jour UTC.
        $utcRepo  = new ElectricityReadingRepository($this->pdo(), $otherId, 'UTC');
        $utcByDay = array_column($utcRepo->getDailyDeltasForChart(30), null, 'day');
        self::assertArrayHasKey($r2->format('Y-m-d'), $utcByDay);
        self::assertArrayNotHasKey($localDay3, $utcByDay);
    }

    public function testDailyChartKeepsSolarOnDaysWithoutImportReadings(): void
    {
        // Régression #180 : la production solaire relevée des jours DIFFÉRENTS des index
        // de consommation ne doit plus être tronquée. Avant le fix, l'axe des jours était
        // bâti sur les seuls jours d'import_t1 → les jours solaires disjoints disparaissaient.
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'chart-solar', 'test', 'Chart Solar')->id;
        $utc     = new \DateTimeZone('UTC');

        // import_t1 relevé sur J-6 et J-5 ; production sur J-4 et J-3 (jours disjoints).
        // Instants à midi UTC pour éviter tout basculement de jour local (Europe/Brussels).
        $imp1 = (new \DateTimeImmutable('now', $utc))->modify('-6 days')->setTime(12, 0, 0);
        $imp2 = (new \DateTimeImmutable('now', $utc))->modify('-5 days')->setTime(12, 0, 0);
        $sol1 = (new \DateTimeImmutable('now', $utc))->modify('-4 days')->setTime(12, 0, 0);
        $sol2 = (new \DateTimeImmutable('now', $utc))->modify('-3 days')->setTime(12, 0, 0);

        $repo = new ElectricityReadingRepository($this->pdo(), $otherId, 'Europe/Brussels');
        $repo->insertIndexes($imp1, ['import_t1' => 100.0]);
        $repo->insertIndexes($imp2, ['import_t1' => 130.0]);
        $repo->insertIndexes($sol1, ['production' => 40.0]);
        $repo->insertIndexes($sol2, ['production' => 55.0]);

        $tz       = new \DateTimeZone('Europe/Brussels');
        $impDay   = $imp2->setTimezone($tz)->format('Y-m-d'); // delta import rattaché à J-5
        $solDay   = $sol2->setTimezone($tz)->format('Y-m-d'); // delta solaire rattaché à J-3

        $chart = $repo->getDailyDeltasForChart(30);
        $byDay = array_column($chart, null, 'day');

        // Jour import : delta import présent, solaire null (aucun relevé PV ce jour-là).
        self::assertArrayHasKey($impDay, $byDay);
        self::assertEqualsWithDelta(30.0, $byDay[$impDay]['import_t1'], 0.001); // 130 - 100
        self::assertNull($byDay[$impDay]['solar']);

        // Jour solaire (fix #180) : conservé bien qu'aucun import ne le couvre.
        self::assertArrayHasKey($solDay, $byDay);
        self::assertEqualsWithDelta(15.0, $byDay[$solDay]['solar'], 0.001); // 55 - 40
        self::assertEqualsWithDelta(0.0, $byDay[$solDay]['import_t1'], 0.001);

        // Les jours restent triés chronologiquement (import avant solaire).
        $days   = array_column($chart, 'day');
        $sorted = $days;
        sort($sorted);
        self::assertSame($sorted, $days);
        self::assertSame([$impDay, $solDay], $days);
    }

    public function testMonthlySeriesAggregatesConsumptionPerCalendarMonth(): void
    {
        // Vue « 1 an » du graphe (#238) : 12 points mensuels, calculés sur la
        // consommation (bornes interpolées à minuit) et non sur l'index brut.
        $series = $this->repo()->getMonthlyDeltaSeries(12, new \DateTimeImmutable('2026-07-15 00:00:00', new \DateTimeZone('UTC')));
        $byMonth = array_column($series, null, 'month');

        // Fenêtre : août 2025 → juillet 2026, tronquée aux mois couverts par les
        // relevés (le premier tombe le 30 nov. 2025 à 23:00).
        self::assertSame(
            ['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'],
            array_column($series, 'month'),
        );

        // Décembre 2025 : 81 − 51, exactement la valeur de la card mensuelle.
        self::assertEqualsWithDelta(30.0, $byMonth['2025-12']['import_t1'], 0.001);
        self::assertEqualsWithDelta(30.0, $byMonth['2025-12']['export_t1'], 0.001);
        self::assertFalse($byMonth['2025-12']['partial']);

        // Mai 2026 : 201 − 101, idem getMonthlyDeltasForMonth(2026, 5).
        self::assertEqualsWithDelta(100.0, $byMonth['2026-05']['import_t1'], 0.001);
        self::assertEqualsWithDelta(
            $this->repo()->getMonthlyDeltasForMonth(2026, 5)['prelev_jour'],
            $byMonth['2026-05']['import_t1'],
            0.001,
        );

        // Aucun registre « production » sur ce compteur → solaire absent.
        self::assertNull($byMonth['2026-05']['solar']);
    }

    public function testMonthlySeriesClampsCurrentMonthOnLastReadingWithoutProjecting(): void
    {
        // Juillet 2026 : dernier relevé le 10 à 12:00 (350). La barre vaut la conso
        // réelle (350 − 301 = 49) et non une projection à fin de mois.
        $series  = $this->repo()->getMonthlyDeltaSeries(3, new \DateTimeImmutable('2026-07-15 00:00:00', new \DateTimeZone('UTC')));
        $byMonth = array_column($series, null, 'month');

        self::assertEqualsWithDelta(49.0, $byMonth['2026-07']['import_t1'], 0.001);
        self::assertTrue($byMonth['2026-07']['partial']);
        self::assertFalse($byMonth['2026-06']['partial']);
    }

    public function testMonthlySeriesIncludesSolarWhenTheRegisterIsFed(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'series-solar', 'test', 'Série PV')->id;
        $utc     = new \DateTimeZone('UTC');
        $repo    = new ElectricityReadingRepository($this->pdo(), $otherId);

        $repo->insertIndexes(new \DateTimeImmutable('2026-02-01 00:00:00', $utc), ['import_t1' => 100.0, 'production' => 10.0]);
        $repo->insertIndexes(new \DateTimeImmutable('2026-03-01 00:00:00', $utc), ['import_t1' => 160.0, 'production' => 40.0]);

        $byMonth = array_column(
            $repo->getMonthlyDeltaSeries(2, new \DateTimeImmutable('2026-03-05 00:00:00', $utc)),
            null,
            'month',
        );

        self::assertEqualsWithDelta(60.0, $byMonth['2026-02']['import_t1'], 0.001);
        self::assertEqualsWithDelta(30.0, $byMonth['2026-02']['solar'], 0.001);
    }

    public function testMonthlySeriesIsScopedPerUserAndEmptyWithoutReadings(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'series-scope', 'test', 'Série')->id;

        self::assertSame([], (new ElectricityReadingRepository($this->pdo(), $otherId))->getMonthlyDeltaSeries(12));
    }

    public function testTodayIndexUsesUserLocalDay(): void
    {
        // Utilisateur isolé pour ne pas mélanger avec le seed 2025/2026 de la classe.
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'today-tz', 'test', 'Today TZ')->id;
        $utc     = new \DateTimeZone('UTC');

        // Relevé pris juste après minuit LOCAL (Europe/Brussels) : en UTC il tombe la
        // veille (+01/+02). Il doit être vu comme « relevé du jour » côté fuseau local.
        $justAfterLocalMidnight = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))
            ->setTime(0, 30, 0)
            ->setTimezone($utc);
        self::assertNotSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->format('Y-m-d'),
            $justAfterLocalMidnight->format('Y-m-d'),
            'pré-condition : le relevé est la veille en UTC',
        );

        $repo = new ElectricityReadingRepository($this->pdo(), $otherId, 'Europe/Brussels');
        $repo->insertIndexes($justAfterLocalMidnight, ['import_t1' => 123.0]);

        // Le relevé est capté par la fenêtre du jour LOCAL (et non rejeté comme « pas
        // aujourd'hui » ) : getTodayIndexValues expose son index.
        $today = $repo->getTodayIndexValues();
        self::assertNotNull($today['dries']);
        self::assertEqualsWithDelta(123.0, $today['dries']['Prelev_jour'], 0.001);
        self::assertSame(Dates::toDbString($justAfterLocalMidnight), $today['dries']['timestamp']);
    }

    public function testGetHistoryIncludesReadingsOlderThan30Days(): void
    {
        $rows = $this->repo()->getHistory();
        $timestamps = array_column($rows, 'reading_at');

        // '2025-11-30 23:00:00' est antidaté de > 30 jours : masqué par l'ancienne
        // fenêtre glissante, désormais visible (borne par nombre, pas par date).
        self::assertContains('2025-11-30 23:00:00', $timestamps);
        self::assertContains('2026-07-10 12:00:00', $timestamps);
        // 11 horodatages distincts seedés, tous sous la limite par défaut.
        self::assertCount(11, $rows);
        // DESC : plus récent d'abord.
        self::assertSame('2026-07-10 12:00:00', $timestamps[0]);
        // Pivot par registre renseigné (import_t1 = 350.0 au dernier relevé).
        self::assertEqualsWithDelta(350.0, $rows[0]['import_t1'], 0.001);
    }

    public function testGetHistoryCapsToMostRecentTimestamps(): void
    {
        $rows = $this->repo()->getHistory(3);

        self::assertCount(3, $rows);
        self::assertSame(
            ['2026-07-10 12:00:00', '2026-07-01 01:00:00', '2026-06-30 23:00:00'],
            array_column($rows, 'reading_at'),
        );
    }

    // ── Pagination de l'historique (#257) ───────────────────────────────────

    public function testCountHistoryCountsDistinctTimestamps(): void
    {
        self::assertSame(11, $this->repo()->countHistory());
    }

    public function testCountHistoryIsZeroWithoutRegisters(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'no-meter', 'test', 'Sans compteur')->id;

        self::assertSame(0, (new ElectricityReadingRepository($this->pdo(), $otherId))->countHistory());
    }

    public function testGetHistoryPageWalksBackToTheOldestReadings(): void
    {
        $all = array_column($this->repo()->getHistory(), 'reading_at');

        $page2 = $this->repo()->getHistoryPage(4, 4);
        self::assertSame(array_slice($all, 4, 4), array_column($page2['items'], 'reading_at'));

        $lastPage = $this->repo()->getHistoryPage(4, 8);
        self::assertCount(3, $lastPage['items'], 'dernière page partielle');
        self::assertSame('2025-11-30 23:00:00', $lastPage['items'][2]['reading_at']);
    }

    /**
     * `previous` porte le relevé immédiatement plus ancien que la page — hors
     * liste — pour que le client calcule le delta de la dernière ligne affichée.
     */
    public function testGetHistoryPageExposesTheReadingBeforeThePage(): void
    {
        $all = array_column($this->repo()->getHistory(), 'reading_at');

        $page = $this->repo()->getHistoryPage(4, 0);
        self::assertNotNull($page['previous']);
        self::assertSame($all[4], $page['previous']['reading_at']);

        $lastPage = $this->repo()->getHistoryPage(4, 8);
        self::assertNull($lastPage['previous'], 'aucun relevé plus ancien que la dernière page');
    }

    public function testGetHistoryPageBeyondTheEndIsEmpty(): void
    {
        $page = $this->repo()->getHistoryPage(25, 100);

        self::assertSame([], $page['items']);
        self::assertNull($page['previous']);
    }

    public function testInsertIndexesReplaceOverwritesExistingIndex(): void
    {
        $repo = $this->repo();
        $ts   = new \DateTimeImmutable('2026-07-10 12:00:00'); // horodatage seedé (import_t1 = 350)

        // Sans replace : doublon ignoré, valeur inchangée.
        self::assertSame(0, $repo->insertIndexes($ts, ['import_t1' => 999.0]));
        self::assertEqualsWithDelta(350.0, $this->repo()->getHistory(1)[0]['import_t1'], 0.001);

        // Avec replace : la valeur est écrasée.
        self::assertSame(1, $repo->insertIndexes($ts, ['import_t1' => 999.0], true));
        self::assertEqualsWithDelta(999.0, $this->repo()->getHistory(1)[0]['import_t1'], 0.001);
    }

    public function testDeleteReadingAtRemovesAllRegistersAtThatTimestamp(): void
    {
        $repo = $this->repo();

        // 4 registres seedés à cet horodatage.
        $deleted = $repo->deleteReadingAt(new \DateTimeImmutable('2026-07-10 12:00:00'));
        self::assertSame(4, $deleted);

        $timestamps = array_column($this->repo()->getHistory(), 'reading_at');
        self::assertNotContains('2026-07-10 12:00:00', $timestamps);
        self::assertContains('2026-07-01 01:00:00', $timestamps); // les autres restent
    }

    public function testDeleteReadingAtIsScopedPerUser(): void
    {
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'other-del', 'test', 'Autre')->id;
        $other   = new ElectricityReadingRepository($this->pdo(), $otherId);

        // L'autre utilisateur (sans compteur) ne supprime rien de mes relevés.
        self::assertSame(0, $other->deleteReadingAt(new \DateTimeImmutable('2026-07-10 12:00:00')));
        self::assertContains('2026-07-10 12:00:00', array_column($this->repo()->getHistory(), 'reading_at'));
    }

    public function testDeleteMeterCascadesRegistersAndReadings(): void
    {
        $repo = $this->repo();

        self::assertSame(1, $repo->deleteMeter());

        // Compteur, registres et relevés partis en cascade.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meters')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_registers')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn());

        // Un nouvel insertIndexes recrée compteur/registres paresseusement.
        $repo->insertIndexes(new \DateTimeImmutable('2026-08-01 00:00:00'), ['import_t1' => 400.0]);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM meters')->fetchColumn());
        self::assertCount(1, $this->repo()->getHistory());
    }

    /**
     * Tarif dynamique quart-horaire (#230) : des relevés au pas de 15 min donnent
     * un créneau par MTU, chacun portant exactement le delta mesuré, et tous marqués
     * natifs — c'est ce drapeau qui autorise le service à facturer au quart d'heure.
     */
    public function testQuarterImportDeltasKeepEachMeasuredQuarter(): void
    {
        $this->seedImportIndexes([
            ['2026-08-01 10:00:00', 100.0],
            ['2026-08-01 10:15:00', 101.0],
            ['2026-08-01 10:30:00', 103.0],
            ['2026-08-01 10:45:00', 106.0],
            ['2026-08-01 11:00:00', 110.0],
        ]);

        $rows = $this->repo()->getQuarterImportDeltas(
            new \DateTimeImmutable('2026-08-01 10:00:00'),
            new \DateTimeImmutable('2026-08-01 11:00:00'),
        );

        self::assertSame(
            ['2026-08-01 10:00:00', '2026-08-01 10:15:00', '2026-08-01 10:30:00', '2026-08-01 10:45:00'],
            array_column($rows, 'quarter'),
        );
        // Deltas 1 / 2 / 3 / 4 kWh, doublés par la somme des deux registres import.
        self::assertSame([2.0, 4.0, 6.0, 8.0], array_column($rows, 'import_kwh'));
        self::assertSame([true, true, true, true], array_column($rows, 'native'));
    }

    /**
     * Relevés horaires : faute de mesure intra-horaire, le delta est étalé au prorata
     * du temps sur les quatre quarts, et AUCUN n'est natif — l'imputer entièrement au
     * quart :00 le ferait facturer au prix d'un seul MTU.
     */
    public function testQuarterImportDeltasSpreadHourlyReadingsAndMarkThemEstimated(): void
    {
        $this->seedImportIndexes([
            ['2026-08-01 10:00:00', 100.0],
            ['2026-08-01 11:00:00', 104.0],
        ]);

        $rows = $this->repo()->getQuarterImportDeltas(
            new \DateTimeImmutable('2026-08-01 10:00:00'),
            new \DateTimeImmutable('2026-08-01 11:00:00'),
        );

        self::assertCount(4, $rows);
        self::assertSame([2.0, 2.0, 2.0, 2.0], array_column($rows, 'import_kwh'));
        self::assertSame([false, false, false, false], array_column($rows, 'native'));
    }

    /**
     * `reading_at` est stocké tel que l'émetteur l'envoie : un compteur qui vise les
     * 15 min dérive de quelques secondes à chaque trame. Ce jitter ne doit pas faire
     * passer un flux réellement quart-horaire pour une reconstruction — sinon le mode
     * 15 min ne s'engagerait quasiment jamais en production, et l'écran annoncerait à
     * tort que les relevés ne sont pas au bon pas.
     */
    public function testQuarterImportDeltasToleratesReadingJitter(): void
    {
        $this->seedImportIndexes([
            ['2026-08-01 10:00:00', 100.0],
            ['2026-08-01 10:15:02', 101.0],
            ['2026-08-01 10:30:01', 102.0],
            ['2026-08-01 10:45:03', 103.0],
            ['2026-08-01 11:00:02', 104.0],
        ]);

        $rows = $this->repo()->getQuarterImportDeltas(
            new \DateTimeImmutable('2026-08-01 10:00:00'),
            new \DateTimeImmutable('2026-08-01 11:00:02'),
        );

        self::assertNotContains(false, array_column($rows, 'native'));
        // Chaque MTU garde ses 2 kWh à quelques millièmes près : seule la fraction de
        // seconde qui déborde passe au créneau suivant.
        foreach (['2026-08-01 10:00:00', '2026-08-01 10:15:00', '2026-08-01 10:30:00', '2026-08-01 10:45:00'] as $slot) {
            $row = array_column($rows, 'import_kwh', 'quarter')[$slot] ?? null;
            self::assertNotNull($row, "Créneau {$slot} absent.");
            self::assertEqualsWithDelta(2.0, $row, 0.02);
        }
    }

    /**
     * Relevés cadencés à 15 min mais décalés (poller à :07/:22/:37/:52) : la cadence
     * est bien quart-horaire, mais chaque intervalle chevauche deux MTU. Imputer le
     * tout au créneau de départ facturerait la moitié de l'énergie au prix d'un quart
     * d'heure qu'elle n'a pas consommé.
     */
    public function testQuarterImportDeltasSplitsOffsetButQuarterlyReadings(): void
    {
        $this->seedImportIndexes([
            ['2026-08-01 10:07:00', 100.0],
            ['2026-08-01 10:22:00', 102.0],
        ]);

        $rows = $this->repo()->getQuarterImportDeltas(
            new \DateTimeImmutable('2026-08-01 10:07:00'),
            new \DateTimeImmutable('2026-08-01 10:22:00'),
        );

        self::assertSame(['2026-08-01 10:00:00', '2026-08-01 10:15:00'], array_column($rows, 'quarter'));
        self::assertSame([true, true], array_column($rows, 'native'));
        // 4 kWh répartis 8 min / 7 min entre les deux MTU couverts.
        self::assertEqualsWithDelta(4.0 * 8 / 15, $rows[0]['import_kwh'], 0.001);
        self::assertEqualsWithDelta(4.0 * 7 / 15, $rows[1]['import_kwh'], 0.001);
    }

    /**
     * Relevés import (T1 + T2 au même instant, valeurs identiques) pour les scénarios
     * quart-horaires, sur une fenêtre disjointe du seed de setUp().
     *
     * @param list<array{string, float}> $rows
     */
    private function seedImportIndexes(array $rows): void
    {
        $topology  = new MeterTopology($this->pdo());
        $registers = $topology->ensureRegisters($topology->ensureElectricityMeter($this->userId));

        $ins = $this->pdo()->prepare(
            'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)'
        );
        foreach (['import_t1', 'import_t2'] as $key) {
            foreach ($rows as [$ts, $value]) {
                $ins->execute(['rid' => $registers[$key], 'at' => $ts, 'val' => $value]);
            }
        }
    }
}
