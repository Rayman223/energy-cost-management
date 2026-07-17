<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\ImportMapping;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImportMappingTest extends TestCase
{
    public function testElectricityDefaultsMapEachRegisterToItself(): void
    {
        $m = ImportMapping::preset('electricity');

        self::assertTrue($m->isElectricity());
        self::assertSame('timestamp', $m->timestampColumn);
        self::assertSame('import_t1', $m->registerColumns['import_t1']);
        self::assertArrayHasKey('production', $m->registerColumns);
    }

    public function testUtilityDefaults(): void
    {
        $m = ImportMapping::preset('gas');

        self::assertFalse($m->isElectricity());
        self::assertSame('value', $m->valueColumn);
    }

    public function testOverridesAreNormalized(): void
    {
        $m = ImportMapping::preset('water', ['ts_col' => ' Date ', 'value_col' => ' Eau ']);

        self::assertSame('date', $m->timestampColumn);
        self::assertSame('eau', $m->valueColumn);
    }

    public function testElectricityRegisterOverride(): void
    {
        $m = ImportMapping::preset('electricity', ['registers' => ['Prelev' => 'import_t1']]);

        self::assertSame(['prelev' => 'import_t1'], $m->registerColumns);
    }

    public function testInvalidEnergyTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportMapping::preset('oil');
    }

    public function testInvalidRegisterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportMapping::preset('electricity', ['registers' => ['col' => 'nonexistent']]);
    }

    public function testDefaultUnitFactorIsCanonical(): void
    {
        self::assertSame(1.0, ImportMapping::preset('electricity')->unitToCanonicalFactor);
        self::assertSame(1.0, ImportMapping::preset('gas')->unitToCanonicalFactor);
        self::assertSame(1.0, ImportMapping::preset('water')->unitToCanonicalFactor);
    }

    public function testSubUnitConvertsToCanonical(): void
    {
        // Wh → kWh et litre → m³ : facteur 0.001 (÷1000).
        self::assertSame(0.001, ImportMapping::preset('electricity', ['unit' => 'wh'])->unitToCanonicalFactor);
        self::assertSame(0.001, ImportMapping::preset('water', ['unit' => 'l'])->unitToCanonicalFactor);
        self::assertSame(1.0, ImportMapping::preset('water', ['unit' => 'm3'])->unitToCanonicalFactor);
    }

    public function testUnitIsNormalized(): void
    {
        self::assertSame(0.001, ImportMapping::preset('water', ['unit' => ' L '])->unitToCanonicalFactor);
    }

    public function testUnitNotAllowedForTypeFallsBackToCanonical(): void
    {
        // Une unité non proposée pour le type (ex. requête sans JS soumettant
        // l'unité d'un autre type) retombe sur l'unité canonique (facteur 1) au
        // lieu d'échouer — jamais de diviseur de sous-unité appliqué par erreur.
        self::assertSame(1.0, ImportMapping::preset('gas', ['unit' => 'l'])->unitToCanonicalFactor);
        self::assertSame(1.0, ImportMapping::preset('water', ['unit' => 'wh'])->unitToCanonicalFactor);
        self::assertSame(1.0, ImportMapping::preset('electricity', ['unit' => 'l'])->unitToCanonicalFactor);
    }
}
