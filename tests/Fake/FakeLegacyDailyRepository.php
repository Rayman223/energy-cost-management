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
     * @param array<int, array{quarter: string, import_kwh: float, native: bool}> $quarterImportDeltas
     */
    /** Couples (année, mois) demandés à getMonthlyDeltasForMonth(), dans l'ordre.
     *
     * @var list<array{int, int}>
     */
    public array $monthsRequested = [];

    /** Couples (from, to) demandés à getDeltasBetween(), dans l'ordre.
     *
     * @var list<array{string, string}>
     */
    public array $rangesRequested = [];

    /**
     * @param array<string,mixed> $deltasBetween Deltas rendus par getDeltasBetween() ;
     *        `null` = retomber sur $monthlyDeltasForMonth, pour que les tests écrits
     *        avant #241 n'aient rien à déclarer.
     */
    public function __construct(
        public array $monthlyDeltas = [],
        public array $monthlyDeltasForMonth = [],
        public array $hourlyImportDeltas = [],
        public array $quarterImportDeltas = [],
        public ?array $deltasBetween = null,
    ) {
    }

    public function getMonthlyDeltas(): array
    {
        return $this->monthlyDeltas;
    }

    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        $this->monthsRequested[] = [$year, $month];

        return $this->monthlyDeltasForMonth;
    }

    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->hourlyImportDeltas;
    }

    public function getQuarterImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->quarterImportDeltas;
    }

    public function getDeltasBetween(string $from, string $to): array
    {
        $this->rangesRequested[] = [$from, $to];

        return $this->deltasBetween ?? $this->monthlyDeltasForMonth;
    }
}
