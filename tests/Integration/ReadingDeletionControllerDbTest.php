<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Meter;
use App\Http\Controller\ReadingDeletionController;
use App\Http\MeterResolver;
use App\Http\Request;
use App\Http\ValidationException;
use App\Infrastructure\MeterTopology;
use App\Repository\ElectricityReadingRepository;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;

/**
 * Test d'intégration du contrôleur de suppression contre de vrais repositories :
 * couvre le chemin complet (réponse JSON, parsing reading_at, validation d'id) de
 * la suppression d'un relevé, ligne par ligne ou en masse (issue #162).
 * S'auto-skippe sans base de test joignable.
 */
final class ReadingDeletionControllerDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'del-ctrl-test', 'test', 'Del Tester')->id;
    }

    protected function clean(): void
    {
        foreach (['utility_readings', 'meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function controller(): ReadingDeletionController
    {
        return new ReadingDeletionController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            new MeterResolver(new MeterRepository($this->pdo(), $this->userId)),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): Request
    {
        return new Request('POST', ['action' => $action], $body);
    }

    public function testDeleteWaterReadingByIdReturnsOk(): void
    {
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);
        $id = $water->getAllReadings()[0]['id'];

        $res = $this->controller()->waterReading($this->post('delete_water_reading', ['id' => $id]));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'deleted' => 1], $res->data);
        self::assertSame([], $water->getAllReadings());
    }

    public function testDeleteWaterReadingWithInvalidIdThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->waterReading($this->post('delete_water_reading', ['id' => 'abc']));
    }

    public function testDeleteWaterReadingUnknownIdReportsZero(): void
    {
        $res = $this->controller()->waterReading($this->post('delete_water_reading', ['id' => 999999]));

        self::assertSame(['ok' => true, 'deleted' => 0], $res->data);
    }

    public function testDeleteWaterAllClearsFluid(): void
    {
        $water = new UtilityReadingRepository($this->pdo(), $this->userId, 'water');
        $water->save(new DateTimeImmutable('2026-06-01 10:00:00'), 40.0);
        $water->save(new DateTimeImmutable('2026-07-01 10:00:00'), 45.0);

        $res = $this->controller()->waterAll($this->post('delete_water_all', []));

        self::assertSame(['ok' => true, 'deleted' => 2], $res->data);
        self::assertSame([], $water->getAllReadings());
    }

    public function testDeleteElectricityReadingByTimestamp(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0, 'import_t2' => 200.0]);

        $res = $this->controller()->electricityReading($this->post('delete_electricity_reading', ['reading_at' => '2026-06-01 00:00:00']));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'deleted' => 2], $res->data);
        self::assertSame([], (new ElectricityReadingRepository($this->pdo(), $this->userId))->getHistory());
    }

    public function testDeleteElectricityReadingWithBadTimestampThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->electricityReading($this->post('delete_electricity_reading', ['reading_at' => 'not-a-date']));
    }

    /**
     * Régression : sans reading_at, le cast `(string) null` faisait construire un
     * DateTimeImmutable('') — soit « maintenant » — et la suppression partait
     * quand même, en répondant 200 {"deleted":0}. On attend désormais une 422,
     * et surtout aucun relevé touché.
     */
    public function testDeleteElectricityReadingWithoutTimestampThrows(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);

        try {
            $this->controller()->electricityReading($this->post('delete_electricity_reading', []));
            self::fail('Une date requise absente doit lever une ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('Invalid reading_at date format', $e->getMessage());
        }

        self::assertNotSame([], (new ElectricityReadingRepository($this->pdo(), $this->userId))->getHistory());
    }

    public function testDeleteElectricityAllPreservesNamedClosedMeterAndRegisters(): void
    {
        $meters = new MeterRepository($this->pdo(), $this->userId);
        $meterId = $meters->insert(new Meter(
            id: 0,
            energyType: 'electricity',
            label: 'Atelier',
            closedOn: new DateTimeImmutable('2026-07-01'),
            openedOn: new DateTimeImmutable('2026-01-01'),
        ));
        $before = $meters->find($meterId);
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId, meterId: $meterId);
        $indexes = ['import_t1' => 100.0, 'import_t2' => 200.0, 'export_t1' => 20.0, 'export_t2' => 30.0, 'production' => 50.0];
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), $indexes);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-02 00:00:00'), $indexes);
        $topology = new MeterTopology($this->pdo());
        $registers = $topology->ownedRegisterMap($this->userId, $meterId);

        // L'identifiant reste facultatif lorsqu'il n'existe qu'un compteur.
        $res = $this->controller()->electricityAll($this->post('delete_electricity_all', []));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'deleted' => 10], $res->data);
        self::assertSame([], $elec->getHistory());
        self::assertEquals($before, $meters->find($meterId));
        self::assertSame($registers, $topology->ownedRegisterMap($this->userId, $meterId));
        self::assertSame(
            ['ok' => true, 'deleted' => 0],
            $this->controller()->electricityAll($this->post('delete_electricity_all', ['meter_id' => $meterId]))->data,
        );

        // Un relevé antérieur à la fermeture utilise toujours les mêmes ids.
        self::assertSame(5, $elec->insertIndexes(new DateTimeImmutable('2026-06-03 00:00:00'), $indexes));
        self::assertSame($registers, $topology->ownedRegisterMap($this->userId, $meterId));
        self::assertEquals($before, $meters->find($meterId));
    }

    public function testDeleteElectricityAllOnlyEmptiesSelectedMeter(): void
    {
        $meters = new MeterRepository($this->pdo(), $this->userId);
        $house = $meters->insert(new Meter(id: 0, energyType: 'electricity', label: 'Maison'));
        $workshop = $meters->insert(new Meter(id: 0, energyType: 'electricity', label: 'Atelier'));
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'del-other', 'test', 'Other')->id;
        $houseRepo = new ElectricityReadingRepository($this->pdo(), $this->userId, meterId: $house);
        $workshopRepo = new ElectricityReadingRepository($this->pdo(), $this->userId, meterId: $workshop);
        $otherRepo = new ElectricityReadingRepository($this->pdo(), $otherId);
        foreach ([$houseRepo, $workshopRepo, $otherRepo] as $repo) {
            $repo->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);
        }

        $res = $this->controller()->electricityAll($this->post('delete_electricity_all', ['meter_id' => $workshop]));

        self::assertSame(['ok' => true, 'deleted' => 1], $res->data);
        self::assertSame([], $workshopRepo->getHistory());
        self::assertCount(1, $houseRepo->getHistory());
        self::assertCount(1, $otherRepo->getHistory());
        self::assertCount(2, $meters->listByEnergy('electricity'));
    }

    public function testDeleteElectricityAllRejectsAmbiguousOrInvalidTargets(): void
    {
        $meters = new MeterRepository($this->pdo(), $this->userId);
        $first = $meters->insert(new Meter(id: 0, energyType: 'electricity'));
        $second = $meters->insert(new Meter(id: 0, energyType: 'electricity'));
        $gas = $meters->insert(new Meter(id: 0, energyType: 'gas'));
        $otherId = (new UserRepository($this->pdo()))->create('https://iss.test', 'del-foreign', 'test', 'Foreign')->id;
        $foreign = (new MeterRepository($this->pdo(), $otherId))->insert(new Meter(id: 0, energyType: 'electricity'));
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId, meterId: $first);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);

        $cases = [
            [[], 'meter_id is required (several electricity meters: ' . $first . ', ' . $second . ')'],
            [['meter_id' => 'abc'], 'Unknown meter_id'],
            [['meter_id' => 999999], 'Unknown meter_id'],
            [['meter_id' => $gas], 'Unknown meter_id'],
            [['meter_id' => $foreign], 'Unknown meter_id'],
        ];
        foreach ($cases as [$body, $message]) {
            try {
                $this->controller()->electricityAll($this->post('delete_electricity_all', $body));
                self::fail('Une cible invalide ou ambiguë doit être refusée.');
            } catch (ValidationException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertCount(1, $elec->getHistory());
    }

    public function testDeleteElectricityAllWithoutMeterDoesNotCreateOne(): void
    {
        $res = $this->controller()->electricityAll($this->post('delete_electricity_all', []));

        self::assertSame(['ok' => true, 'deleted' => 0], $res->data);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meters')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_registers')->fetchColumn());
    }

    public function testDeleteElectricityAllWithoutRegistersPreservesMeter(): void
    {
        $meters = new MeterRepository($this->pdo(), $this->userId);
        $meterId = $meters->insert(new Meter(id: 0, energyType: 'electricity', label: 'Neuf'));
        $before = $meters->find($meterId);

        $res = $this->controller()->electricityAll($this->post('delete_electricity_all', ['meter_id' => $meterId]));

        self::assertSame(['ok' => true, 'deleted' => 0], $res->data);
        self::assertEquals($before, $meters->find($meterId));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meter_registers')->fetchColumn());
    }

    public function testDeleteElectricityMeter(): void
    {
        $elec = new ElectricityReadingRepository($this->pdo(), $this->userId);
        $elec->insertIndexes(new DateTimeImmutable('2026-06-01 00:00:00'), ['import_t1' => 100.0]);

        $res = $this->controller()->electricityMeter($this->post('delete_electricity_meter', []));

        self::assertSame(['ok' => true, 'deleted' => 1], $res->data);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM meters')->fetchColumn());
    }
}
