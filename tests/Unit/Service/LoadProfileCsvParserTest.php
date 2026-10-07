<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\LoadProfileCsvParser;
use App\Service\Import\LoadProfileParseResult;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Lecture des CSV de profil de charge (#93, #101), partagée par le script CLI et la
 * page d'administration.
 */
final class LoadProfileCsvParserTest extends TestCase
{
    private function parse(string $csv, int $resolution = 15, string $tsCol = 'timestamp', string $valueCol = 'fraction'): LoadProfileParseResult
    {
        $handle = fopen('php://memory', 'r+b');
        self::assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);

        try {
            return (new LoadProfileCsvParser())->parse($handle, $tsCol, $valueCol, $resolution, new DateTimeZone('Europe/Brussels'));
        } finally {
            fclose($handle);
        }
    }

    /** Synergrid horodate en heure belge : le créneau stocké est l'instant UTC. */
    public function testLocalTimestampsAreConvertedToUtc(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 00:00;0,25\n2026-07-01 00:15;0,50\n");

        self::assertSame('2025-12-31 23:00:00', $result->firstSlot());
        self::assertSame('2026-06-30 22:15:00', $result->lastSlot());
        self::assertSame(['2025-12' => 1, '2026-06' => 1], $result->pointsByMonth());
    }

    public function testExplicitOffsetIsHonoured(): void
    {
        $result = $this->parse("timestamp,fraction\n2026-01-01T00:00:00+00:00,1\n");

        self::assertSame('2026-01-01 00:00:00', $result->firstSlot());
    }

    /** « 0,25 % » d'un export tableur : virgule décimale ET signe pourcent. */
    public function testPercentWithDecimalCommaIsAccepted(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 00:00;0,25%\n2026-01-01 00:15;0,75 %\n");

        self::assertSame(0, $result->rejected);
        self::assertEqualsWithDelta(1.0, $result->sum(), 1e-12);
    }

    /** « 2,85E-05 » : un petit coefficient exporté en notation scientifique. */
    public function testScientificNotationWithDecimalCommaIsAccepted(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 00:00;2,5E-05\n");

        self::assertSame(0, $result->rejected);
        self::assertEqualsWithDelta(2.5e-5, $result->sum(), 1e-15);
    }

    /**
     * « 1/09/2026 0:15 » d'un tableur belge est le 1er septembre, pas le 9 janvier
     * qu'y lirait DateTimeImmutable (m/d/Y).
     */
    public function testSlashDatesAreReadDayFirst(): void
    {
        $result = $this->parse("timestamp;fraction\n1/09/2026 0:15;1\n13/09/2026 00:00;1\n");

        self::assertSame(0, $result->rejected);
        self::assertSame('2026-08-31 22:15:00', $result->firstSlot());
        self::assertSame('2026-09-12 22:00:00', $result->lastSlot());
    }

    /** Dates impossibles et formes souples : rejetées, jamais reportées en silence. */
    public function testCalendarInvalidAndRelativeDatesAreRejected(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 00:00;1\n2026-02-30 00:00;1\n31/04/2026 00:00;1\nnow;1\n+1 day;1\n");

        self::assertSame(1, $result->count());
        self::assertSame(4, $result->rejected);
    }

    /**
     * Quatre quarts importés à 60 min forment le poids de l'heure : SOMMÉS, jamais
     * écrasés — sinon trois quarts sur quatre disparaîtraient en silence.
     */
    public function testHourlyResolutionSumsTheQuartersOfAnHour(): void
    {
        $csv = "timestamp;fraction\n"
            . "2026-01-01 00:00;0.1\n2026-01-01 00:15;0.2\n2026-01-01 00:30;0.3\n2026-01-01 00:45;0.4\n";

        $result = $this->parse($csv, 60);

        self::assertSame(1, $result->count());
        self::assertSame(3, $result->merged);
        self::assertEqualsWithDelta(1.0, $result->weights[0]['fraction'], 1e-12);
    }

    public function testInvalidRowsAreCountedNotFatal(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 00:00;1\nnot-a-date;1\n2026-01-01 00:15;-1\n2026-01-01 00:30;abc\n;1\n");

        self::assertSame(1, $result->count());
        self::assertSame(4, $result->rejected);
    }

    public function testWeightsAreSortedBySlot(): void
    {
        $result = $this->parse("timestamp;fraction\n2026-01-01 01:00;1\n2026-01-01 00:00;2\n");

        self::assertSame('2025-12-31 23:00:00', $result->firstSlot());
        self::assertSame('2025-12-31 23:00:00', $result->weights[0]['slot_start']->format('Y-m-d H:i:00'));
        self::assertSame(2.0, $result->weights[0]['fraction']);
        self::assertSame(1.0, $result->min());
        self::assertSame(2.0, $result->max());
    }

    public function testCustomColumnNamesAreCaseInsensitive(): void
    {
        $result = $this->parse("Datum;RLP0N\n2026-01-01 00:00;1\n", 15, 'DATUM', 'rlp0n');

        self::assertSame(1, $result->count());
    }

    public function testFileWithoutUsablePointIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('« timestamp » et « fraction »');

        $this->parse("date;valeur\n2026-01-01 00:00;1\n");
    }

    public function testUnsupportedResolutionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parse("timestamp;fraction\n2026-01-01 00:00;1\n", 30);
    }

    public function testCodeAndCountryAreNormalized(): void
    {
        self::assertSame('RLP0N', LoadProfileCsvParser::normalizeCode(' rlp0n '));
        self::assertSame('BE', LoadProfileCsvParser::normalizeCountry('be'));
    }

    /** @return list<array{string}> */
    public static function invalidCodes(): array
    {
        return [[''], ['RLP 0N'], [str_repeat('A', 33)], ['<script>']];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodeIsRejected(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        LoadProfileCsvParser::normalizeCode($code);
    }

    public function testInvalidCountryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LoadProfileCsvParser::normalizeCountry('BEL');
    }

    public function testUnknownTimezoneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LoadProfileCsvParser::timezone('Mars/Olympus');
    }

    public function testMissingUploadIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Aucun fichier fourni.');
        LoadProfileCsvParser::openUploaded(['error' => UPLOAD_ERR_NO_FILE]);
    }

    /** Le fichier de poids Synergrid est publié en XLSX : il faut le convertir. */
    public function testNonCsvUploadIsRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rlp');
        self::assertNotFalse($tmp);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('CSV attendu');
            LoadProfileCsvParser::openUploaded(['tmp_name' => $tmp, 'name' => 'RLP0N.xlsx', 'error' => UPLOAD_ERR_OK, 'size' => 10]);
        } finally {
            unlink($tmp);
        }
    }

    public function testOversizedUploadIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trop volumineux');
        LoadProfileCsvParser::openUploaded(['tmp_name' => '/dev/null', 'name' => 'p.csv', 'error' => UPLOAD_ERR_OK, 'size' => 9_000_000]);
    }

    public function testValidUploadIsOpened(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rlp');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "timestamp;fraction\n2026-01-01 00:00;1\n");

        $handle = LoadProfileCsvParser::openUploaded(['tmp_name' => $tmp, 'name' => 'RLP0N.CSV', 'error' => UPLOAD_ERR_OK, 'size' => 40]);
        try {
            $result = (new LoadProfileCsvParser())->parse($handle, 'timestamp', 'fraction', 15, new DateTimeZone('UTC'));
            self::assertSame(1, $result->count());
        } finally {
            fclose($handle);
            unlink($tmp);
        }
    }
}
