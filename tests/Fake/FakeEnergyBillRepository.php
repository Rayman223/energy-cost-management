<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\EnergyBill;
use App\Repository\Contract\EnergyBillRepositoryInterface;

/**
 * Faux repository de factures saisies : liste en mémoire, sans base de données.
 *
 * `upsert()` et `delete()` sont implémentés pour de vrai (et non laissés vides) afin que
 * les tests d'orchestration puissent enchaîner écriture puis lecture comme le ferait la
 * route.
 */
final class FakeEnergyBillRepository implements EnergyBillRepositoryInterface
{
    /** @param list<EnergyBill> $bills */
    public function __construct(public array $bills = [])
    {
    }

    /** @return list<EnergyBill> */
    public function listFor(string $energyType, int $limit, int $offset = 0): array
    {
        return array_slice($this->matching($energyType), max(0, $offset), max(1, $limit));
    }

    public function countFor(string $energyType): int
    {
        return count($this->matching($energyType));
    }

    /** @return list<EnergyBill> */
    private function matching(string $energyType): array
    {
        return array_values(array_filter(
            $this->bills,
            static fn (EnergyBill $bill): bool => $bill->energyType === $energyType,
        ));
    }

    public function upsert(
        string $energyType,
        int $year,
        int $month,
        ?float $amountHtva,
        ?float $amountTtc,
        string $note = '',
    ): void {
        foreach ($this->bills as $index => $bill) {
            if ($bill->energyType === $energyType && $bill->year === $year && $bill->month === $month) {
                $this->bills[$index] = new EnergyBill($bill->id, $energyType, $year, $month, $amountHtva, $amountTtc, $note);

                return;
            }
        }

        $this->bills[] = new EnergyBill(count($this->bills) + 1, $energyType, $year, $month, $amountHtva, $amountTtc, $note);
    }

    public function delete(int $id): void
    {
        $this->bills = array_values(array_filter(
            $this->bills,
            static fn (EnergyBill $bill): bool => $bill->id !== $id,
        ));
    }
}
