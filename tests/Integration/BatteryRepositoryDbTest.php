<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Repository\BatteryRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parc de batteries domestiques (#26). S'auto-skippe sans base de test joignable.
 *
 * Deux propriétés tiennent tout le reste : le scope tenant — un identifiant
 * deviné ne doit toucher aucune ligne d'autrui, en lecture comme en écriture — et
 * l'aller-retour fidèle des colonnes nullables, sur lesquelles reposeront le
 * rendement (capacité utile) et l'amortissement (prix d'achat).
 */
final class BatteryRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    private int $otherUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'bat-owner', 'test', 'Battery Owner')->id;
        $this->otherUserId = $users->create('https://iss.test', 'bat-other', 'test', 'Other Owner')->id;
    }

    protected function clean(): void
    {
        foreach (['battery_readings', 'batteries', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function draft(
        string $brand = 'BYD',
        float $capacity = 10.24,
        ?float $usable = 9.2,
        ?float $price = 7500.0,
        string $commissioned = '2026-01-01',
        ?string $decommissioned = null,
        BatteryDischargeProfile $profile = BatteryDischargeProfile::ImportMix,
        ?int $t1Share = null,
    ): Battery {
        return new Battery(
            id: 0,
            brand: $brand,
            model: 'HVS 10.2',
            capacityKwh: $capacity,
            commissionedOn: $this->at($commissioned),
            decommissionedOn: $decommissioned !== null ? $this->at($decommissioned) : null,
            usableCapacityKwh: $usable,
            purchasePrice: $price,
            warrantyUntil: $this->at('2036-01-01'),
            ratedCycles: 6000,
            pvChargeShare: 80,
            dischargeProfile: $profile,
            dischargeT1Share: $t1Share,
            note: 'onduleur hybride',
        );
    }

    public function testInsertListUpdateAndDelete(): void
    {
        $repo = new BatteryRepository($this->pdo(), $this->userId);

        self::assertSame([], $repo->listAll());

        $id = $repo->insert($this->draft());
        self::assertGreaterThan(0, $id);

        self::assertCount(1, $repo->listAll());

        $stored = $repo->find($id);
        self::assertNotNull($stored);
        self::assertSame('BYD', $stored->brand);
        self::assertEqualsWithDelta(10.24, $stored->capacityKwh, 0.0001);
        self::assertEqualsWithDelta(9.2, $stored->usableCapacityKwh ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(7500.0, $stored->purchasePrice ?? 0.0, 0.0001);
        self::assertSame('2026-01-01', $stored->commissionedOn->format('Y-m-d'));
        self::assertNull($stored->decommissionedOn);
        self::assertSame(6000, $stored->ratedCycles);
        self::assertSame(80, $stored->pvChargeShare);
        self::assertSame(BatteryDischargeProfile::ImportMix, $stored->dischargeProfile);
        self::assertSame('onduleur hybride', $stored->note);

        $repo->update($id, $this->draft(brand: 'Sonnen', capacity: 11.0, decommissioned: '2027-01-01'));
        $updated = $repo->find($id);

        self::assertNotNull($updated);
        self::assertSame('Sonnen', $updated->brand);
        self::assertEqualsWithDelta(11.0, $updated->capacityKwh, 0.0001);
        self::assertSame('2027-01-01', $updated->decommissionedOn?->format('Y-m-d'));

        $repo->delete($id);
        self::assertSame([], $repo->listAll());
        self::assertNull($repo->find($id));
    }

    /**
     * Colonnes nullables laissées vides : capacité utile et prix d'achat doivent
     * revenir `null` et non `0.0`. Un zéro se lirait comme « batterie sans capacité
     * utile » et comme « investissement nul », donc amortie d'emblée.
     */
    public function testNullableColumnsRoundTripAsNull(): void
    {
        $repo = new BatteryRepository($this->pdo(), $this->userId);
        $id   = $repo->insert(new Battery(
            id: 0,
            brand: '',
            model: '',
            capacityKwh: 5.0,
            commissionedOn: $this->at('2026-02-01'),
        ));

        $battery = $repo->find($id);

        self::assertNotNull($battery);
        self::assertNull($battery->usableCapacityKwh);
        self::assertNull($battery->purchasePrice);
        self::assertNull($battery->warrantyUntil);
        self::assertNull($battery->ratedCycles);
        self::assertNull($battery->decommissionedOn);
        self::assertSame(100, $battery->pvChargeShare, 'Défaut : charge intégralement photovoltaïque');
    }

    /**
     * La part T1 n'a de sens que pour le profil qui la consomme. La conserver après
     * un changement de profil laisserait en base une valeur muette, qui ressortirait
     * à l'écran comme si elle entrait dans le calcul.
     */
    public function testT1ShareIsDroppedWhenTheProfileDoesNotUseIt(): void
    {
        $repo = new BatteryRepository($this->pdo(), $this->userId);
        $id   = $repo->insert($this->draft(profile: BatteryDischargeProfile::Ratio, t1Share: 55));

        self::assertSame(55, $repo->find($id)?->dischargeT1Share);

        $repo->update($id, $this->draft(profile: BatteryDischargeProfile::T2, t1Share: 55));

        self::assertNull($repo->find($id)?->dischargeT1Share);
    }

    public function testAnotherAccountCannotReadUpdateOrDeleteTheBattery(): void
    {
        $owner  = new BatteryRepository($this->pdo(), $this->userId);
        $intrus = new BatteryRepository($this->pdo(), $this->otherUserId);

        $id = $owner->insert($this->draft());

        self::assertNull($intrus->find($id));
        self::assertFalse($intrus->owns($id));
        self::assertTrue($owner->owns($id));
        self::assertSame([], $intrus->listAll());

        // Écriture : l'UPDATE et le DELETE portent aussi le scope tenant, sans quoi
        // un identifiant deviné écraserait la batterie d'autrui.
        $intrus->update($id, $this->draft(brand: 'Détourné'));
        $intrus->delete($id);

        $survivor = $owner->find($id);
        self::assertNotNull($survivor, 'La batterie du propriétaire a été supprimée par un tiers');
        self::assertSame('BYD', $survivor->brand, 'La batterie du propriétaire a été modifiée par un tiers');
    }

    /** Le parc se lit de la mise en service la plus récente à la plus ancienne. */
    public function testListIsOrderedByCommissioningDesc(): void
    {
        $repo = new BatteryRepository($this->pdo(), $this->userId);
        $repo->insert($this->draft(brand: 'Ancienne', commissioned: '2024-05-01'));
        $repo->insert($this->draft(brand: 'Récente', commissioned: '2026-05-01'));

        self::assertSame(
            ['Récente', 'Ancienne'],
            array_map(static fn (Battery $b): string => $b->brand, $repo->listAll())
        );
    }

    /** Supprimer une batterie emporte ses index par cascade FK. */
    public function testDeletingABatteryCascadesToItsReadings(): void
    {
        $repo = new BatteryRepository($this->pdo(), $this->userId);
        $id   = $repo->insert($this->draft());

        $this->pdo()->prepare(
            'INSERT INTO battery_readings (battery_id, reading_at, charge_index_kwh, discharge_index_kwh)
             VALUES (:b, :a, :c, :d)'
        )->execute(['b' => $id, 'a' => '2026-06-01 10:00:00', 'c' => 1200.0, 'd' => 1000.0]);

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM battery_readings')->fetchColumn());

        $repo->delete($id);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM battery_readings')->fetchColumn());
    }
}
