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
        self::assertNull($meter->openedOn);
        self::assertFalse($meter->isNamed());
    }

    // ── Mise en service (#81) ────────────────────────────────────────────────

    /**
     * La borne de début est INCLUSE, à l'inverse de la fermeture : le jour de mise
     * en service est le premier jour COUVERT. La convention #1 ne porte que sur
     * les dates de fin — `opened_on` se lit comme `tariff_grids.valid_from`.
     */
    public function testCommissioningBoundIsInclusive(): void
    {
        $meter = new Meter(id: 1, energyType: 'electricity', openedOn: $this->at('2026-06-15'));

        self::assertTrue($meter->isNotInServiceYetOn($this->at('2026-06-14')));
        self::assertFalse($meter->isNotInServiceYetOn($this->at('2026-06-15')));
        self::assertFalse($meter->isNotInServiceYetOn($this->at('2026-06-16')));
    }

    /** Sans date de pose, le compteur est réputé là depuis toujours. */
    public function testAMeterWithoutACommissioningDateIsAlwaysInService(): void
    {
        $meter = new Meter(id: 1, energyType: 'water');

        self::assertFalse($meter->isNotInServiceYetOn($this->at('1999-01-01')));
        self::assertTrue($meter->isInServiceOn($this->at('1999-01-01')));
    }

    /**
     * Les deux bornes se combinent : en service entre la pose et la fermeture,
     * hors service avant l'une comme à partir de l'autre. Ce sont trois états, pas
     * deux — « pas encore posé » ne se lit pas comme « fermé ».
     */
    public function testServiceWindowCombinesBothBounds(): void
    {
        $meter = new Meter(
            id:         1,
            energyType: 'electricity',
            closedOn:   $this->at('2026-07-01'),
            openedOn:   $this->at('2026-06-15'),
        );

        self::assertFalse($meter->isInServiceOn($this->at('2026-06-14')));
        self::assertTrue($meter->isInServiceOn($this->at('2026-06-15')));
        self::assertTrue($meter->isInServiceOn($this->at('2026-06-30')));
        self::assertFalse($meter->isInServiceOn($this->at('2026-07-01')));

        // Et les deux états restent distincts, ce que l'affichage exploite.
        self::assertTrue($meter->isNotInServiceYetOn($this->at('2026-06-14')));
        self::assertFalse($meter->isClosedOn($this->at('2026-06-14')));
    }

    /**
     * `serviceInstantFor()` situe la date dans le fuseau du foyer, comme son
     * pendant de fermeture : un compteur posé le 15 en UTC−5 l'est à partir du 15
     * à 05 h UTC, pas du 15 à minuit UTC.
     */
    public function testServiceInstantIsReadInTheUserTimezone(): void
    {
        $instant = Meter::serviceInstantFor('2026-06-15', 'America/New_York');

        self::assertNotNull($instant);
        self::assertSame('2026-06-15 04:00:00', $instant->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $instant->getTimezone()->getName());
    }

    /** Fuseau illisible : repli sur UTC plutôt qu'une exception (comme en fermeture). */
    public function testServiceInstantFallsBackOnUtcForAnUnknownTimezone(): void
    {
        $instant = Meter::serviceInstantFor('2026-06-15', 'Mars/Olympus_Mons');

        self::assertNotNull($instant);
        self::assertSame('2026-06-15 00:00:00', $instant->format('Y-m-d H:i:s'));
    }

    public function testServiceInstantIsNullWithoutADate(): void
    {
        self::assertNull(Meter::serviceInstantFor(null, 'UTC'));
        self::assertNull(Meter::serviceInstantFor('', 'UTC'));
    }

    /**
     * `opened_on` absente de la ligne — une lecture qui ne sélectionne pas la
     * colonne — ne casse pas l'hydratation : le compteur est alors « depuis
     * toujours », ce qui est l'état de tout le parc antérieur à la migration.
     */
    public function testFromRowAcceptsARowWithoutTheCommissioningColumn(): void
    {
        $meter = Meter::fromRow([
            'id'          => 3,
            'energy_type' => 'electricity',
            'label'       => 'Maison',
            'closed_on'   => null,
        ]);

        self::assertNull($meter->openedOn);
    }

    public function testFromRowParsesBothLifecycleDatesInUtc(): void
    {
        $meter = Meter::fromRow([
            'id'          => 9,
            'energy_type' => 'electricity',
            'label'       => 'Atelier',
            'closed_on'   => '2026-07-01',
            'opened_on'   => '2026-06-15',
        ]);

        self::assertNotNull($meter->openedOn);
        self::assertSame('2026-06-15 00:00:00', $meter->openedOn->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $meter->openedOn->getTimezone()->getName());
        self::assertNotNull($meter->closedOn);
        self::assertSame('2026-07-01 00:00:00', $meter->closedOn->format('Y-m-d H:i:s'));
    }
}
