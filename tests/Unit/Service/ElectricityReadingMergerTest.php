<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ElectricityReadingMerger;
use App\Service\EnergyIdPayloadFactory;
use PHPUnit\Framework\TestCase;

final class ElectricityReadingMergerTest extends TestCase
{
    private ElectricityReadingMerger $merger;
    private EnergyIdPayloadFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EnergyIdPayloadFactory();
        $this->merger  = new ElectricityReadingMerger($this->factory);
    }

    /**
     * @param array<int,array{timestamp:string,value:string}> $rows
     * @return array<int,array{timestamp:string,value:string}>
     */
    private function rows(array $rows): array
    {
        return $rows;
    }

    public function testEmptyWhenAllDatasetsEmpty(): void
    {
        $points = $this->merger->build([], [], [], [], []);

        self::assertTrue($points->isEmpty());
        self::assertSame([], $points->points);
    }

    public function testMergesMetricsByDateAndSorts(): void
    {
        $driesT1 = $this->rows([
            ['timestamp' => '2026-06-02 00:00:00', 'value' => '10'], // volontairement non trié
            ['timestamp' => '2026-06-01 00:00:00', 'value' => '5'],
        ]);
        $driesT2 = $this->rows([['timestamp' => '2026-06-01 00:00:00', 'value' => '3']]);
        $solar   = $this->rows([['timestamp' => '2026-06-01 00:00:00', 'value' => '2000']]);

        $result = $this->merger->build($driesT1, $driesT2, [], [], $solar);

        self::assertFalse($result->isEmpty());
        self::assertCount(2, $result->points);

        // Trié par date : 06-01 d'abord
        [$first, $second] = $result->points;

        self::assertSame($this->factory->unixTs('2026-06-01 00:00:00'), $first['ts']);
        self::assertSame(5.0, $first['el.t1']);
        self::assertSame(3.0, $first['el.t2']);
        self::assertSame(2.0, $first['pv']);          // 2000 / 1000
        self::assertArrayNotHasKey('el-i.t1', $first); // métriques absentes non incluses

        self::assertSame(10.0, $second['el.t1']);
        self::assertArrayNotHasKey('el.t2', $second);
        self::assertArrayNotHasKey('pv', $second);

        self::assertSame('2026-06-01', $result->firstDate);
        self::assertSame('2026-06-02', $result->lastDate);
        self::assertSame('2026-06-02 00:00:00', $result->lastTimestamp);
    }

    public function testSolarConvertedFromWhToKwhRounded(): void
    {
        $solar  = $this->rows([['timestamp' => '2026-06-01 12:00:00', 'value' => '2500']]);
        $result = $this->merger->build([], [], [], [], $solar);

        self::assertSame(2.5, $result->points[0]['pv']); // 2500 Wh -> 2.5 kWh
    }

    public function testTimestampKeptFromFirstDatasetForADate(): void
    {
        // Deux datasets pour la même date avec des horodatages différents :
        // l'horodatage du premier dataset rencontré est conservé.
        $driesT1 = $this->rows([['timestamp' => '2026-06-01 06:00:00', 'value' => '5']]);
        $driesT2 = $this->rows([['timestamp' => '2026-06-01 23:00:00', 'value' => '3']]);

        $result = $this->merger->build($driesT1, $driesT2, [], [], []);

        self::assertSame('2026-06-01 06:00:00', $result->lastTimestamp);
        self::assertSame($this->factory->unixTs('2026-06-01 06:00:00'), $result->points[0]['ts']);
    }
}
