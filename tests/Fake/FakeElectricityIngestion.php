<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\ElectricityIngestionInterface;
use DateTimeImmutable;

final class FakeElectricityIngestion implements ElectricityIngestionInterface
{
    /** @var list<array{timestamp: string, indexes: array<string, float>}> */
    public array $calls = [];

    /** @var array<string, true> Clés (timestamp|register) déjà vues — simule l'unicité. */
    private array $seen = [];

    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister): int
    {
        $this->calls[] = ['timestamp' => $timestamp->format('Y-m-d H:i:s'), 'indexes' => $indexByRegister];

        $inserted = 0;
        foreach (array_keys($indexByRegister) as $key) {
            $unique = $timestamp->format('Y-m-d H:i:s') . '|' . $key;
            if (!isset($this->seen[$unique])) {
                $this->seen[$unique] = true;
                $inserted++;
            }
        }

        return $inserted;
    }
}
