<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\AdvanceSchedule;
use App\Repository\Contract\AdvanceScheduleRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository de barèmes d'acomptes : liste en mémoire, sans base de données.
 */
final class FakeAdvanceScheduleRepository implements AdvanceScheduleRepositoryInterface
{
    /** @param list<AdvanceSchedule> $schedules */
    public function __construct(public array $schedules = [])
    {
    }

    /** @return list<AdvanceSchedule> */
    public function listFor(?string $energyType = null): array
    {
        if ($energyType === null) {
            return $this->schedules;
        }

        return array_values(array_filter(
            $this->schedules,
            static fn (AdvanceSchedule $s): bool => $s->energyType === $energyType,
        ));
    }

    /** @return list<AdvanceSchedule> */
    public function findOverlapping(
        string $energyType,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        ?int $exceptId = null,
    ): array {
        return array_values(array_filter(
            $this->listFor($energyType),
            static fn (AdvanceSchedule $s): bool => $s->id !== $exceptId && $s->overlaps($validFrom, $validTo),
        ));
    }

    public function insert(
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void {
        $this->schedules[] = new AdvanceSchedule(
            id: count($this->schedules) + 1,
            energyType: $energyType,
            amountMonthly: $amountMonthly,
            validFrom: $validFrom,
            validTo: $validTo,
            dueDay: $dueDay,
            note: $note,
        );
    }

    public function update(
        int $id,
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void {
        foreach ($this->schedules as $i => $schedule) {
            if ($schedule->id === $id) {
                $this->schedules[$i] = new AdvanceSchedule(
                    id: $id,
                    energyType: $energyType,
                    amountMonthly: $amountMonthly,
                    validFrom: $validFrom,
                    validTo: $validTo,
                    dueDay: $dueDay,
                    note: $note,
                );
            }
        }
    }

    public function delete(int $id): void
    {
        $this->schedules = array_values(array_filter(
            $this->schedules,
            static fn (AdvanceSchedule $s): bool => $s->id !== $id,
        ));
    }
}
