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

    public function testSpotKindsAreProposedForElectricityOnly(): void
    {
        foreach ([ComponentKind::SpotCoefficient, ComponentKind::SpotOffset] as $kind) {
            self::assertContains($kind, ComponentKind::forEnergy('electricity'));
            self::assertNotContains($kind, ComponentKind::forEnergy('gas'));
            self::assertNotContains($kind, ComponentKind::forEnergy('water'));
        }
    }

    public function testOnlySpotKindsAreFormulaParameters(): void
    {
        foreach (ComponentKind::cases() as $kind) {
            $expected = $kind === ComponentKind::SpotCoefficient || $kind === ComponentKind::SpotOffset;
            self::assertSame($expected, $kind->isSpotFormula(), $kind->value);
        }
    }

    /**
     * Distinction structurante (#228) : les kinds spot PARAMÈTRENT le prix de marché,
     * ils ne sont pas remplacés par lui. Les confondre les ferait facturer en tarif fixe.
     */
    public function testSpotKindsAreNotSupplierEnergy(): void
    {
        self::assertFalse(ComponentKind::SpotCoefficient->isSupplierEnergy());
        self::assertFalse(ComponentKind::SpotOffset->isSupplierEnergy());
    }

    public function testSpotKindsAreGroupedWithEnergy(): void
    {
        self::assertSame('energy', ComponentKind::SpotCoefficient->group());
        self::assertSame('energy', ComponentKind::SpotOffset->group());
    }

    public function testSpotCoefficientIsDimensionlessWhileOffsetIsPerKwh(): void
    {
        self::assertSame('×', ComponentKind::SpotCoefficient->unit('electricity'));
        self::assertSame('€/kWh', ComponentKind::SpotOffset->unit('electricity'));
    }
}
