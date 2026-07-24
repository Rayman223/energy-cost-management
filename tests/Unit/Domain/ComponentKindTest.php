<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ComponentKind;
use PHPUnit\Framework\TestCase;

final class ComponentKindTest extends TestCase
{
    public function testForEnergyGas(): void
    {
        self::assertSame(
            [ComponentKind::EnergyFlat, ComponentKind::PerKwh, ComponentKind::FixedMonthly, ComponentKind::FixedAnnual],
            ComponentKind::forEnergy('gas'),
        );
    }

    public function testForEnergyWater(): void
    {
        self::assertSame(
            [ComponentKind::PerM3, ComponentKind::FixedMonthly, ComponentKind::FixedAnnual],
            ComponentKind::forEnergy('water'),
        );
    }

    public function testForEnergyElectricityExcludesOnlyPerM3(): void
    {
        $kinds = ComponentKind::forEnergy('electricity');

        self::assertNotContains(ComponentKind::PerM3, $kinds);
        self::assertSame(
            array_values(array_filter(ComponentKind::cases(), static fn (ComponentKind $k): bool => $k !== ComponentKind::PerM3)),
            $kinds,
        );
    }

    public function testForEnergyUnknownFallsBackToElectricity(): void
    {
        self::assertSame(ComponentKind::forEnergy('electricity'), ComponentKind::forEnergy('unknown'));
    }
}
