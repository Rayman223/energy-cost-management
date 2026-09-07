<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Domain\Meter;
use App\Http\Controller\MeterEntryController;
use App\Http\JsonResponse;
use App\Http\MeterResolver;
use App\Http\Request;
use App\Http\Router;
use App\Repository\BatteryReadingRepository;
use App\Repository\BatteryRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\Exception\ClosedMeterException;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportRunner;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Compteur fermé (#55) : plus aucune écriture à partir de sa date de fermeture.
 *
 * L'invariant vit dans le REPOSITORY, et ces tests l'attaquent par là — pas
 * seulement par les contrôleurs. Il y a quatre points d'entrée en écriture
 * (saisie manuelle, ingestion d'agent, import de fichier, scripts CLI) et le
 * repository est le seul qui leur soit commun : une garde posée en contrôleur
 * laisserait l'import ouvert, or c'est justement le chemin le plus susceptible
 * de porter des lignes anciennes.
 *
 * Trois propriétés se tiennent ensemble, et se cassent facilement l'une sans
 * l'autre :
 *   - la borne est **exclue** : on refuse DÈS le jour de fermeture ;
 *   - la règle porte sur `reading_at` et **pas sur l'horloge** : un relevé
 *     antidaté reste accepté longtemps après la fermeture ;
 *   - les **lectures ne sont jamais filtrées** : les relevés d'un compteur fermé
 *     continuent de compter partout. La dépense a eu lieu.
 */
final class ClosedMeterDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'closed', 'test', 'Closed')->id;
    }

    protected function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'utility_readings', 'meters',
            'battery_readings', 'batteries',
            'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function meters(): MeterRepository
    {
        return new MeterRepository($this->pdo(), $this->userId);
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function closedGasMeter(string $closedOn = '2026-06-15'): int
    {
        return $this->meters()->insert(new Meter(
            id: 0,
            energyType: 'gas',
            label: 'Ancien',
            closedOn: $this->at($closedOn . ' 00:00:00'),
        ));
    }

    // ── La borne ─────────────────────────────────────────────────────────────

    /**
     * Borne EXCLUE : la veille passe, le jour même ne passe plus. C'est la
     * convention de tout le projet (#1), et la confondre décalerait la fermeture
     * d'une journée entière.
     */
    public function testClosureBoundIsExclusive(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');
        $repo    = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId);

        $repo->save($this->at('2026-06-14 23:59:59'), 100.0);
        self::assertSame(1, $this->countReadings($meterId));

        $this->expectException(ClosedMeterException::class);
        $repo->save($this->at('2026-06-15 00:00:00'), 101.0);
    }

    /**
     * La règle porte sur `reading_at`, PAS sur l'horloge. Un carnet recopié des
     * mois après la fermeture doit pouvoir être saisi : c'est même le cas
     * d'usage le plus fréquent d'un compteur qu'on vient de fermer.
     */
    public function testBackdatedReadingIsStillAcceptedLongAfterClosure(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');

        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId))
            ->save($this->at('2026-03-01 08:00:00'), 42.0);

        self::assertSame(1, $this->countReadings($meterId));
    }

    /**
     * La date de fermeture est une DATE, lue dans le fuseau du foyer. Un relevé
     * du 14 à 20 h à Montréal tombe le 15 à 01 h UTC : le refuser serait une
     * fermeture anticipée d'une soirée.
     */
    public function testClosureIsReadInTheUserTimezone(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');

        // 2026-06-15 01:00 UTC == 2026-06-14 21:00 à Montréal (UTC−4 en été).
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId, 'America/Montreal'))
            ->save($this->at('2026-06-15 01:00:00'), 50.0);

        self::assertSame(1, $this->countReadings($meterId));

        // Le même instant est en revanche déjà le 15 pour un foyer à Bruxelles.
        $this->expectException(ClosedMeterException::class);
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId, 'Europe/Brussels'))
            ->save($this->at('2026-06-15 01:00:00'), 51.0);
    }

    /** Vider la date rouvre le compteur : la fermeture n'est pas irréversible. */
    public function testClearingTheDateReopensTheMeter(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');
        $meters  = $this->meters();

        $meters->update($meterId, new Meter(id: $meterId, energyType: 'gas', label: 'Ancien'));

        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId))
            ->save($this->at('2026-08-01 10:00:00'), 200.0);

        self::assertSame(1, $this->countReadings($meterId));
    }

    // ── Les lectures ─────────────────────────────────────────────────────────

    /**
     * **Les relevés d'un compteur fermé comptent toujours.** C'est le cadrage
     * même de la fonctionnalité : fermer un compteur ne rétracte pas les dépenses
     * qu'il a mesurées.
     */
    public function testReadingsOfAClosedMeterStillCountInReports(): void
    {
        $meterId = $this->closedGasMeter('2026-07-01');
        $repo    = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId);

        $repo->save($this->at('2026-06-01 10:00:00'), 100.0);
        $repo->save($this->at('2026-06-30 10:00:00'), 140.0);

        $fleet = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');

        self::assertCount(2, $fleet->getFleetSeries());
        self::assertCount(2, $fleet->getReadingsForRange('2026-06-01 00:00:00', '2026-07-01 00:00:00'));
        self::assertCount(2, $repo->getAllReadings());
    }

    // ── Les quatre points d'entrée en écriture ───────────────────────────────

    public function testElectricityWriteIsRefusedOnAClosedMeter(): void
    {
        $meterId = $this->meters()->insert(new Meter(
            id: 0,
            energyType: 'electricity',
            closedOn: $this->at('2026-06-15 00:00:00'),
        ));

        $repo = new ElectricityReadingRepository($this->pdo(), $this->userId, 'UTC', $meterId);

        // Avant la fermeture : accepté.
        self::assertSame(1, $repo->insertIndexes($this->at('2026-06-01 10:00:00'), ['import_t1' => 100.0]));

        $this->expectException(ClosedMeterException::class);
        $repo->insertIndexes($this->at('2026-06-20 10:00:00'), ['import_t1' => 120.0]);
    }

    /** L'ingestion d'agent passe par `saveIgnore()`, pas par `save()`. */
    public function testAgentIngestionIsRefusedToo(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');

        $this->expectException(ClosedMeterException::class);
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId))
            ->saveIgnore($this->at('2026-06-20 10:00:00'), 60.0);
    }

    /**
     * L'import refuse AVANT sa première ligne. BulkImportService attrape les
     * exceptions ligne par ligne : sans ce pré-contrôle, un fichier de
     * 200 000 lignes produirait 200 000 « erreurs d'écriture » au lieu d'un
     * message — un rapport d'import illisible.
     */
    public function testImportIsRefusedBeforeItsFirstRow(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');

        $rows = [];
        for ($i = 1; $i <= 50; ++$i) {
            $rows[$i] = ['timestamp' => sprintf('2026-06-%02dT10:00:00Z', min(28, $i)), 'value' => (string) (100 + $i)];
        }

        try {
            (new ImportRunner())->run(
                $this->pdo(),
                ImportMapping::preset('gas'),
                $rows,
                $this->userId,
                'gas',
                false,
                false,
                null,
                null,
                'UTC',
                $meterId,
            );
            self::fail("L'import sur un compteur fermé aurait dû être refusé.");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('fermé', $e->getMessage());
        }

        self::assertSame(0, $this->countReadings($meterId), 'Des lignes ont été écrites avant le refus.');
    }

    /**
     * Un import visant une BATTERIE DÉPOSÉE est refusé avant sa première ligne,
     * exactement comme un compteur fermé.
     *
     * `battery_id` ne passe pas par le pré-contrôle des compteurs : sans garde
     * propre, les N lignes du fichier partiraient dans la boucle et
     * BulkImportService attraperait N fois `ClosedMeterException` ligne à ligne —
     * N « erreurs d'écriture » muettes au lieu d'un message.
     */
    public function testImportIntoADecommissionedBatteryIsRefusedBeforeItsFirstRow(): void
    {
        $batteryId = (new BatteryRepository($this->pdo(), $this->userId))->insert(new Battery(
            id: 0,
            brand: 'BYD',
            model: 'HVS',
            capacityKwh: 10.24,
            commissionedOn: $this->at('2026-01-01 00:00:00'),
            decommissionedOn: $this->at('2026-06-15 00:00:00'),
            pvChargeShare: 80,
            dischargeProfile: BatteryDischargeProfile::ImportMix,
        ));

        $rows = [];
        for ($i = 1; $i <= 20; ++$i) {
            $rows[$i] = [
                'timestamp' => sprintf('2026-06-%02dT10:00:00Z', min(28, 15 + $i)),
                'charge'    => (string) (1000 + $i),
                'discharge' => (string) (500 + $i),
            ];
        }

        try {
            (new ImportRunner())->run(
                $this->pdo(),
                ImportMapping::preset('battery'),
                $rows,
                $this->userId,
                'battery',
                false,
                false,
                null,
                $batteryId,
            );
            self::fail("L'import sur une batterie déposée aurait dû être refusé.");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('déposée', $e->getMessage());
        }

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM battery_readings WHERE battery_id = :bid');
        $stmt->execute(['bid' => $batteryId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Des lignes ont été écrites avant le refus.');
    }

    /**
     * La fermeture d'un compteur d'AUTRUI ne se lit pas.
     *
     * `meter_id` arrive du POST sans contrôle d'appartenance — c'est le
     * repository qui le fait, juste avant d'écrire. Si la fermeture était relue
     * sans le même scope, « fermé le 15/06 » se distinguerait de « compteur
     * inconnu » et dirait à un tiers quels identifiants existent, et quand ils
     * ont été fermés. Les deux cas doivent rester le même refus.
     */
    public function testAForeignClosedMeterIsRefusedAsUnknown(): void
    {
        $otherUserId = (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'other', 'test', 'Other')->id;
        $foreignMeterId = (new MeterRepository($this->pdo(), $otherUserId))->insert(new Meter(
            id: 0,
            energyType: 'gas',
            closedOn: $this->at('2026-06-15 00:00:00'),
        ));

        try {
            (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $foreignMeterId))
                ->save($this->at('2026-06-20 10:00:00'), 60.0);
            self::fail('Un compteur étranger aurait dû être refusé.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(ClosedMeterException::class, $e);
            self::assertStringNotContainsString('2026-06-15', $e->getMessage());
        }

        // Même règle sur l'électricité, où la garde de fermeture précède le
        // contrôle d'appartenance dans insertIndexes().
        $foreignElecId = (new MeterRepository($this->pdo(), $otherUserId))->insert(new Meter(
            id: 0,
            energyType: 'electricity',
            closedOn: $this->at('2026-06-15 00:00:00'),
        ));

        try {
            (new ElectricityReadingRepository($this->pdo(), $this->userId, 'UTC', $foreignElecId))
                ->insertIndexes($this->at('2026-06-20 10:00:00'), ['import_t1' => 100.0]);
            self::fail('Un compteur électrique étranger aurait dû être refusé.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(ClosedMeterException::class, $e);
            self::assertStringNotContainsString('2026-06-15', $e->getMessage());
        }
    }

    /**
     * Le refus est une erreur de REQUÊTE, pas une panne : le routeur le traduit
     * en 422, une fois, plutôt que dans chacun des contrôleurs d'écriture.
     */
    public function testRouterTranslatesTheRefusalInto422(): void
    {
        $meterId = $this->closedGasMeter('2026-06-15');

        $controller = new MeterEntryController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            null,
            new MeterResolver($this->meters()),
        );

        $router = new Router();
        $router->add('POST', 'gas_entry', $controller->gas(...));

        $response = $router->dispatch(new Request('POST', ['action' => 'gas_entry'], [
            'counter_m3' => 60.0,
            'reading_at' => '2026-06-20 10:00:00',
            'meter_id'   => $meterId,
        ]));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->status);
        self::assertIsArray($response->data);
        self::assertStringContainsString('Meter closed on 2026-06-15', (string) $response->data['error']);
    }

    // ── Batteries ────────────────────────────────────────────────────────────

    /**
     * `batteries.decommissioned_on` n'était opposée à AUCUNE écriture avant #55 :
     * elle n'était lue que par le bilan et validée au formulaire. Une batterie
     * déposée continuait d'accepter des index — et de peser sur des économies
     * qu'elle ne produisait plus.
     */
    public function testDecommissionedBatteryRefusesReadings(): void
    {
        $batteryId = (new BatteryRepository($this->pdo(), $this->userId))->insert(new Battery(
            id: 0,
            brand: 'BYD',
            model: 'HVS',
            capacityKwh: 10.24,
            commissionedOn: $this->at('2026-01-01 00:00:00'),
            decommissionedOn: $this->at('2026-06-15 00:00:00'),
            pvChargeShare: 80,
            dischargeProfile: BatteryDischargeProfile::ImportMix,
        ));

        $repo = new BatteryReadingRepository($this->pdo(), $this->userId, $batteryId);

        // Avant la dépose : accepté.
        self::assertSame(1, $repo->insertIndexes($this->at('2026-06-01 10:00:00'), ['charge' => 1200.0]));

        $this->expectException(ClosedMeterException::class);
        $repo->insertIndexes($this->at('2026-06-15 10:00:00'), ['charge' => 1300.0]);
    }

    private function countReadings(int $meterId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM utility_readings WHERE meter_id = :mid');
        $stmt->execute(['mid' => $meterId]);

        return (int) $stmt->fetchColumn();
    }
}
