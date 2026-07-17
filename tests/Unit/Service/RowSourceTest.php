<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\RowSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RowSourceTest extends TestCase
{
    public function testCsvNormalizesHeaderAndYieldsLineNumbers(): void
    {
        $csv = "Timestamp,Gaz Naturel\n2026-01-01 08:00:00,100.5\n\n2026-01-02 08:00:00,101\n";
        $rows = iterator_to_array($this->csvRows($csv));

        // La ligne vide est ignorée ; les clés sont les numéros de ligne source.
        self::assertSame([2, 4], array_keys($rows));
        self::assertSame(['timestamp' => '2026-01-01 08:00:00', 'gaz naturel' => '100.5'], $rows[2]);
        self::assertSame('101', $rows[4]['gaz naturel']);
    }

    public function testCsvEmptyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($this->csvRows(''));
    }

    /** Export tableur FR/BE : séparateur « ; » détecté sans rien préciser (#134). */
    public function testCsvDetectsSemicolonDelimiter(): void
    {
        $csv = "Date;HP_Jour;HP_Nuit\n2026-01-01 08:00:00;1000;2000\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame(['date' => '2026-01-01 08:00:00', 'hp_jour' => '1000', 'hp_nuit' => '2000'], $rows[2]);
    }

    public function testCsvDetectsTabDelimiter(): void
    {
        $csv = "timestamp\timport_t1\n2026-01-01 08:00:00\t1000\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame(['timestamp' => '2026-01-01 08:00:00', 'import_t1' => '1000'], $rows[2]);
    }

    /**
     * Sur un fichier délimité par « ; », une décimale à la virgule est réinterprétée
     * en point (exports tableur FR/BE — #150), sans que l'utilisateur configure rien.
     */
    public function testCsvSemicolonNormalizesDecimalComma(): void
    {
        $csv = "Date;HP_Jour;HP_Nuit\n2026-01-01 08:00:00;1234,5;0,75\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame('1234.5', $rows[2]['hp_jour']);
        self::assertSame('0.75', $rows[2]['hp_nuit']);
        // L'horodatage (avec « - » et « : ») ne matche jamais le motif décimal.
        self::assertSame('2026-01-01 08:00:00', $rows[2]['date']);
    }

    /** Idem en tabulation : une virgule ne peut y être qu'une décimale (#150). */
    public function testCsvTabNormalizesDecimalComma(): void
    {
        $csv = "timestamp\timport_t1\n2026-01-01 08:00:00\t1234,5\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame('1234.5', $rows[2]['import_t1']);
    }

    /**
     * Fichier délimité par « , » : une valeur quotée « 1,234 » est ambiguë
     * (millier vs décimale) — on ne devine pas, on laisse tel quel (#150).
     */
    public function testCsvCommaDelimiterDoesNotReinterpretComma(): void
    {
        $csv = "timestamp,import_t1\n\"2026-01-01 08:00:00\",\"1,234\"\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame('1,234', $rows[2]['import_t1']);
    }

    /** Non-régression : la décimale au point reste intacte, quel que soit le délimiteur. */
    public function testCsvDecimalDotIsUnchanged(): void
    {
        $semi = iterator_to_array($this->csvRows("Date;HP_Jour\n2026-01-01 08:00:00;1234.5\n"));
        self::assertSame('1234.5', $semi[2]['hp_jour']);

        $comma = iterator_to_array($this->csvRows("timestamp,import_t1\n2026-01-01 08:00:00,1234.5\n"));
        self::assertSame('1234.5', $comma[2]['import_t1']);
    }

    /**
     * Motif étroit : séparateur de milliers (`1 234,5`) et format mixte (`1.234,5`)
     * ne sont pas normalisés — ils restent tels quels et seront rejetés en aval (#150).
     */
    public function testCsvSemicolonLeavesThousandSeparatorsUntouched(): void
    {
        $csv = "Date;HP_Jour;HP_Nuit\n2026-01-01 08:00:00;\"1 234,5\";\"1.234,5\"\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame('1 234,5', $rows[2]['hp_jour']);
        self::assertSame('1.234,5', $rows[2]['hp_nuit']);
    }

    /** Le BOM des exports tableur ne doit pas coller au 1er nom de colonne (#134). */
    public function testCsvStripsUtf8Bom(): void
    {
        $csv = "\xEF\xBB\xBFtimestamp;import_t1\n2026-01-01 08:00:00;1000\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame(['timestamp' => '2026-01-01 08:00:00', 'import_t1' => '1000'], $rows[2]);
    }

    /**
     * Un délimiteur présent uniquement dans un champ quoté ne doit pas l'emporter :
     * ici « ; » découpe 2 colonnes, la virgule une seule (elle est quotée).
     */
    public function testCsvIgnoresDelimiterInsideQuotedHeader(): void
    {
        $csv = "\"Relevé, jour\";import_t1\n2026-01-01 08:00:00;1000\n";
        $rows = iterator_to_array($this->csvRows($csv));

        self::assertSame(['relevé, jour' => '2026-01-01 08:00:00', 'import_t1' => '1000'], $rows[2]);
    }

    /** Délimiteur explicite : prioritaire sur la détection (CLI). */
    public function testCsvExplicitDelimiterWins(): void
    {
        // « ; » découperait 2 colonnes, mais on impose la virgule : 1 seule colonne.
        $csv = "a;b\n1;2\n";
        $rows = iterator_to_array($this->csvRows($csv, ','));

        self::assertSame(['a;b' => '1;2'], $rows[2]);
    }

    public function testJsonReadingsEnvelope(): void
    {
        $json = '{"readings":[{"reading_at":"2026-02-01 00:00:00","counter_m3":5},{"reading_at":"2026-02-02 00:00:00","counter_m3":6}]}';
        $rows = iterator_to_array(RowSource::fromJson($json));

        self::assertSame([1, 2], array_keys($rows));
        self::assertSame('2026-02-01 00:00:00', $rows[1]['reading_at']);
        self::assertSame('5', $rows[1]['counter_m3']);
    }

    public function testJsonTopLevelList(): void
    {
        $json = '[{"timestamp":"2026-01-01 00:00:00","import_t1":1000}]';
        $rows = iterator_to_array(RowSource::fromJson($json));

        self::assertSame('1000', $rows[1]['import_t1']);
    }

    public function testJsonInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array(RowSource::fromJson('not json'));
    }

    /**
     * @param non-empty-string|null $delimiter null = auto-détection
     * @return iterable<int, array<string, string>>
     */
    private function csvRows(string $content, ?string $delimiter = null): iterable
    {
        $handle = fopen('php://memory', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $content);
        rewind($handle);

        return RowSource::fromCsv($handle, $delimiter);
    }
}
