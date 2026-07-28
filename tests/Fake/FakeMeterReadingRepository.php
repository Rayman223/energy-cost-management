<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\MeterReadingRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository de relevés de compteur (gaz/eau) en mémoire.
 * Mémorise le dernier save() pour vérification dans les tests.
 */
final class FakeMeterReadingRepository implements MeterReadingRepositoryInterface
{
    /** @var array{reading_at: DateTimeImmutable, counter_m3: float}|null */
    public ?array $saved = null;

    /**
     * @param array<string, mixed>|null $latest
     * @param array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> $all
     * @param list<array{reading_at: string, counter_m3: float}> $forInterpolation
     * @param array<string, mixed>|null $before relevé encadrant renvoyé par getReadingBefore()
     * @param array<string, mixed>|null $after  relevé encadrant renvoyé par getReadingAfter()
     */
    public function __construct(
        public ?array $latest = null,
        public array $all = [],
        public array $forInterpolation = [],
        public ?array $before = null,
        public ?array $after = null,
    ) {
    }

    public function save(DateTimeImmutable $readingAt, float $counterM3): void
    {
        $this->saved = ['reading_at' => $readingAt, 'counter_m3' => $counterM3];
    }

    /** @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> */
    public function getAllReadings(): array
    {
        return $this->all;
    }

    /** @return array<string, mixed>|null */
    public function getLatest(): ?array
    {
        return $this->latest;
    }

    /** @return array<string, mixed>|null */
    public function getReadingBefore(DateTimeImmutable $ts): ?array
    {
        return $this->before;
    }

    /** @return array<string, mixed>|null */
    public function getReadingAfter(DateTimeImmutable $ts): ?array
    {
        return $this->after;
    }

    /** @return list<array{reading_at: string, counter_m3: float}> */
    public function getReadingsForInterpolation(int $year, int $month): array
    {
        return $this->forInterpolation;
    }

    /** @return list<array{reading_at: string, counter_m3: float}> */
    public function getReadingsForRange(string $from, string $to): array
    {
        return $this->forInterpolation;
    }
}
