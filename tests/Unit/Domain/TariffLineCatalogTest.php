<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ComponentKind;
use App\Domain\TariffLineCatalog;
use PHPUnit\Framework\TestCase;

final class TariffLineCatalogTest extends TestCase
{
    public function testElectricityHasExpectedKeys(): void
    {
        $keys = array_keys(TariffLineCatalog::electricity());

        self::assertCount(17, $keys);
        self::assertContains('energy_t1', $keys);
        self::assertContains('injection_t2', $keys);
        self::assertContains('spot_coefficient', $keys);
        self::assertContains('spot_offset', $keys);
    }

    /** Les clés spot ne concernent que l'électricité (#228). */
    public function testSpotKeysAreElectricityOnly(): void
    {
        foreach (['spot_coefficient', 'spot_offset'] as $key) {
            self::assertArrayNotHasKey($key, TariffLineCatalog::gas());
            self::assertArrayNotHasKey($key, TariffLineCatalog::water());
        }
    }

    public function testKindForMapsSpotKeys(): void
    {
        self::assertSame(ComponentKind::SpotCoefficient, TariffLineCatalog::kindFor('electricity', 'spot_coefficient'));
        self::assertSame(ComponentKind::SpotOffset, TariffLineCatalog::kindFor('electricity', 'spot_offset'));
    }

    /**
     * Le mapping ne doit PAS être restreint à l'électricité : le repli par défaut est
     * per_kwh, qui facturerait un coefficient 1,08 comme 1,08 €/kWh sur une grille gaz
     * reçue par l'API (~1620 € sur 1500 kWh), le garde-fou isSpotFormula() ne pouvant
     * pas s'activer sur un kind erroné.
     */
    public function testKindForMapsSpotKeysOnEveryEnergyType(): void
    {
        foreach (['gas', 'water'] as $energy) {
            self::assertSame(ComponentKind::SpotCoefficient, TariffLineCatalog::kindFor($energy, 'spot_coefficient'), $energy);
            self::assertSame(ComponentKind::SpotOffset, TariffLineCatalog::kindFor($energy, 'spot_offset'), $energy);
        }
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

    /**
     * KEY_PATTERN est la contrainte appliquée aux deux portes d'entrée (#265) :
     * une clé du catalogue qui ne la respecterait pas serait refusée à
     * l'enregistrement, alors que le formulaire la propose.
     */
    public function testEveryCatalogKeyMatchesKeyPattern(): void
    {
        foreach (['electricity', 'gas', 'water'] as $energy) {
            foreach (TariffLineCatalog::keysFor($energy) as $key) {
                self::assertSame(1, preg_match(TariffLineCatalog::KEY_PATTERN, $key), "$energy: $key");
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
        // Multiplicateur sans dimension, à ne pas afficher en €/kWh.
        self::assertSame('×', $elec['spot_coefficient']['unit']);
        // « TTC » explicite : la fiche tarifaire donne souvent la marge HTVA.
        self::assertStringContainsString('TTC', $elec['spot_offset']['label']);

        $gas = TariffLineCatalog::gas();
        self::assertSame('Énergie fournisseur', $gas['energy']['label']);
        self::assertSame('€/mois', $gas['subscription']['unit']);

        $water = TariffLineCatalog::water();
        self::assertSame('Consommation — fourniture', $water['water_supply']['label']);
        self::assertSame('€/m³', $water['water_supply']['unit']);
    }
}
