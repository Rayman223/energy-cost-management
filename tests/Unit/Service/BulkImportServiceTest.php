<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

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
            2 => ['timestamp' => '2026-01-01 08:00:00', 'value' =>'100.5'],
            3 => ['timestamp' => '2026-01-02 08:00:00', 'value' =>'101.25'],
            4 => ['timestamp' => '2026-01-02 08:00:00', 'value' =>'101.25'], // doublon
            5 => ['timestamp' => 'bad-date',            'value' =>'50'],     // horodatage KO
            6 => ['timestamp' => '2026-01-03 08:00:00', 'value' =>'-3'],     // valeur KO
            7 => ['timestamp' => '2026-01-04 08:00:00', 'value' =>''],       // valeur absente
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
            2 => ['timestamp' => '2026-01-01 08:00:00', 'value' =>'10'],
            3 => ['timestamp' => '2026-01-02 08:00:00', 'value' =>'11'],
        ];
        $sink = new FakeUtilityIngestion();

        $first = $this->service->importUtility($rows, ImportMapping::preset('water'), $sink);
        self::assertSame(2, $first->imported());
        self::assertSame(0, $first->duplicates());

        $second = $this->service->importUtility($rows, ImportMapping::preset('water'), $sink);
        self::assertSame(0, $second->imported());
        self::assertSame(2, $second->duplicates());
    }

    public function testUtilityImportHonoursValueColumnOverride(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01 08:00:00', 'gaz naturel' => '42.0']];

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
        $rows = [2 => ['timestamp' => '2026-01-01 08:00:00', 'value' =>'1500']];
        $sink = new FakeUtilityIngestion();

        $report = $this->service->importUtility($rows, ImportMapping::preset('water', ['unit' => 'l']), $sink);

        self::assertSame(1, $report->imported());
        self::assertSame(1.5, $sink->saved['2026-01-01 08:00:00']);
    }

    public function testElectricityImportConvertsWattHoursToKilowattHours(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01 00:00:00', 'import_t1' => '2000000']];
        $sink = new FakeElectricityIngestion();

        $this->service->importElectricity($rows, ImportMapping::preset('electricity', ['unit' => 'wh']), $sink);

        self::assertSame(2000.0, $sink->calls[0]['indexes']['import_t1']);
    }

    public function testElectricityImportCountsPerRegisterAndDedups(): void
    {
        $rows = [
            2 => ['timestamp' => '2026-01-01 00:00:00', 'import_t1' => '1000', 'import_t2' => '2000', 'export_t1' => '50'],
            3 => ['timestamp' => '2026-01-01 00:00:00', 'import_t1' => '1000', 'import_t2' => '2000', 'export_t1' => '50'], // doublons
            4 => ['timestamp' => '2026-01-02 00:00:00', 'import_t1' => 'abc'], // invalide → ligne en erreur
        ];

        $report = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), new FakeElectricityIngestion());

        self::assertSame(3, $report->imported());
        self::assertSame(3, $report->duplicates());
        self::assertSame(1, $report->errors());
    }

    public function testElectricityImportRequiresAtLeastOneRegister(): void
    {
        $rows = [2 => ['timestamp' => '2026-01-01 00:00:00']]; // aucun registre

        $report = $this->service->importElectricity($rows, ImportMapping::preset('electricity'), new FakeElectricityIngestion());

        self::assertSame(0, $report->imported());
        self::assertSame(1, $report->errors());
    }

    public function testLenientRelativeDatesAreRejected(): void
    {
        // « now », « 2026 », « +1 day » seraient datés à l'instant de l'import et
        // casseraient l'idempotence → doivent être rejetés (bug #1).
        $rows = [
            2 => ['timestamp' => 'now',                 'value' =>'10'],
            3 => ['timestamp' => '2026',                'value' =>'10'],
            4 => ['timestamp' => '+1 day',              'value' =>'10'],
            5 => ['timestamp' => '2026-01-01 08:00:00', 'value' =>'10'], // OK
            6 => ['timestamp' => '2026-01-02',          'value' =>'10'], // OK (date seule → minuit)
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), new FakeUtilityIngestion());

        self::assertSame(2, $report->imported());
        self::assertSame(3, $report->errors());
    }

    public function testDbErrorOnOneRowDoesNotAbortTheOthers(): void
    {
        // Une erreur base sur une ligne (ex. dépassement DECIMAL) est comptée et
        // n'annule pas les autres lignes (bug #2), sans fuiter de détail (bug #4).
        $sink = new class implements UtilityIngestionInterface {
            public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3): bool
            {
                if ($counterM3 === 999.0) {
                    throw new \RuntimeException('SQLSTATE[22003]: numeric value out of range');
                }

                return true;
            }
        };

        $rows = [
            2 => ['timestamp' => '2026-01-01 00:00:00', 'value' =>'1'],
            3 => ['timestamp' => '2026-01-02 00:00:00', 'value' =>'999'], // lève
            4 => ['timestamp' => '2026-01-03 00:00:00', 'value' =>'2'],
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
            3 => ['timestamp' => '2026-01-01 08:00:00', 'value' =>'-5'],
            4 => ['timestamp' => '2026-01-02 08:00:00', 'value' =>'12'], // OK
        ];

        $report = $this->service->importUtility($rows, ImportMapping::preset('gas'), new FakeUtilityIngestion());

        self::assertSame(1, $report->imported());
        self::assertSame(2, $report->errors());
        self::assertSame(0, $report->writeErrors());
    }
}
