<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Meter;
use App\Http\Controller\IngestController;
use App\Http\Controller\MeterEntryController;
use App\Http\Controller\ReadingDeletionController;
use App\Http\Controller\ReadingsController;
use App\Http\JsonResponse;
use App\Http\MeterResolver;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Saisie, historique et suppression CIBLÉS sur un compteur (#55).
 *
 * C'est la phase qui rend enfin atteignable le parc que /meters permet de
 * déclarer : jusqu'ici toute écriture atterrissait sur le compteur le plus
 * ancien, quel que soit celui affiché à l'écran.
 *
 * En écriture et suppression, la règle est **jamais de devinette**.
 * Écrire dans le mauvais compteur ne lèverait aucune
 * erreur — l'index d'un second compteur est simplement plus élevé, et la
 * validation de bornes le lirait comme une consommation légitime. Le refus
 * explicite est donc la seule protection possible. En lecture (#78), omettre
 * la cible conserve le compteur par défaut, même après l'ajout d'un compteur.
 */
final class TargetedEntryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    private int $otherUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'targeted', 'test', 'Targeted')->id;
        $this->otherUserId = $users->create('https://iss.test', 'targeted-other', 'test', 'Other')->id;
    }

    protected function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'utility_readings', 'meters',
            'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    // ── Montage ──────────────────────────────────────────────────────────────

    private function meters(?int $userId = null): MeterRepository
    {
        return new MeterRepository($this->pdo(), $userId ?? $this->userId);
    }

    private function resolver(?int $userId = null): MeterResolver
    {
        return new MeterResolver($this->meters($userId));
    }

    private function entry(): MeterEntryController
    {
        return new MeterEntryController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            null,
            $this->resolver(),
        );
    }

    private function readings(): ReadingsController
    {
        return new ReadingsController(
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new \App\Service\UtilityConsumptionSeriesService(),
            $this->resolver(),
        );
    }

    private function deletion(): ReadingDeletionController
    {
        return new ReadingDeletionController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            $this->resolver(),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): Request
    {
        return new Request('POST', ['action' => $action], $body);
    }

    /** @param array<string, mixed> $query */
    private function get(string $action, array $query = []): Request
    {
        return new Request('GET', ['action' => $action] + $query, []);
    }

    private function countOn(int $meterId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM utility_readings WHERE meter_id = :mid');
        $stmt->execute(['mid' => $meterId]);

        return (int) $stmt->fetchColumn();
    }

    // ── Le résolveur ─────────────────────────────────────────────────────────

    /**
     * Compte neuf : aucun compteur, aucun `meter_id`. Le résolveur rend `null` et
     * le repository crée le compteur au premier relevé, comme avant #55. Refuser
     * ici obligerait à passer par /meters avant la première saisie.
     */
    public function testNoMeterYetKeepsLazyCreation(): void
    {
        self::assertNull($this->resolver()->resolve(null, 'gas'));

        $this->entry()->gas($this->post('gas_entry', ['counter_m3' => 100.0, 'reading_at' => '2026-06-01 10:00:00']));

        self::assertCount(1, $this->meters()->listByEnergy('gas'));
    }

    /** Un seul compteur : la cible est implicite, aucun agent n'a d'identifiant à connaître. */
    public function testSingleMeterIsResolvedImplicitly(): void
    {
        $id = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        self::assertSame($id, $this->resolver()->resolve(null, 'gas'));
        self::assertSame($id, $this->resolver()->resolve((string) $id, 'gas'));
    }

    /**
     * Plusieurs compteurs sans cible : refus, avec la liste des identifiants.
     * C'est LE cas qui justifie toute la phase.
     */
    public function testAmbiguityIsRefusedWithTheListOfIds(): void
    {
        $first  = $this->meters()->insert(new Meter(id: 0, energyType: 'gas', label: 'Maison'));
        $second = $this->meters()->insert(new Meter(id: 0, energyType: 'gas', label: 'Atelier'));

        try {
            $this->resolver()->resolve(null, 'gas');
            self::fail('Une cible ambiguë aurait dû être refusée.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('meter_id is required', $e->getMessage());
            self::assertStringContainsString((string) $first, $e->getMessage());
            self::assertStringContainsString((string) $second, $e->getMessage());
        }
    }

    /**
     * Compteur d'autrui, compteur d'une autre énergie, identifiant inexistant :
     * le MÊME message. Distinguer les trois dirait à un attaquant quels
     * identifiants existent.
     */
    public function testForeignWrongEnergyAndUnknownIdsAreIndistinguishable(): void
    {
        $foreign = $this->meters($this->otherUserId)->insert(new Meter(id: 0, energyType: 'gas'));
        $water   = $this->meters()->insert(new Meter(id: 0, energyType: 'water'));

        foreach ([$foreign, $water, 999_999, 'abc', -3] as $raw) {
            try {
                $this->resolver()->resolve($raw, 'gas');
                self::fail('Identifiant accepté à tort : ' . var_export($raw, true));
            } catch (ValidationException $e) {
                self::assertSame('Unknown meter_id', $e->getMessage());
            }
        }
    }

    // ── La saisie ────────────────────────────────────────────────────────────

    public function testEntryLandsOnTheDesignatedMeter(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas', label: 'Maison'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas', label: 'Atelier'));

        $this->entry()->gas($this->post('gas_entry', [
            'counter_m3' => 4200.0,
            'reading_at' => '2026-06-01 10:00:00',
            'meter_id'   => $workshop,
        ]));

        self::assertSame(0, $this->countOn($house), 'Le relevé a atterri sur le mauvais compteur.');
        self::assertSame(1, $this->countOn($workshop));
    }

    /**
     * Sans cible et avec deux compteurs, la saisie est refusée AVANT toute
     * écriture — pas repliée sur le plus ancien.
     */
    public function testEntryWithoutTargetIsRefusedWhenSeveralMetersExist(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        try {
            $this->entry()->gas($this->post('gas_entry', ['counter_m3' => 42.0, 'reading_at' => '2026-06-01 10:00:00']));
            self::fail('La saisie ambiguë aurait dû être refusée.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('meter_id is required', $e->getMessage());
        }

        self::assertSame(0, $this->countOn($house));
        self::assertSame(0, $this->countOn($workshop));
    }

    /**
     * Les bornes d'antidatage portent sur le compteur VISÉ. Un index de 4 200
     * pour l'atelier n'a pas à être comparé aux 100 de la maison — c'est
     * exactement le faux pic que la phase élimine.
     */
    public function testBoundsAreCheckedAgainstTheDesignatedMeterOnly(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $house))
            ->save(new DateTimeImmutable('2026-06-10 10:00:00'), 100.0);

        // Antidaté par rapport au relevé de la maison, mais l'atelier n'a rien :
        // aucune borne ne s'y oppose.
        $res = $this->entry()->gas($this->post('gas_entry', [
            'counter_m3' => 4200.0,
            'reading_at' => '2026-06-01 10:00:00',
            'meter_id'   => $workshop,
        ]));

        self::assertSame(200, $res->status);
        self::assertSame(1, $this->countOn($workshop));
    }

    public function testElectricityEntryLandsOnTheDesignatedMeter(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'electricity'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'electricity'));

        $this->entry()->electricity($this->post('electricity_entry', [
            'reading_at' => '2026-06-01 10:00:00',
            'import_t1'  => 5000.0,
            'meter_id'   => $workshop,
        ]));

        $rows = function (int $meterId): int {
            $stmt = $this->pdo()->prepare(
                'SELECT COUNT(*) FROM meter_readings mr
                   JOIN meter_registers reg ON reg.id = mr.register_id
                  WHERE reg.meter_id = :mid'
            );
            $stmt->execute(['mid' => $meterId]);

            return (int) $stmt->fetchColumn();
        };

        self::assertSame(0, $rows($house));
        self::assertSame(1, $rows($workshop));
    }

    // ── Historique et suppression ────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function singleMeterReads(): iterable
    {
        yield 'today' => ['today', 'electricity'];
        yield 'gas_history' => ['gas_history', 'gas'];
        yield 'water_history' => ['water_history', 'water'];
        yield 'electricity_history' => ['electricity_history', 'electricity'];
    }

    /** @param array<string, mixed> $query */
    private function read(string $action, array $query = []): JsonResponse
    {
        $controller = $this->readings();
        $request = $this->get($action, $query);

        return match ($action) {
            'today' => $controller->today($request),
            'gas_history' => $controller->gasHistory($request),
            'water_history' => $controller->waterHistory($request),
            'electricity_history' => $controller->electricityHistory($request),
        };
    }

    private function seedReadings(int $meterId, string $energy, float $start): void
    {
        foreach (['2026-06-01 10:00:00', '2026-06-02 10:00:00'] as $offset => $at) {
            $timestamp = new DateTimeImmutable($at);
            if ($energy === 'electricity') {
                (new ElectricityReadingRepository($this->pdo(), $this->userId, meterId: $meterId))
                    ->insertIndexes($timestamp, ['import_t1' => $start + 10.0 * $offset]);
            } else {
                (new UtilityReadingRepository($this->pdo(), $this->userId, $energy, $meterId))
                    ->save($timestamp, $start + 10.0 * $offset);
            }
        }
    }

    #[DataProvider('singleMeterReads')]
    public function testAddingMeterKeepsImplicitReadTarget(string $action, string $energy): void
    {
        $first = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $this->seedReadings($first, $energy, 100.0);
        $before = $this->read($action);
        self::assertSame(200, $before->status);
        self::assertSame($before->data, $this->read($action, ['meter_id' => (string) $first])->data);

        $second = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $this->seedReadings($second, $energy, 4200.0);

        self::assertSame($before->data, $this->read($action)->data);
        self::assertSame($before->data, $this->read($action, ['meter_id' => ''])->data);
        $targeted = $this->read($action, ['meter_id' => (string) $second]);
        self::assertSame(200, $targeted->status);
        self::assertIsArray($targeted->data);
        if ($action === 'today') {
            self::assertSame(4210.0, $targeted->data['dries']['Prelev_jour']);
            return;
        }

        self::assertSame(2, $targeted->data['total']);
        $key = $energy === 'electricity' ? 'import_t1' : 'counter_m3';
        self::assertSame([4210.0, 4200.0], array_column($targeted->data['items'], $key));
        if ($energy !== 'electricity') {
            self::assertSame([10.0, null], array_column($targeted->data['items'], 'delta_m3'));
        }

        // Le relevé hors page et les deltas restent sur le compteur choisi.
        $page = $this->read($action, ['meter_id' => $second, 'per_page' => '1'])->data;
        self::assertIsArray($page);
        self::assertSame(1, $page['per_page']);
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);
        if ($energy === 'electricity') {
            self::assertSame(4200.0, $page['previous']['import_t1']);
        } else {
            self::assertSame(10.0, $page['items'][0]['delta_m3']);
        }
    }

    #[DataProvider('singleMeterReads')]
    public function testReadWithoutMeterReturnsEmptyDataWithoutCreatingMeter(string $action, string $energy): void
    {
        self::assertNull($this->resolver()->resolveForRead(null, $energy));
        $response = $this->read($action);
        self::assertSame(200, $response->status);
        $expected = $action === 'today'
            ? ['dries' => null, 'solar' => null, 'solar_source' => 'meter_readings']
            : ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
        if ($action === 'electricity_history') {
            $expected['previous'] = null;
        }
        self::assertSame($expected, $response->data);
        self::assertSame($expected, $this->read($action, ['meter_id' => ''])->data);
        self::assertSame([], $this->meters()->listAll());
    }

    #[DataProvider('singleMeterReads')]
    public function testClosedDefaultMeterRemainsReadable(string $action, string $energy): void
    {
        $first = $this->meters()->insert(new Meter(
            id: 0,
            energyType: $energy,
            closedOn: new DateTimeImmutable('2026-06-03'),
        ));
        $this->seedReadings($first, $energy, 100.0);
        $expected = $this->read($action, ['meter_id' => $first])->data;
        $second = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $this->seedReadings($second, $energy, 4200.0);

        self::assertSame($first, $this->resolver()->resolveForRead(null, $energy));
        self::assertSame($expected, $this->read($action)->data);
    }

    #[DataProvider('singleMeterReads')]
    public function testReadRejectsInvalidExplicitTargetWithoutFallingBack(string $action, string $energy): void
    {
        $first = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $this->seedReadings($first, $energy, 100.0);
        $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $foreign = $this->meters($this->otherUserId)->insert(new Meter(id: 0, energyType: $energy));
        $wrongEnergy = $this->meters()->insert(new Meter(
            id: 0,
            energyType: $energy === 'water' ? 'gas' : 'water',
        ));

        foreach ([$foreign, $wrongEnergy, 999_999, 'abc', -3, 0] as $raw) {
            try {
                $this->read($action, ['meter_id' => $raw]);
                self::fail('Une cible explicite invalide aurait dû être refusée.');
            } catch (ValidationException $e) {
                self::assertSame('Unknown meter_id', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function energies(): iterable
    {
        foreach (Meter::ENERGIES as $energy) {
            yield $energy => [$energy];
        }
    }

    #[DataProvider('energies')]
    public function testMutationsStillRequireTargetWithSeveralMeters(string $energy): void
    {
        $first = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $second = $this->meters()->insert(new Meter(id: 0, energyType: $energy));
        $this->seedReadings($first, $energy, 100.0);
        $this->seedReadings($second, $energy, 4200.0);
        $action = $energy === 'electricity' ? 'electricity_history' : $energy . '_history';
        $before = [
            $this->read($action, ['meter_id' => $first])->data,
            $this->read($action, ['meter_id' => $second])->data,
        ];
        $ingest = new IngestController(
            new ElectricityReadingRepository($this->pdo(), $this->userId),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            null,
            $this->resolver(),
        );
        $deleteMethod = $energy . 'All';
        $body = [
            'reading_at' => '2026-06-03T10:00:00Z',
            'timestamp' => '2026-06-03T10:00:00Z',
            $energy === 'electricity' ? 'import_t1' : 'counter_m3' => 4300.0,
        ];

        foreach ([[$this->entry(), $energy], [$ingest, $energy], [$this->deletion(), $deleteMethod]] as [$controller, $method]) {
            try {
                $controller->$method($this->post($method, $body));
                self::fail('Une mutation ambiguë aurait dû être refusée.');
            } catch (ValidationException $e) {
                self::assertStringContainsString('meter_id is required', $e->getMessage());
            }
        }

        self::assertSame($before, [
            $this->read($action, ['meter_id' => $first])->data,
            $this->read($action, ['meter_id' => $second])->data,
        ], 'Aucun compteur ne doit être modifié par une mutation refusée.');
    }

    public function testHistoryShowsOnlyTheDesignatedMeter(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $house))
            ->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $workshop))
            ->save(new DateTimeImmutable('2026-06-02 10:00:00'), 4200.0);

        /** @var array{items: list<array<string, mixed>>, total: int} $payload */
        $payload = $this->readings()->gasHistory($this->get('gas_history', ['meter_id' => (string) $workshop]))->data;

        self::assertSame(1, $payload['total']);
        self::assertSame(4200.0, $payload['items'][0]['counter_m3']);
        // Et surtout : aucun delta fabriqué contre l'index de l'autre compteur.
        self::assertNull($payload['items'][0]['delta_m3']);
    }

    public function testDeleteAllOnlyEmptiesTheDesignatedMeter(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $house))
            ->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $workshop))
            ->save(new DateTimeImmutable('2026-06-02 10:00:00'), 4200.0);

        $this->deletion()->gasAll($this->post('delete_gas_all', ['meter_id' => $workshop]));

        self::assertSame(1, $this->countOn($house), "L'autre compteur a été vidé.");
        self::assertSame(0, $this->countOn($workshop));
    }

    /**
     * Les RAPPORTS restent en flotte : la série de volumes somme les compteurs,
     * pour afficher les mêmes m³ que les cards de coût.
     */
    public function testFleetSeriesStillCoversEveryMeter(): void
    {
        $house    = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));
        $workshop = $this->meters()->insert(new Meter(id: 0, energyType: 'gas'));

        foreach ([[$house, 100.0], [$workshop, 40.0]] as [$meterId, $value]) {
            $repo = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas', $meterId);
            $repo->save(new DateTimeImmutable('2026-06-01 10:00:00'), $value);
            $repo->save(new DateTimeImmutable('2026-07-01 10:00:00'), $value + 30.0);
        }

        $series = (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'))->getFleetSeries();

        self::assertCount(2, $series);
        self::assertSame(140.0, $series[0]['counter_m3']);
        self::assertSame(200.0, $series[1]['counter_m3']);
    }
}
