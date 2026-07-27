<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\UtilityConsumptionSeriesService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeMeterReadingRepository;

final class UtilityConsumptionSeriesServiceTest extends TestCase
{
    private UtilityConsumptionSeriesService $service;

    protected function setUp(): void
    {
        $this->service = new UtilityConsumptionSeriesService();
    }

    /**
     * Relevés au format du repository : DESC (le plus récent d'abord), avec le
     * delta brut entre relevés consécutifs.
     *
     * @param array<int, array{0:string,1:float}> $pairsAsc
     * @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    private function repoRows(array $pairsAsc): array
    {
        $rows = [];
        $prev = null;
        foreach ($pairsAsc as $i => [$at, $value]) {
            $rows[] = [
                'id'         => $i + 1,
                'reading_at' => $at,
                'counter_m3' => $value,
                'delta_m3'   => $prev === null ? null : round($value - $prev, 3),
            ];
            $prev = $value;
        }

        return array_reverse($rows);
    }

    private function ts(string $moment): int
    {
        return (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->getTimestamp();
    }

    public function testBuildsMonthlySeriesFromRawReadings(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->repoRows([
            ['2026-01-01 00:00:00', 100.0],
            ['2026-03-01 00:00:00', 159.0], // 59 m³ sur 59 jours = 1 m³/jour
        ]));

        $series = $this->service->build($repo, 3, $this->ts('2026-03-01 09:00:00'));

        self::assertSame(['2026-01', '2026-02'], array_column($series, 'month'));
        self::assertEqualsWithDelta(31.0, $series[0]['delta_m3'], 0.001);
        self::assertEqualsWithDelta(28.0, $series[1]['delta_m3'], 0.001);
    }

    public function testReadingsAreOrderedRegardlessOfRepositoryOrder(): void
    {
        // Le repository renvoie DESC : le service doit trier avant d'interpoler.
        $repo = new FakeMeterReadingRepository(all: $this->repoRows([
            ['2026-04-01 00:00:00', 0.0],
            ['2026-05-01 00:00:00', 30.0],
        ]));

        $series = $this->service->build($repo, 2, $this->ts('2026-05-05 00:00:00'));

        self::assertSame(['2026-04'], array_column($series, 'month'));
        self::assertEqualsWithDelta(30.0, $series[0]['delta_m3'], 0.001);
    }

    public function testEmptyRepositoryYieldsEmptySeries(): void
    {
        self::assertSame([], $this->service->build(new FakeMeterReadingRepository(), 12, $this->ts('2026-06-01 00:00:00')));
    }

    public function testMonthsWindowIsClamped(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->repoRows([
            ['2026-05-01 00:00:00', 0.0],
            ['2026-06-01 00:00:00', 30.0],
        ]));

        // 0 mois → ramené à 1 (le mois courant), 999 mois → ramené à 60 sans
        // pour autant inventer des barres avant le premier relevé.
        self::assertSame([], $this->service->build($repo, 0, $this->ts('2026-07-10 00:00:00')));
        self::assertSame(
            ['2026-05'],
            array_column($this->service->build($repo, 999, $this->ts('2026-07-10 00:00:00')), 'month'),
        );
    }
}
