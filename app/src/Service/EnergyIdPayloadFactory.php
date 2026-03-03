<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final class EnergyIdPayloadFactory
{
    /**
     * @param array<string,float|int> $metrics
     * @return array<string,float|int>
     */
    public function makePoint(string $timestamp, array $metrics): array
    {
        $point = ['ts' => $this->unixTs($timestamp)];

        foreach ($metrics as $key => $value) {
            $point[$key] = $value;
        }

        return $point;
    }

    public function unixTs(string $timestamp): int
    {
        return (new DateTimeImmutable($timestamp))->getTimestamp();
    }
}
