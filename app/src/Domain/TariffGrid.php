<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class TariffGrid
{
    /** @param array<string,float> $lines  line_key => amount_per_kwh */
    public function __construct(
        public readonly int $id,
        public readonly string $energyType,
        public readonly string $name,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validTo,
        public readonly array $lines,
    ) {
    }

    public function isActiveOn(DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return $this->validTo === null || $date <= $this->validTo;
    }

    public function getLine(string $key, float $default = 0.0): float
    {
        return $this->lines[$key] ?? $default;
    }

    /** Export as flat array suitable for TariffCalculatorService. */
    public function toTariffArray(): array
    {
        return $this->lines;
    }
}
