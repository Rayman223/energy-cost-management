<?php

declare(strict_types=1);

namespace App\Domain;

final class EnergyReading
{
    public function __construct(
        public readonly string $meterType,
        public readonly string $metric,
        public readonly string $metricKind,
        public readonly string $unit,
        public readonly string $interval,
        public readonly \DateTimeImmutable $timestamp,
        public readonly float $value
    ) {
    }

    public function timestampIso8601(): string
    {
        return $this->timestamp->format(\DateTimeInterface::ATOM);
    }
}
