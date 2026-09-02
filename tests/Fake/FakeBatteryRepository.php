<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Repository\Contract\BatteryRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parc de batteries en mémoire (#26), pour tester la résolution de la batterie
 * visée sans base de données.
 */
final class FakeBatteryRepository implements BatteryRepositoryInterface
{
    /** @var array<int, Battery> */
    private array $fleet = [];

    /** @param list<int> $ids Identifiants du parc, dans l'ordre d'affichage. */
    public function __construct(array $ids = [])
    {
        foreach ($ids as $id) {
            $this->fleet[$id] = self::battery($id);
        }
    }

    public static function battery(int $id): Battery
    {
        return new Battery(
            id: $id,
            brand: 'Marque ' . $id,
            model: 'Modèle',
            capacityKwh: 10.0,
            commissionedOn: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            dischargeProfile: BatteryDischargeProfile::ImportMix,
        );
    }

    /** @return list<Battery> */
    public function listAll(): array
    {
        return array_values($this->fleet);
    }

    public function find(int $id): ?Battery
    {
        return $this->fleet[$id] ?? null;
    }

    public function owns(int $id): bool
    {
        return isset($this->fleet[$id]);
    }

    public function insert(Battery $battery): int
    {
        $id = $this->fleet === [] ? 1 : max(array_keys($this->fleet)) + 1;
        $this->fleet[$id] = $battery;

        return $id;
    }

    public function update(int $id, Battery $battery): void
    {
        if (isset($this->fleet[$id])) {
            $this->fleet[$id] = $battery;
        }
    }

    public function delete(int $id): void
    {
        unset($this->fleet[$id]);
    }
}
