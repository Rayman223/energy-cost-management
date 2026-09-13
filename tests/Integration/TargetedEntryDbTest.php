<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Meter;
use App\Http\Controller\MeterEntryController;
use App\Http\Controller\ReadingDeletionController;
use App\Http\Controller\ReadingsController;
use App\Http\MeterResolver;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;

/**
 * Saisie, historique et suppression CIBLÉS sur un compteur (#55).
 *
 * C'est la phase qui rend enfin atteignable le parc que /meters permet de
 * déclarer : jusqu'ici toute écriture atterrissait sur le compteur le plus
 * ancien, quel que soit celui affiché à l'écran.
 *
 * La règle testée ici est celle du résolveur, et elle tient en une phrase :
 * **jamais de devinette**. Écrire dans le mauvais compteur ne lèverait aucune
 * erreur — l'index d'un second compteur est simplement plus élevé, et la
 * validation de bornes le lirait comme une consommation légitime. Le refus
 * explicite est donc la seule protection possible.
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
