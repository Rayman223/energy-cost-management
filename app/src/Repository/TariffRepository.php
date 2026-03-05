<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\TariffGrid;
use DateTimeImmutable;
use PDO;

final class TariffRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Find the active tariff grid for a given energy type and date.
     * Returns the most recently started grid that was valid on that date.
     */
    public function findActiveGrid(string $energyType, ?DateTimeImmutable $on = null): ?TariffGrid
    {
        $on ??= new DateTimeImmutable('now');
        $date = $on->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, name, valid_from, valid_to
             FROM tariff_grids
             WHERE energy_type = :type
               AND valid_from <= :date
               AND (valid_to IS NULL OR valid_to >= :date)
             ORDER BY valid_from DESC
             LIMIT 1'
        );
        $stmt->execute(['type' => $energyType, 'date' => $date]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new TariffGrid(
            id: (int) $row['id'],
            energyType: $row['energy_type'],
            name: $row['name'],
            validFrom: new DateTimeImmutable($row['valid_from']),
            validTo: $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null,
            lines: $this->fetchLines((int) $row['id']),
        );
    }

    /** @return TariffGrid[] */
    public function findAll(string $energyType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, name, valid_from, valid_to
             FROM tariff_grids
             WHERE energy_type = :type
             ORDER BY valid_from DESC'
        );
        $stmt->execute(['type' => $energyType]);
        $rows = $stmt->fetchAll();

        return array_map(fn (array $row): TariffGrid => new TariffGrid(
            id: (int) $row['id'],
            energyType: $row['energy_type'],
            name: $row['name'],
            validFrom: new DateTimeImmutable($row['valid_from']),
            validTo: $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null,
            lines: $this->fetchLines((int) $row['id']),
        ), $rows);
    }

    public function saveGrid(string $energyType, string $name, DateTimeImmutable $validFrom, ?DateTimeImmutable $validTo, array $lines): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tariff_grids (energy_type, name, valid_from, valid_to)
                 VALUES (:type, :name, :from, :to)'
            );
            $stmt->execute([
                'type' => $energyType,
                'name' => $name,
                'from' => $validFrom->format('Y-m-d'),
                'to'   => $validTo?->format('Y-m-d'),
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $lineStmt = $this->pdo->prepare(
                'INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, amount_per_kwh)
                 VALUES (:grid_id, :key, :amount)'
            );
            foreach ($lines as $key => $amount) {
                $lineStmt->execute(['grid_id' => $id, 'key' => $key, 'amount' => $amount]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /** @return array<string,float> */
    private function fetchLines(int $gridId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT line_key, amount_per_kwh FROM tariff_grid_lines WHERE tariff_grid_id = :id'
        );
        $stmt->execute(['id' => $gridId]);

        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $lines[$row['line_key']] = (float) $row['amount_per_kwh'];
        }

        return $lines;
    }
}
