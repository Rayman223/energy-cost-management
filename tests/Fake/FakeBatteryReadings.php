<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\BatteryReadingsInterface;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * Séries d'index de batterie en mémoire (#26).
 *
 * Les relevés se déclarent en dates lisibles (`'2026-01-01' => 1000.0`) : un test
 * de bilan parle en mois, pas en timestamps.
 */
final class FakeBatteryReadings implements BatteryReadingsInterface
{
    /** @var list<array{ts:int,value:float}> */
    private array $charge = [];

    /** @var list<array{ts:int,value:float}> */
    private array $discharge = [];

    /**
     * @param array<string, float> $charge    date 'Y-m-d' (ou 'Y-m-d H:i:s') => index cumulé
     * @param array<string, float> $discharge idem
     */
    public function __construct(array $charge = [], array $discharge = [])
    {
        $this->charge    = self::series($charge);
        $this->discharge = self::series($discharge);
    }

    /**
     * @return array{charge: list<array{ts:int,value:float}>, discharge: list<array{ts:int,value:float}>}
     */
    public function indexSeries(): array
    {
        return ['charge' => $this->charge, 'discharge' => $this->discharge];
    }

    /**
     * @param array<string, float> $readings
     * @return list<array{ts:int,value:float}>
     */
    private static function series(array $readings): array
    {
        $series = [];
        foreach ($readings as $date => $value) {
            $series[] = [
                'ts'    => (new DateTimeImmutable($date, Dates::utc()))->getTimestamp(),
                'value' => $value,
            ];
        }

        usort($series, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        return $series;
    }
}
