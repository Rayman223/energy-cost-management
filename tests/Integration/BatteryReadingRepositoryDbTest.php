<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Battery;
use App\Http\Controller\BatteryReadingController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\BatteryReadingRepository;
use App\Repository\BatteryRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Index de batterie (#26) : repository et contrôleur de saisie, contre une vraie
 * base. S'auto-skippe sans base de test joignable.
 *
 * Trois propriétés portent tout le reste et ne se vérifient qu'en base :
 *  - la COMPLÉTION d'un compteur laissé vide (deux colonnes nullables sur une
 *    même ligne, un `ON DUPLICATE KEY UPDATE` avec COALESCE) ;
 *  - les BORNES de croissance calculées compteur par compteur, en sautant les
 *    NULL de l'autre colonne ;
 *  - le double scope tenant : ni un identifiant de batterie deviné, ni un id de
 *    relevé étranger ne doivent toucher les données d'autrui.
 */
final class BatteryReadingRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    private int $otherUserId = 0;

    private int $batteryId = 0;

    private int $foreignBatteryId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'bat-idx-owner', 'test', 'Battery Owner')->id;
        $this->otherUserId = $users->create('https://iss.test', 'bat-idx-other', 'test', 'Other Owner')->id;

        $this->batteryId        = (new BatteryRepository($this->pdo(), $this->userId))->insert($this->draft());
        $this->foreignBatteryId = (new BatteryRepository($this->pdo(), $this->otherUserId))->insert($this->draft());
    }

    protected function clean(): void
    {
        foreach (['battery_readings', 'batteries', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function draft(): Battery
    {
        return new Battery(
            id: 0,
            brand: 'BYD',
            model: 'HVS 10.2',
            capacityKwh: 10.24,
            commissionedOn: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        );
    }

    private function repo(?int $batteryId = null, ?int $userId = null): BatteryReadingRepository
    {
        return new BatteryReadingRepository($this->pdo(), $userId ?? $this->userId, $batteryId ?? $this->batteryId);
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    // ── Écriture ───────────────────────────────────────────────────────────

    public function testWritesBothCountersAndCountsThem(): void
    {
        $repo = $this->repo();

        self::assertSame(2, $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0, 'discharge' => 1000.0]));

        $rows = $repo->getReadingsPage(10, 0);
        self::assertCount(1, $rows);
        self::assertSame(1200.0, $rows[0]['charge']);
        self::assertSame(1000.0, $rows[0]['discharge']);
    }

    public function testRewritingTheSameValuesWritesNothing(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0, 'discharge' => 1000.0]);

        self::assertSame(0, $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0, 'discharge' => 1000.0]));
        self::assertSame(1, $repo->countReadings());
    }

    /**
     * Onduleur n'exposant qu'un compteur à la fois : la seconde écriture complète
     * la colonne restée vide sans toucher à l'autre. Sans cela, la moitié de
     * l'historique d'une telle installation serait silencieusement perdue.
     */
    public function testASecondWriteFillsTheEmptyCounterWithoutTouchingTheOther(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['discharge' => 1000.0]);

        self::assertSame(1, $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]));

        $rows = $repo->getReadingsPage(10, 0);
        self::assertSame(1200.0, $rows[0]['charge']);
        self::assertSame(1000.0, $rows[0]['discharge']);
    }

    /** Une valeur déjà posée n'est jamais écrasée en silence. */
    public function testAnExistingValueIsKeptUnlessReplaceIsAsked(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]);

        self::assertSame(0, $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 999.0]));
        self::assertSame(1200.0, $repo->getReadingsPage(10, 0)[0]['charge']);

        self::assertSame(1, $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 999.0], true));
        self::assertSame(999.0, $repo->getReadingsPage(10, 0)[0]['charge']);
    }

    /**
     * Le mode écrasement ne doit corriger QUE les compteurs soumis : un compteur
     * absent de la requête arrive à NULL, et l'effacer perdrait une donnée que
     * l'utilisateur n'a jamais demandé à toucher.
     */
    public function testReplaceDoesNotWipeACounterAbsentFromTheSubmission(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0, 'discharge' => 1000.0]);

        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1250.0], true);

        $rows = $repo->getReadingsPage(10, 0);
        self::assertSame(1250.0, $rows[0]['charge']);
        self::assertSame(1000.0, $rows[0]['discharge'], 'la décharge, non soumise, a été effacée');
    }

    // ── Bornes et deltas ───────────────────────────────────────────────────

    /**
     * Les bornes se prennent compteur par compteur, en sautant les NULL de l'autre
     * colonne. Sans ce filtre, le voisin le plus proche serait « NULL » et la
     * validation de croissance laisserait passer n'importe quelle valeur.
     */
    public function testBoundsSkipRowsWhereTheCounterIsNull(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1000.0, 'discharge' => 800.0]);
        $repo->insertIndexes($this->at('2026-06-02 08:00:00'), ['charge' => 1050.0]); // pas de décharge ce jour-là
        $repo->insertIndexes($this->at('2026-06-03 08:00:00'), ['charge' => 1100.0, 'discharge' => 900.0]);

        $bounds = $repo->readingBounds($this->at('2026-06-02 12:00:00'), ['charge', 'discharge']);

        self::assertSame(1050.0, $bounds['charge']['min']);
        self::assertSame(1100.0, $bounds['charge']['max']);
        // La décharge saute la ligne du 2 : ses bornes restent celles du 1er et du 3.
        self::assertSame(800.0, $bounds['discharge']['min']);
        self::assertSame(900.0, $bounds['discharge']['max']);
    }

    public function testExistsIsPerCounterNotPerRow(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1000.0]);

        $bounds = $repo->readingBounds($this->at('2026-06-01 08:00:00'), ['charge', 'discharge']);

        self::assertTrue($bounds['charge']['exists']);
        self::assertFalse($bounds['discharge']['exists'], 'la décharge reste à compléter sur cette ligne');
    }

    /** Le delta d'un compteur se prend contre le dernier relevé où IL figurait. */
    public function testDeltasIgnoreRowsWhereTheCounterIsMissing(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1000.0, 'discharge' => 800.0]);
        $repo->insertIndexes($this->at('2026-06-02 08:00:00'), ['charge' => 1050.0]);
        $repo->insertIndexes($this->at('2026-06-03 08:00:00'), ['charge' => 1100.0, 'discharge' => 900.0]);

        // Page rendue du plus récent au plus ancien.
        $rows = $repo->getReadingsPage(10, 0);

        self::assertSame(50.0, $rows[0]['delta_charge']);
        self::assertSame(100.0, $rows[0]['delta_discharge'], 'delta pris contre le 1er juin, pas contre une ligne sans décharge');
        self::assertNull($rows[2]['delta_charge'], 'première ligne de la série : aucun précédent');
    }

    /** Le jour civil du plafond suit le fuseau de l'utilisateur, pas UTC. */
    public function testDailyCapFollowsTheUserTimezone(): void
    {
        $repo = $this->repo();
        $repo->insertIndexes($this->at('2026-01-01 10:00:00'), ['charge' => 1000.0]);

        self::assertTrue($repo->readingPresentInDay($this->at('2026-01-01 23:00:00'), 'UTC'));
        self::assertFalse(
            $repo->readingPresentInDay($this->at('2026-01-01 23:00:00'), 'Europe/Brussels'),
            '23:00 UTC est déjà le 2 janvier à Bruxelles'
        );
        // L'instant exact est exclu : compléter le second compteur reste possible.
        self::assertFalse($repo->readingPresentInDay($this->at('2026-01-01 10:00:00'), 'UTC'));
    }

    // ── Scope tenant ───────────────────────────────────────────────────────

    /**
     * Le scope batterie ne suffit pas : sans contrôle d'appartenance, un
     * identifiant deviné écrirait dans la batterie d'un autre compte.
     */
    public function testWritingIntoAForeignBatteryIsRefused(): void
    {
        $intrus = $this->repo($this->foreignBatteryId, $this->userId);

        self::assertSame(0, $intrus->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM battery_readings')->fetchColumn());
    }

    public function testDeletingAForeignReadingDoesNothing(): void
    {
        $owner = $this->repo($this->foreignBatteryId, $this->otherUserId);
        $owner->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]);
        $readingId = (int) $this->pdo()->query('SELECT id FROM battery_readings')->fetchColumn();

        self::assertFalse($this->repo()->deleteReading($readingId));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM battery_readings')->fetchColumn());
    }

    public function testDeleteAllOnlyEmptiesTheTargetedBattery(): void
    {
        $second = (new BatteryRepository($this->pdo(), $this->userId))->insert($this->draft());

        $this->repo()->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]);
        $this->repo($second)->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 500.0]);

        self::assertSame(1, $this->repo()->deleteAll());
        self::assertSame(0, $this->repo()->countReadings());
        self::assertSame(1, $this->repo($second)->countReadings(), 'l\'autre batterie a été vidée à tort');
    }

    // ── Contrôleur de saisie ───────────────────────────────────────────────

    private function controller(): BatteryReadingController
    {
        $pdo    = $this->pdo();
        $userId = $this->userId;

        return new BatteryReadingController(
            new BatteryRepository($pdo, $userId),
            static fn (int $batteryId): BatteryReadingRepository => new BatteryReadingRepository($pdo, $userId, $batteryId),
            'UTC',
        );
    }

    /** @param array<string, mixed> $body */
    private function entry(array $body): Request
    {
        return new Request('POST', ['action' => 'battery_entry'], $body + ['battery_id' => $this->batteryId]);
    }

    public function testEntryRefusesADecreasingIndex(): void
    {
        $this->repo()->insertIndexes($this->at('2026-06-01 08:00:00'), ['charge' => 1200.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≥ previous reading');

        $this->controller()->entry($this->entry(['reading_at' => '2026-06-02 08:00:00', 'charge' => 1100.0]));
    }

    /** Un relevé antidaté doit rester borné par le relevé qui le SUIT. */
    public function testEntryRefusesAValueAboveTheNextReading(): void
    {
        $this->repo()->insertIndexes($this->at('2026-06-10 08:00:00'), ['charge' => 1200.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≤ next reading');

        $this->controller()->entry($this->entry(['reading_at' => '2026-06-05 08:00:00', 'charge' => 1300.0]));
    }

    public function testEntryRefusesASecondReadingOnTheSameDay(): void
    {
        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00', 'charge' => 1200.0]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only one battery reading per day');

        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 20:00:00', 'charge' => 1250.0]));
    }

    /**
     * Compléter le second compteur d'une ligne déjà écrite n'est PAS un second
     * relevé du jour : le plafond exclut l'instant exact.
     */
    public function testEntryCanCompleteTheOtherCounterAtTheSameInstant(): void
    {
        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00', 'charge' => 1200.0]));

        $response = $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00', 'discharge' => 1000.0]));

        self::assertSame(200, $response->status);
        $rows = $this->repo()->getReadingsPage(10, 0);
        self::assertCount(1, $rows);
        self::assertSame(1200.0, $rows[0]['charge']);
        self::assertSame(1000.0, $rows[0]['discharge']);
    }

    public function testEntryRefusesReplacingACounterAlreadySet(): void
    {
        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00', 'charge' => 1200.0]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists at this date');

        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00', 'charge' => 1250.0]));
    }

    public function testEntryRequiresAtLeastOneCounter(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('At least one of');

        $this->controller()->entry($this->entry(['reading_at' => '2026-06-01 08:00:00']));
    }

    /** Ingestion par agent : idempotente, et les jours déjà servis sont ignorés. */
    public function testIngestIsIdempotentAndCapsToOneReadingPerDay(): void
    {
        $batch = new Request('POST', ['action' => 'ingest_battery'], [
            'battery_id' => $this->batteryId,
            'readings'   => [
                ['timestamp' => '2026-06-01T08:00:00Z', 'charge' => 1200.0, 'discharge' => 1000.0],
                ['timestamp' => '2026-06-01T09:00:00Z', 'charge' => 1205.0, 'discharge' => 1002.0],
                ['timestamp' => '2026-06-02T08:00:00Z', 'charge' => 1250.0, 'discharge' => 1030.0],
            ],
        ]);

        $first = $this->controller()->ingest($batch);
        self::assertSame(200, $first->status);
        self::assertSame(2, $this->repo()->countReadings());

        // Rejeu du même batch : plus rien à écrire.
        $this->controller()->ingest($batch);
        self::assertSame(2, $this->repo()->countReadings());
    }
}
