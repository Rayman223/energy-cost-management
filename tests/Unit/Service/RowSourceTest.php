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
     * @return iterable<int, array<string, string>>
     */
    private function csvRows(string $content): iterable
    {
        $handle = fopen('php://memory', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $content);
        rewind($handle);

        return RowSource::fromCsv($handle);
    }
}
