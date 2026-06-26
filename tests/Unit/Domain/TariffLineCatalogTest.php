<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\TariffLineCatalog;
use PHPUnit\Framework\TestCase;

final class TariffLineCatalogTest extends TestCase
{
    public function testElectricityHasExpectedKeys(): void
    {
        $keys = array_keys(TariffLineCatalog::electricity());

        self::assertCount(15, $keys);
        self::assertContains('energy_t1', $keys);
        self::assertContains('injection_t2', $keys);
    }

    public function testGasHasExpectedKeys(): void
    {
        $keys = array_keys(TariffLineCatalog::gas());

        self::assertCount(10, $keys);
        self::assertContains('energy', $keys);
        self::assertContains('connection_fee_kwh', $keys);
    }

    public function testEveryEntryHasLabelAndUnit(): void
    {
        foreach ([TariffLineCatalog::electricity(), TariffLineCatalog::gas()] as $set) {
            foreach ($set as $key => $def) {
                self::assertArrayHasKey('label', $def, "label manquant pour $key");
                self::assertArrayHasKey('unit', $def, "unit manquant pour $key");
                self::assertNotSame('', $def['label']);
            }
        }
    }

    public function testKeysForMatchesDefinitionKeys(): void
    {
        self::assertSame(array_keys(TariffLineCatalog::electricity()), TariffLineCatalog::keysFor('electricity'));
        self::assertSame(array_keys(TariffLineCatalog::gas()), TariffLineCatalog::keysFor('gas'));
    }

    public function testForTypeDefaultsToElectricity(): void
    {
        self::assertSame(TariffLineCatalog::gas(), TariffLineCatalog::forType('gas'));
        self::assertSame(TariffLineCatalog::electricity(), TariffLineCatalog::forType('electricity'));
        // tout type non-gaz retombe sur l'électricité
        self::assertSame(TariffLineCatalog::electricity(), TariffLineCatalog::forType('unknown'));
    }

    public function testSpecificLabelsAndUnitsAreStable(): void
    {
        $elec = TariffLineCatalog::electricity();
        self::assertSame('Énergie T1 (jour)', $elec['energy_t1']['label']);
        self::assertSame('€/kWh', $elec['energy_t1']['unit']);
        self::assertSame("Droit d'accise spécial", $elec['excise_duty']['label']);
        self::assertSame('€/an', $elec['management_annual']['unit']);

        $gas = TariffLineCatalog::gas();
        self::assertSame('Énergie fournisseur', $gas['energy']['label']);
        self::assertSame('€/mois', $gas['subscription']['unit']);
    }
}
