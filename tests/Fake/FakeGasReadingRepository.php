<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\GasReadingRepositoryInterface;

/**
 * Faux repository de relevés gaz : renvoie des paires de relevés en mémoire.
 */
final class FakeGasReadingRepository implements GasReadingRepositoryInterface
{
    /**
     * @param array{from:array|null, to:array|null} $lastTwo
     * @param array{from:array|null, to:array|null} $twoForMonth
     */
    public function __construct(
        public array $lastTwo = ['from' => null, 'to' => null],
        public array $twoForMonth = ['from' => null, 'to' => null],
    ) {
    }

    public function getLastTwoReadings(): array
    {
        return $this->lastTwo;
    }

    public function getTwoReadingsForMonth(int $year, int $month): array
    {
        return $this->twoForMonth;
    }
}
