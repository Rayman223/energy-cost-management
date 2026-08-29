<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ReadingGranularity;
use App\Service\ReadingGranularityPolicy;
use App\Repository\Contract\UtilityIngestionInterface;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeElectricityIngestion;
use Tests\Fake\FakeUtilityIngestion;

final class BulkImportServiceTest extends TestCase
{
    private BulkImportService $service;

    protected function setUp(): void
    {
        $this->service = new BulkImportService();
    }

    public function testUtilityImportCountsImportedDuplicatesAndErrors(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' =>'100.5'],
            3 => ['timestamp' => '2026-01-02T08:00:00Z', 'value' =>'101.25'],
            4 => ['timestamp' => '2026-01-02T08:00:00Z', 'value' =>'101.25'], // doublon
            5 => ['timestamp' => 'bad-date',            'value' =>'50'],     // horodatage KO
            6 => ['timestamp' => '2026-01-03T08:00:00Z', 'value' =>'-3'],     // valeur KO
            7 => ['timestamp' => '2026-01-04T08:00:00Z', 'value' =>''],       // valeur absente
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), new FakeUtilityIngestion());

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->duplicates());
        self::assertSame(3, $report->errors());
        self::assertCount(3, $report->errorSamples());
    }

    public function testUtilityImportIsIdempotentOnReimport(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' =>'10'],
            3 => ['timestamp' => '2026-01-02T08:00:00Z', 'value' =>'11'],
        ];
        $sink = new FakeUtilityIngestion();

        $first = $this->service->importUtility($rows, ImportMapping::preset('water'), $sink);
        self::assertSame(2, $first->imported());
        self::assertSame(0, $first->duplicates());

        $second = $this->service->importUtility($rows, ImportMapping::preset('water'), $sink);
        self::assertSame(0, $second->imported());
        self::assertSame(2, $second->duplicates());
    }

    public function testUtilityReimportWithReplaceOverwritesInsteadOfIgnoring(): void
    {
        // Cas de l'issue #162 : import fautif, puis ré-import corrigé au même
        // horodatage avec « écraser » → la valeur est mise à jour, pas ignorée.
        $sink = new FakeUtilityIngestion();

        $wrong = [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' => '100']];
        $this->service->importUtility($wrong, ImportMapping::preset('water'), $sink);
        self::assertSame(100.0, $sink->saved['2026-01-01 08:00:00']);

        $fixed  = [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' => '10']];
        $report = $this->service->importUtility($fixed, ImportMapping::preset('water'), $sink, null, true);

        self::assertSame(10.0, $sink->saved['2026-01-01 08:00:00'], 'la valeur doit être écrasée');
        self::assertSame(1, $report->imported(), 'la ligne écrasée compte comme importée');
        self::assertSame(0, $report->duplicates());
    }

    public function testUtilityReimportWithReplaceCountsIdenticalValueAsDuplicate(): void
    {
        // Réécrire la même valeur ne « touche » aucune ligne (ON DUPLICATE KEY
        // UPDATE renvoie 0) → comptée comme doublon, pas comme import.
        $sink = new FakeUtilityIngestion();
        $rows = [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' => '42']];

        $this->service->importUtility($rows, ImportMapping::preset('gas'), $sink);
        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), $sink, null, true);

        self::assertSame(0, $report->imported());
        self::assertSame(1, $report->duplicates());
    }

    public function testElectricityReimportWithReplaceOverwritesExistingIndexes(): void
    {
        $sink = new FakeElectricityIngestion();
        $rows = [2 => ['timestamp' => '2026-01-01T00:00:00Z', 'import_t1' => '1000', 'import_t2' => '2000']];

        $first = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), $sink);
        self::assertSame(2, $first->imported());

        // Sans replace : doublons ignorés.
        $second = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), $sink);
        self::assertSame(0, $second->imported());
        self::assertSame(2, $second->duplicates());

        // Avec replace : les 2 registres sont réécrits (comptés importés).
        $third = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), $sink, null, true);
        self::assertSame(2, $third->imported());
        self::assertSame(0, $third->duplicates());
    }

    public function testUtilityImportHonoursValueColumnOverride(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'gaz naturel' => '42.0']];

        $report = $this->service->importUtility(
            $rows,
            ImportMapping::preset('gas', ['value_col' => 'Gaz naturel']),
            new FakeUtilityIngestion(),
        );

        self::assertSame(1, $report->imported());
        self::assertSame(0, $report->errors());
    }

    public function testUtilityImportConvertsLitresToCubicMetres(): void
    {
        // Compteur d'eau exporté en litres : 1500 L doivent être stockés en m³.
        $rows = [2 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' =>'1500']];
        $sink = new FakeUtilityIngestion();

        $report = $this->service->importUtility($rows, ImportMapping::preset('water', ['unit' => 'l']), $sink);

        self::assertSame(1, $report->imported());
        self::assertSame(1.5, $sink->saved['2026-01-01 08:00:00']);
    }

    public function testElectricityImportConvertsWattHoursToKilowattHours(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01T00:00:00Z', 'import_t1' => '2000000']];
        $sink = new FakeElectricityIngestion();

        $this->service->importElectricity($rows, ImportMapping::preset('electricity', ['unit' => 'wh']), $sink);

        self::assertSame(2000.0, $sink->calls[0]['indexes']['import_t1']);
    }

    public function testElectricityImportCountsPerRegisterAndDedups(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01T00:00:00Z', 'import_t1' => '1000', 'import_t2' => '2000', 'export_t1' => '50'],
            3 => ['timestamp' => '2026-01-01T00:00:00Z', 'import_t1' => '1000', 'import_t2' => '2000', 'export_t1' => '50'], // doublons
            4 => ['timestamp' => '2026-01-02T00:00:00Z', 'import_t1' => 'abc'], // invalide → ligne en erreur
        ];

        $report = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), new FakeElectricityIngestion());

        self::assertSame(3, $report->imported());
        self::assertSame(3, $report->duplicates());
        self::assertSame(1, $report->errors());
    }

    public function testDailyLimitSkipsSameRegisterSameDayAsDuplicate(): void
    {
        // Tarif fixe (Day) : deux relevés du même registre le même jour à des heures
        // différentes → le 2e est compté en doublon (skip silencieux).
        $rows = [
            2 => ['timestamp' => '2026-01-01T07:00:00Z', 'import_t1' => '1000'],
            3 => ['timestamp' => '2026-01-01T18:00:00Z', 'import_t1' => '1010'],
        ];

        $report = $this->service->importElectricity(
            $rows,
            ImportMapping::preset('electricity'),
            new FakeElectricityIngestion(),
            null,
            false,
            ReadingGranularityPolicy::constant(ReadingGranularity::Day, 'UTC'),
        );

        self::assertSame(1, $report->imported());
        self::assertSame(1, $report->duplicates());
    }

    public function testDailyLimitKeepsDistinctRegistersSameDay(): void
    {
        // import_t1 le matin, production le soir : registres indépendants → tout importé.
        $rows = [
            2 => ['timestamp' => '2026-01-01T07:00:00Z', 'import_t1' => '1000'],
            3 => ['timestamp' => '2026-01-01T18:00:00Z', 'production' => '200'],
        ];

        $report = $this->service->importElectricity(
            $rows,
            ImportMapping::preset('electricity'),
            new FakeElectricityIngestion(),
            null,
            false,
            ReadingGranularityPolicy::constant(ReadingGranularity::Day, 'UTC'),
        );

        self::assertSame(2, $report->imported());
        self::assertSame(0, $report->duplicates());
    }

    public function testQuarterHourLimitKeepsOnePerAlignedSlot(): void
    {
        // Tarif dynamique (QuarterHour) : dans un même quart d'heure aligné, un seul
        // index par registre ; le créneau suivant repasse.
        $rows = [
            2 => ['timestamp' => '2026-01-01T07:02:00Z', 'import_t1' => '1000'], // [07:00–07:15)
            3 => ['timestamp' => '2026-01-01T07:12:00Z', 'import_t1' => '1001'], // même créneau → doublon
            4 => ['timestamp' => '2026-01-01T07:20:00Z', 'import_t1' => '1002'], // [07:15–07:30) → importé
        ];

        $report = $this->service->importElectricity(
            $rows,
            ImportMapping::preset('electricity'),
            new FakeElectricityIngestion(),
            null,
            false,
            ReadingGranularityPolicy::constant(ReadingGranularity::QuarterHour, 'UTC'),
        );

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->duplicates());
    }

    public function testElectricityImportRequiresAtLeastOneRegister(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01T00:00:00Z']]; // aucun registre

        $report = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), new FakeElectricityIngestion());

        self::assertSame(0, $report->imported());
        self::assertSame(1, $report->errors());
    }

    public function testTimestampsWithoutOffsetAreRejected(): void
    {
        // Fuseau obligatoire (#175) : les horodatages nus (sans offset), le
        // date-only et les valeurs souples (« now », « +1 day »…) sont refusés.
        // Seul un ISO 8601 avec offset explicite passe.
        $rows = [
            2 => ['timestamp' => 'now',                       'value' =>'10'],
            3 => ['timestamp' => '+1 day',                    'value' =>'10'],
            4 => ['timestamp' => '2026-01-01 08:00:00',       'value' =>'10'], // nu → refusé
            5 => ['timestamp' => '2026-01-02',                'value' =>'10'], // date seule → refusée
            6 => ['timestamp' => '2026-01-01T08:00:00+02:00', 'value' =>'10'], // OK
            7 => ['timestamp' => '2026-01-02T08:00:00Z',      'value' =>'10'], // OK
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), new FakeUtilityIngestion());

        self::assertSame(2, $report->imported());
        self::assertSame(4, $report->errors());
    }

    public function testDbErrorOnOneRowDoesNotAbortTheOthers(): void
    {
        // Une erreur base sur une ligne (ex. dépassement DECIMAL) est comptée et
        // n'annule pas les autres lignes (bug #2), sans fuiter de détail (bug #4).
        $sink = new class implements UtilityIngestionInterface {
            public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3, bool $replace = false): bool
            {
                if ($counterM3 === 999.0) {
                    throw new \RuntimeException('SQLSTATE[22003]: numeric value out of range');
                }

                return true;
            }
        };

        $rows = [
            2 => ['timestamp' => '2026-01-01T00:00:00Z', 'value' =>'1'],
            3 => ['timestamp' => '2026-01-02T00:00:00Z', 'value' =>'999'], // lève
            4 => ['timestamp' => '2026-01-03T00:00:00Z', 'value' =>'2'],
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), $sink);

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->errors());
        // L'erreur base est comptée comme échec réel (writeError), pas une simple
        // ligne ignorée → sert au code de sortie CLI (#75).
        self::assertSame(1, $report->writeErrors());
        self::assertStringNotContainsString('SQLSTATE', implode(' ', $report->errorSamples()));
    }

    public function testValidationErrorsAreNotCountedAsWriteErrors(): void
    {
        // Lignes ignorées pour cause de validation (horodatage/valeur) : comptées
        // dans errors() mais PAS dans writeErrors() → le process CLI sort en 0.
        $rows = [
            2 => ['timestamp' => 'bad-date',            'value' =>'10'],
            3 => ['timestamp' => '2026-01-01T08:00:00Z', 'value' =>'-5'],
            4 => ['timestamp' => '2026-01-02T08:00:00Z', 'value' =>'12'], // OK
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), new FakeUtilityIngestion());

        self::assertSame(1, $report->imported());
        self::assertSame(2, $report->errors());
        self::assertSame(0, $report->writeErrors());
    }
}
