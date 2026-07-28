<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\GasReadingRepositoryInterface;

/**
 * Faux repository de relevés gaz : renvoie des relevés en mémoire.
 */
final class FakeGasReadingRepository implements GasReadingRepositoryInterface
{
    /**
     * @param array{from:array|null, to:array|null}        $lastTwo
     * @param list<array{reading_at: string, counter_m3: float}> $forInterpolation
     */
    public function __construct(
        public array $lastTwo = ['from' => null, 'to' => null],
        public array $forInterpolation = [],
    ) {
    }

    public function getLastTwoReadings(): array
    {
        return $this->lastTwo;
    }

    public function getReadingsForInterpolation(int $year, int $month): array
    {
        return $this->forInterpolation;
    }

    public function getReadingsForRange(string $from, string $to): array
    {
        return $this->forInterpolation;
    }
}
