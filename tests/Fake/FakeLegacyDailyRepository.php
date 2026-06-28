<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\LegacyDailyRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository legacy : renvoie des deltas en mémoire, sans base de données.
 */
final class FakeLegacyDailyRepository implements LegacyDailyRepositoryInterface
{
    /**
     * @param array<string,mixed> $monthlyDeltas
     * @param array<string,mixed> $monthlyDeltasForMonth
     * @param array<int, array{hour: string, import_kwh: float}> $hourlyImportDeltas
     */
    public function __construct(
        public array $monthlyDeltas = [],
        public array $monthlyDeltasForMonth = [],
        public array $hourlyImportDeltas = [],
    ) {
    }

    public function getMonthlyDeltas(): array
    {
        return $this->monthlyDeltas;
    }

    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        return $this->monthlyDeltasForMonth;
    }

    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->hourlyImportDeltas;
    }
}
