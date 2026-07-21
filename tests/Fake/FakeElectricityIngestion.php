<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\ReadingGranularity;
use App\Repository\Contract\ElectricityIngestionInterface;
use DateTimeImmutable;
use DateTimeZone;

final class FakeElectricityIngestion implements ElectricityIngestionInterface
{
    /** @var list<array{timestamp: string, indexes: array<string, float>}> */
    public array $calls = [];

    /** @var array<string, true> Clés (timestamp|register) déjà vues — simule l'unicité. */
    private array $seen = [];

    /**
     * @param array<string, array{min?: float|null, max?: float|null, exists?: bool}> $bounds bornes par registre
     */
    public function __construct(public array $bounds = [])
    {
    }

    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister, bool $replace = false): int
    {
        $this->calls[] = ['timestamp' => $timestamp->format('Y-m-d H:i:s'), 'indexes' => $indexByRegister];

        $inserted = 0;
        foreach (array_keys($indexByRegister) as $key) {
            $unique = $timestamp->format('Y-m-d H:i:s') . '|' . $key;
            // En mode replace, un index déjà vu est réécrit → compté comme ligne
            // touchée (simule ON DUPLICATE KEY UPDATE).
            if (!isset($this->seen[$unique]) || $replace) {
                $this->seen[$unique] = true;
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @param list<string> $registerKeys
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $registerKeys): array
    {
        $out = [];
        foreach ($registerKeys as $key) {
            $b = $this->bounds[$key] ?? [];
            $out[$key] = [
                'min'    => $b['min'] ?? null,
                'max'    => $b['max'] ?? null,
                'exists' => $b['exists'] ?? false,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $registerKeys
     * @return array<string, bool>
     */
    public function readingsPresentInBucket(DateTimeImmutable $timestamp, string $timezone, ReadingGranularity $granularity, array $registerKeys): array
    {
        $tz = new DateTimeZone($timezone);
        [$bucketStart, $bucketEnd] = $granularity->bucket($timestamp, $tz);
        $exact = $timestamp->format('Y-m-d H:i:s');

        $present = [];
        foreach ($registerKeys as $key) {
            $present[$key] = false;
            foreach (array_keys($this->seen) as $seenKey) {
                [$seenTs, $seenReg] = explode('|', $seenKey, 2);
                if ($seenReg !== $key || $seenTs === $exact) {
                    continue; // registre différent, ou instant exact (idempotence)
                }
                $seenMoment = new DateTimeImmutable($seenTs, new DateTimeZone('UTC'));
                if ($seenMoment >= $bucketStart && $seenMoment < $bucketEnd) {
                    $present[$key] = true;
                    break;
                }
            }
        }

        return $present;
    }
}
