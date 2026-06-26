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
     */
    public function __construct(
        public ?array $latest = null,
        public array $all = [],
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
}
