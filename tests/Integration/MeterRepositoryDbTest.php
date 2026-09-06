<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Meter;
use App\Infrastructure\MeterTopology;
use App\Repository\Exception\LimitReachedException;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parc de compteurs (#55). S'auto-skippe sans base de test joignable.
 *
 * Trois propriétés tiennent tout le reste :
 *   - le **scope tenant** — un identifiant deviné ne doit toucher aucune ligne
 *     d'autrui, en lecture comme en écriture ;
 *   - le **plafond par énergie**, qui doit refuser le compteur de trop sans
 *     jamais bloquer une AUTRE énergie ;
 *   - la **cascade de suppression**, qui emporte les relevés des DEUX modèles
 *     (registres électriques et relevés gaz/eau) — c'est ce que la confirmation
 *     annonce à l'utilisateur, et c'est irréversible.
 */
final class MeterRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    private int $otherUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'meter-owner', 'test', 'Meter Owner')->id;
        $this->otherUserId = $users->create('https://iss.test', 'meter-other', 'test', 'Other Owner')->id;
    }

    protected function clean(): void
    {
        // `meters` APRÈS `utility_readings` : depuis #55 les relevés gaz/eau y sont
        // rattachés par clé étrangère.
        foreach ([
            'meter_readings', 'meter_registers', 'utility_readings', 'meters',
            'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function repo(int $max = 5, ?int $userId = null): MeterRepository
    {
        return new MeterRepository($this->pdo(), $userId ?? $this->userId, $max);
    }

    public function testInsertListFindRenameAndDelete(): void
    {
        $repo = $this->repo();

        self::assertSame([], $repo->listAll());

        $id = $repo->insert(new Meter(id: 0, energyType: 'electricity', label: 'Maison'));
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame('electricity', $found->energyType);
        self::assertSame('Maison', $found->label);
        self::assertNull($found->closedOn);
        self::assertTrue($repo->owns($id));

        // Renommage : seul le libellé bouge, l'énergie est ignorée par l'UPDATE.
        $repo->update($id, new Meter(id: $id, energyType: 'electricity', label: 'Atelier'));
        self::assertSame('Atelier', $repo->find($id)?->label);

        $repo->delete($id);
        self::assertNull($repo->find($id));
        self::assertFalse($repo->owns($id));
    }

    /** Un libellé vide est un état normal : c'est le défaut de la colonne. */
    public function testAnUnnamedMeterRoundTripsAsUnnamed(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert(new Meter(id: 0, energyType: 'water'));

        $meter = $repo->find($id);
        self::assertNotNull($meter);
        self::assertSame('', $meter->label);
        self::assertFalse($meter->isNamed());
    }

    public function testClosureDateRoundTrips(): void
    {
        $repo   = $this->repo();
        $closed = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));

        $id = $repo->insert(new Meter(id: 0, energyType: 'gas', label: 'Ancien', closedOn: $closed));

        $meter = $repo->find($id);
        self::assertNotNull($meter);
        self::assertNotNull($meter->closedOn);
        self::assertSame('2026-03-01', $meter->closedOn->format('Y-m-d'));
    }

    /**
     * Ordre significatif : le premier compteur d'une énergie est celui que résout
     * MeterTopology (ORDER BY id), donc celui qui reçoit les relevés sans cible
     * explicite. Il doit se lire en tête, pas noyé dans un tri par libellé.
     */
    public function testListAllGroupsByEnergyThenOldestFirst(): void
    {
        $repo = $this->repo();
        $water = $repo->insert(new Meter(id: 0, energyType: 'water', label: 'Eau'));
        $elec2 = $repo->insert(new Meter(id: 0, energyType: 'electricity', label: 'Atelier'));
        $gas   = $repo->insert(new Meter(id: 0, energyType: 'gas', label: 'Gaz'));
        $elec1 = $repo->insert(new Meter(id: 0, energyType: 'electricity', label: 'Maison'));

        // `electricity` d'abord (le plus ancien en tête), puis `gas`, puis `water`.
        self::assertSame(
            [$elec2, $elec1, $gas, $water],
            array_map(static fn (Meter $m): int => $m->id, $repo->listAll()),
        );

        self::assertSame(
            [$elec2, $elec1],
            array_map(static fn (Meter $m): int => $m->id, $repo->listByEnergy('electricity')),
        );
    }

    public function testLimitIsPerEnergyAndRefusesTheOneTooMany(): void
    {
        $repo = $this->repo(max: 3);

        for ($i = 0; $i < 3; ++$i) {
            $repo->insert(new Meter(id: 0, energyType: 'electricity'));
        }
        self::assertSame(3, $repo->countByEnergy('electricity'));

        // Une AUTRE énergie reste libre : le plafond est par énergie, trois
        // compteurs électriques ne doivent pas empêcher de déclarer un compteur
        // d'eau.
        $repo->insert(new Meter(id: 0, energyType: 'water'));
        self::assertSame(1, $repo->countByEnergy('water'));

        try {
            $repo->insert(new Meter(id: 0, energyType: 'electricity'));
            self::fail('Le quatrième compteur électrique aurait dû être refusé.');
        } catch (LimitReachedException $e) {
            self::assertSame('electricity', $e->energyType);
            self::assertSame(3, $e->limit);
        }

        // Rien n'a été écrit : un refus ne doit pas laisser de ligne derrière lui.
        self::assertSame(3, $repo->countByEnergy('electricity'));
    }

    /** Le plafond d'un utilisateur ne se calcule pas sur le parc d'un autre. */
    public function testLimitIsCountedPerUser(): void
    {
        $mine  = $this->repo(max: 1);
        $other = $this->repo(max: 1, userId: $this->otherUserId);

        $mine->insert(new Meter(id: 0, energyType: 'gas'));
        $other->insert(new Meter(id: 0, energyType: 'gas'));

        self::assertSame(1, $mine->countByEnergy('gas'));
        self::assertSame(1, $other->countByEnergy('gas'));
    }

    /**
     * Scope tenant : un identifiant deviné ne doit rien rendre, rien modifier et
     * rien supprimer.
     */
    public function testAnotherUsersMeterIsInvisibleAndUntouchable(): void
    {
        $foreignId = $this->repo(userId: $this->otherUserId)
            ->insert(new Meter(id: 0, energyType: 'electricity', label: 'Chez le voisin'));

        $repo = $this->repo();
        self::assertNull($repo->find($foreignId));
        self::assertFalse($repo->owns($foreignId));
        self::assertSame([], $repo->listAll());
        self::assertSame([], $repo->readingCounts());

        $repo->update($foreignId, new Meter(id: $foreignId, energyType: 'electricity', label: 'Détourné'));
        $repo->delete($foreignId);

        // La ligne du voisin est intacte : ni renommée, ni supprimée.
        $neighbour = $this->repo(userId: $this->otherUserId)->find($foreignId);
        self::assertNotNull($neighbour);
        self::assertSame('Chez le voisin', $neighbour->label);
    }

    public function testReadingCountsSumBothModelsAndIncludeEmptyMeters(): void
    {
        $repo     = $this->repo();
        $elecId   = $repo->insert(new Meter(id: 0, energyType: 'electricity', label: 'Maison'));
        $gasId    = $repo->insert(new Meter(id: 0, energyType: 'gas', label: 'Gaz'));
        $emptyId  = $repo->insert(new Meter(id: 0, energyType: 'water', label: 'Eau'));

        // Deux SAISIES électriques, chacune alimentant les cinq registres — c'est
        // ce que fait une saisie réelle. Compter les lignes de `meter_readings`
        // annoncerait 10 relevés là où l'utilisateur en a saisi 2, et ce chiffre
        // faux s'afficherait dans la confirmation d'une suppression irréversible.
        $this->seedElectricityReadings($elecId, ['2026-01-01 10:00:00', '2026-01-02 10:00:00']);
        self::assertSame(10, $this->countRows('meter_readings'), 'Le seed doit bien écrire 5 lignes par saisie.');
        $this->seedUtilityReadings($gasId, 'gas', ['2026-01-01 10:00:00']);

        $counts = $repo->readingCounts();

        // 2 saisies électriques (et non 10 lignes) + 1 relevé gaz.
        self::assertSame(2, $counts[$elecId] ?? null);
        self::assertSame(1, $counts[$gasId] ?? null);
        // Présent avec un zéro : l'absence de clé signifierait « compteur inconnu »,
        // ce que le template lirait comme la même chose.
        self::assertSame(0, $counts[$emptyId] ?? null);
    }

    /**
     * La cascade est ce que la confirmation annonce à l'utilisateur : si elle ne
     * portait que sur un modèle, la suppression laisserait des relevés orphelins
     * rattachés à rien.
     */
    public function testDeleteCascadesToBothReadingModels(): void
    {
        $repo   = $this->repo();
        $elecId = $repo->insert(new Meter(id: 0, energyType: 'electricity'));
        $gasId  = $repo->insert(new Meter(id: 0, energyType: 'gas'));

        $this->seedElectricityReadings($elecId, ['2026-01-01 10:00:00']);
        $this->seedUtilityReadings($gasId, 'gas', ['2026-01-01 10:00:00']);

        $repo->delete($elecId);
        $repo->delete($gasId);

        self::assertSame(0, $this->countRows('meter_readings'));
        self::assertSame(0, $this->countRows('meter_registers'));
        self::assertSame(0, $this->countRows('utility_readings'));
        self::assertSame(0, $this->countRows('meters'));
    }

    /** @param list<string> $timestamps */
    private function seedElectricityReadings(int $meterId, array $timestamps): void
    {
        // Tous les registres, comme une saisie réelle : c'est ce qui distingue le
        // nombre de SAISIES du nombre de lignes.
        $registers = (new MeterTopology($this->pdo()))->ensureRegisters($meterId);
        $stmt      = $this->pdo()->prepare(
            'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:r, :a, :v)'
        );
        foreach ($timestamps as $index => $at) {
            foreach ($registers as $registerId) {
                $stmt->execute(['r' => $registerId, 'a' => $at, 'v' => 100.0 + $index]);
            }
        }
    }

    /** @param list<string> $timestamps */
    private function seedUtilityReadings(int $meterId, string $energyType, array $timestamps): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
             VALUES (:uid, :mid, :etype, :a, :v)'
        );
        foreach ($timestamps as $index => $at) {
            $stmt->execute([
                'uid'   => $this->userId,
                'mid'   => $meterId,
                'etype' => $energyType,
                'a'     => $at,
                'v'     => 10.0 + $index,
            ]);
        }
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
