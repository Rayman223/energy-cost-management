<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeBatteryIngestion;

/**
 * Import en masse des index de batterie (#26).
 *
 * Deux règles décident de ce qui finit en base, et une erreur sur l'une d'elles
 * fausserait tout bilan calculé ensuite : le plafond « un relevé par jour »
 * (un export horaire doit se réduire tout seul, sans erreur) et la complétion
 * d'un compteur encore vide (beaucoup d'onduleurs n'exposent qu'un des deux).
 */
final class BatteryImportTest extends TestCase
{
    private BulkImportService $service;

    protected function setUp(): void
    {
        $this->service = new BulkImportService();
    }

    public function testPresetMapsTheTwoCountersByDefault(): void
    {
        $mapping = ImportMapping::preset('battery');

        self::assertTrue($mapping->isBattery());
        self::assertFalse($mapping->isElectricity());
        self::assertSame(['charge' => 'charge', 'discharge' => 'discharge'], $mapping->registerColumns);
        self::assertNull($mapping->valueColumn);
    }

    public function testPresetAcceptsColumnOverridesAndRejectsUnknownCounters(): void
    {
        $mapping = ImportMapping::preset('battery', ['registers' => ['Batt_In' => 'charge', 'Batt_Out' => 'discharge']]);
        self::assertSame(['batt_in' => 'charge', 'batt_out' => 'discharge'], $mapping->registerColumns);

        $this->expectException(\InvalidArgumentException::class);
        ImportMapping::preset('battery', ['registers' => ['col' => 'import_t1']]);
    }

    /** Un fichier en Wh est ramené aux kWh du stockage, comme pour l'électricité. */
    public function testWattHourFilesAreConvertedToKilowattHours(): void
    {
        $sink = new FakeBatteryIngestion();
        $this->service->importBattery(
            [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200000', 'discharge' => '1000000']],
            ImportMapping::preset('battery', ['unit' => 'wh']),
            $sink
        );

        self::assertSame(1200.0, $sink->stored['2026-01-01 08:00:00']['charge']);
        self::assertSame(1000.0, $sink->stored['2026-01-01 08:00:00']['discharge']);
    }

    public function testCountsImportedDuplicatesAndErrors(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200', 'discharge' => '1000'],
            3 => ['timestamp' => '2026-01-02T08:00:00Z', 'charge' => '1210', 'discharge' => '1008'],
            4 => ['timestamp' => '2026-01-02T08:00:00Z', 'charge' => '1210', 'discharge' => '1008'], // doublon exact
            5 => ['timestamp' => 'bad-date',             'charge' => '1220', 'discharge' => '1016'], // horodatage KO
            6 => ['timestamp' => '2026-01-03T08:00:00Z', 'charge' => 'abc',  'discharge' => '1024'], // valeur KO
            7 => ['timestamp' => '2026-01-04T08:00:00Z', 'charge' => '',     'discharge' => ''],     // ligne vide
        ];

        $report = $this->service->importBattery($rows, ImportMapping::preset('battery'), new FakeBatteryIngestion());

        self::assertSame(4, $report->imported(), '2 lignes × 2 compteurs');
        self::assertSame(2, $report->duplicates(), 'la ligne 4 réécrit les deux compteurs déjà là');
        self::assertSame(3, $report->errors());
    }

    public function testReimportIsIdempotent(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200', 'discharge' => '1000'],
            3 => ['timestamp' => '2026-01-02T08:00:00Z', 'charge' => '1210', 'discharge' => '1008'],
        ];
        $sink = new FakeBatteryIngestion();

        $first = $this->service->importBattery($rows, ImportMapping::preset('battery'), $sink);
        self::assertSame(4, $first->imported());
        self::assertSame(0, $first->duplicates());

        $again = $this->service->importBattery($rows, ImportMapping::preset('battery'), $sink);
        self::assertSame(0, $again->imported());
        self::assertSame(4, $again->duplicates());
    }

    /**
     * Un compteur laissé vide un jour, renseigné par un second fichier le même
     * jour et à la même heure, doit être COMPLÉTÉ : sinon la moitié de l'historique
     * d'un onduleur qui expose ses deux compteurs séparément serait perdue.
     */
    public function testASecondFileFillsTheCounterLeftEmpty(): void
    {
        $sink    = new FakeBatteryIngestion();
        $mapping = ImportMapping::preset('battery');

        $first = $this->service->importBattery([2 => ['timestamp' => '2026-01-01T08:00:00Z', 'discharge' => '1000']], $mapping, $sink);
        self::assertSame(1, $first->imported(), 'seule la décharge est renseignée');

        $second = $this->service->importBattery([2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200']], $mapping, $sink);
        self::assertSame(1, $second->imported());

        $row = $sink->stored['2026-01-01 08:00:00'];
        self::assertSame(1200.0, $row['charge']);
        self::assertSame(1000.0, $row['discharge'], 'la décharge déjà là ne doit pas bouger');
    }

    /** Une valeur déjà présente n'est réécrite qu'en mode « écraser ». */
    public function testOverwriteReplacesAnExistingValue(): void
    {
        $sink    = new FakeBatteryIngestion();
        $mapping = ImportMapping::preset('battery');

        $this->service->importBattery([2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '999']], $mapping, $sink);
        $this->service->importBattery([2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200']], $mapping, $sink, null, true);

        self::assertSame(1200.0, $sink->stored['2026-01-01 08:00:00']['charge']);
    }

    /**
     * Export horaire d'un onduleur : seul le premier relevé du jour est retenu,
     * les suivants sont comptés comme doublons SANS erreur — un plafond qui ferait
     * échouer le fichier obligerait l'utilisateur à le pré-filtrer lui-même.
     */
    public function testOnlyOneReadingPerCivilDayIsKept(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'charge' => '1200', 'discharge' => '1000'],
            3 => ['timestamp' => '2026-01-01T09:00:00Z', 'charge' => '1205', 'discharge' => '1002'],
            4 => ['timestamp' => '2026-01-01T10:00:00Z', 'charge' => '1208', 'discharge' => '1004'],
            5 => ['timestamp' => '2026-01-02T08:00:00Z', 'charge' => '1220', 'discharge' => '1010'],
        ];
        $sink = new FakeBatteryIngestion();

        $report = $this->service->importBattery($rows, ImportMapping::preset('battery'), $sink, null, false, 'UTC');

        self::assertSame(4, $report->imported(), 'un relevé par jour, deux jours, deux compteurs');
        self::assertSame(4, $report->duplicates(), 'les deux relevés surnuméraires du 1er janvier');
        self::assertSame(0, $report->errors());
        self::assertSame(['2026-01-01 08:00:00', '2026-01-02 08:00:00'], array_keys($sink->stored));
    }

    /**
     * Le jour est celui de l'UTILISATEUR : à Bruxelles, 23:00 UTC le 1er janvier
     * appartient déjà au 2 janvier. Découper sur UTC rejetterait le relevé du
     * lendemain comme doublon, et en laisserait le jour suivant sans relevé.
     */
    public function testTheDailyCapFollowsTheUserTimezone(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T10:00:00Z', 'charge' => '1200'],
            3 => ['timestamp' => '2026-01-01T23:00:00Z', 'charge' => '1210'],
        ];

        $utc = new FakeBatteryIngestion();
        $this->service->importBattery($rows, ImportMapping::preset('battery'), $utc, null, false, 'UTC');
        self::assertCount(1, $utc->stored, 'même jour civil en UTC');

        $brussels = new FakeBatteryIngestion();
        $this->service->importBattery($rows, ImportMapping::preset('battery'), $brussels, null, false, 'Europe/Brussels');
        self::assertCount(2, $brussels->stored, '23:00 UTC est déjà le lendemain à Bruxelles');
    }
}
