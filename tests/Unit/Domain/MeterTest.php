<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Meter;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Compteur du parc (#55).
 *
 * Deux propriétés portent tout le reste : le libellé vide est un ÉTAT NORMAL qui
 * se dérive à l'affichage (et non un trou à combler en base, ce qui figerait du
 * français dans une installation néerlandophone), et la fermeture est une borne
 * EXCLUE — le jour de fermeture est déjà hors service (#1).
 */
final class MeterTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function testUnnamedMeterDerivesItsLabelFromTheEnergy(): void
    {
        $meter = new Meter(id: 1, energyType: 'water');

        self::assertFalse($meter->isNamed());
        self::assertSame('meters.default_label.water', $meter->defaultLabelKey());
        self::assertSame('meters.energy.water', $meter->energyLabelKey());
    }

    /**
     * Un libellé fait uniquement d'espaces n'est pas un nom : l'afficher rendrait
     * une ligne vide dans la liste, impossible à distinguer des autres.
     */
    public function testWhitespaceOnlyLabelCountsAsUnnamed(): void
    {
        self::assertFalse((new Meter(id: 1, energyType: 'gas', label: "  \t "))->isNamed());
        self::assertTrue((new Meter(id: 1, energyType: 'gas', label: 'Atelier'))->isNamed());
    }

    public function testKnownEnergiesAreTheThreeOfTheEnum(): void
    {
        self::assertSame(['electricity', 'gas', 'water'], Meter::ENERGIES);

        foreach (Meter::ENERGIES as $energy) {
            self::assertTrue(Meter::isEnergy($energy));
        }

        self::assertFalse(Meter::isEnergy('battery'));
        self::assertFalse(Meter::isEnergy('Electricity')); // sensible à la casse : c'est une valeur d'ENUM
        self::assertFalse(Meter::isEnergy(''));
    }

    /**
     * Le constructeur refuse une énergie inconnue plutôt que de la porter : sans
     * cela, `defaultLabelKey()` fabriquerait une clé absente du catalogue, qui
     * s'afficherait telle quelle à l'écran.
     */
    public function testConstructorRejectsAnUnknownEnergy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Meter(id: 1, energyType: 'heating_oil');
    }

    public function testClosureBoundIsExclusive(): void
    {
        $meter = new Meter(id: 1, energyType: 'electricity', closedOn: $this->at('2026-06-15'));

        self::assertFalse($meter->isClosedOn($this->at('2026-06-14')));
        // Le jour de fermeture lui-même est DÉJÀ hors service (#1), comme
        // `batteries.decommissioned_on`.
        self::assertTrue($meter->isClosedOn($this->at('2026-06-15')));
        self::assertTrue($meter->isClosedOn($this->at('2026-06-16')));
    }

    /** L'heure du jour testé ne doit pas peser : la comparaison porte sur la date. */
    public function testClosureIgnoresTimeOfDay(): void
    {
        $meter = new Meter(id: 1, energyType: 'gas', closedOn: $this->at('2026-06-15'));

        self::assertTrue($meter->isClosedOn(new DateTimeImmutable('2026-06-15 23:59:59', new DateTimeZone('UTC'))));
        self::assertFalse($meter->isClosedOn(new DateTimeImmutable('2026-06-14 23:59:59', new DateTimeZone('UTC'))));
    }

    public function testAnOpenMeterIsNeverClosed(): void
    {
        $meter = new Meter(id: 1, energyType: 'electricity');

        self::assertNull($meter->closedOn);
        self::assertFalse($meter->isClosedOn($this->at('2099-01-01')));
    }

    public function testFromRowParsesTheDateInUtc(): void
    {
        $meter = Meter::fromRow([
            'id'          => '42',
            'energy_type' => 'gas',
            'label'       => 'Atelier',
            'closed_on'   => '2026-03-01',
        ]);

        self::assertSame(42, $meter->id);
        self::assertSame('gas', $meter->energyType);
        self::assertSame('Atelier', $meter->label);
        self::assertNotNull($meter->closedOn);
        self::assertSame('2026-03-01 00:00:00', $meter->closedOn->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $meter->closedOn->getTimezone()->getName());
    }

    public function testFromRowKeepsAnOpenMeterOpen(): void
    {
        $meter = Meter::fromRow([
            'id'          => 7,
            'energy_type' => 'electricity',
            'label'       => '',
            'closed_on'   => null,
        ]);

        self::assertNull($meter->closedOn);
        self::assertFalse($meter->isNamed());
    }
}
