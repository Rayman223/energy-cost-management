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
            'SELECT id, energy_type, name, valid_from, valid_to, pcs_coefficient
             FROM tariff_grids
             WHERE energy_type = :type
               AND valid_from <= :date
               AND (valid_to IS NULL OR valid_to >= :date)
             ORDER BY valid_from DESC
             LIMIT 1'
        );
        $stmt->execute(['type' => $energyType, 'date' => $date]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?TariffGrid
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, name, valid_from, valid_to, pcs_coefficient
             FROM tariff_grids WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /** @return TariffGrid[] */
    public function findAll(string $energyType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, name, valid_from, valid_to, pcs_coefficient
             FROM tariff_grids
             WHERE energy_type = :type
             ORDER BY valid_from DESC'
        );
        $stmt->execute(['type' => $energyType]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        // Chargement de toutes les lignes en une seule requête (évite le N+1).
        $ids      = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $linesMap = $this->fetchLinesForIds($ids);

        return array_map(
            fn (array $row): TariffGrid => $this->hydrate($row, $linesMap[(int) $row['id']] ?? []),
            $rows
        );
    }

    public function saveGrid(
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
    ): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tariff_grids (energy_type, name, valid_from, valid_to, pcs_coefficient)
                 VALUES (:type, :name, :from, :to, :pcs)'
            );
            $stmt->execute([
                'type' => $energyType,
                'name' => $name,
                'from' => $validFrom->format('Y-m-d'),
                'to'   => $validTo?->format('Y-m-d'),
                'pcs'  => $pcsCoefficient,
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


    public function updateGrid(
        int $id,
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
    ): void {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE tariff_grids
                 SET energy_type = :type,
                     name = :name,
                     valid_from = :from,
                     valid_to = :to,
                     pcs_coefficient = :pcs
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'type' => $energyType,
                'name' => $name,
                'from' => $validFrom->format('Y-m-d'),
                'to'   => $validTo?->format('Y-m-d'),
                'pcs'  => $pcsCoefficient,
            ]);

            if ($stmt->rowCount() === 0) {
                $existsStmt = $this->pdo->prepare('SELECT 1 FROM tariff_grids WHERE id = :id');
                $existsStmt->execute(['id' => $id]);
                if ($existsStmt->fetchColumn() === false) {
                    throw new \RuntimeException('Tarif introuvable.');
                }
            }

            $deleteStmt = $this->pdo->prepare('DELETE FROM tariff_grid_lines WHERE tariff_grid_id = :grid_id');
            $deleteStmt->execute(['grid_id' => $id]);

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
    }

    public function closeGrid(int $id, DateTimeImmutable $validTo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tariff_grids SET valid_to = :valid_to WHERE id = :id'
        );
        $stmt->execute(['valid_to' => $validTo->format('Y-m-d'), 'id' => $id]);
    }

    public function deleteGrid(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tariff_grids WHERE id = :id');
        $stmt->execute(['id' => $id]);
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

    /**
     * Charge toutes les lignes pour une liste d'ids en une seule requête.
     * Utilisé par findAll() pour éviter le pattern N+1.
     *
     * @param  int[]                           $ids
     * @return array<int,array<string,float>>  Indexé par tariff_grid_id
     */
    private function fetchLinesForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT tariff_grid_id, line_key, amount_per_kwh
             FROM tariff_grid_lines
             WHERE tariff_grid_id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['tariff_grid_id']][$row['line_key']] = (float) $row['amount_per_kwh'];
        }

        return $map;
    }

    /**
     * @param array<string,float>|null $preloadedLines  Lignes déjà chargées (évite un SELECT supplémentaire).
     */
    private function hydrate(array $row, ?array $preloadedLines = null): TariffGrid
    {
        $id    = (int) $row['id'];
        $lines = $preloadedLines ?? $this->fetchLines($id);

        return new TariffGrid(
            id: $id,
            energyType: $row['energy_type'],
            name: $row['name'],
            validFrom: new DateTimeImmutable($row['valid_from']),
            validTo: $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null,
            lines: $lines,
            pcsCoefficient: isset($row['pcs_coefficient']) ? (float) $row['pcs_coefficient'] : null,
        );
    }

    /**
     * Find the most recent PCS coefficient available for a given energy type
     * at or before a reference date.
     *
     * Used as fallback when the active tariff has no pcs_coefficient set.
     * Searches backwards in time regardless of valid_to.
     */
    public function findMostRecentPcs(string $energyType, DateTimeImmutable $before): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT pcs_coefficient
             FROM tariff_grids
             WHERE energy_type     = :type
               AND pcs_coefficient IS NOT NULL
               AND valid_from      <= :date
             ORDER BY valid_from DESC
             LIMIT 1'
        );
        $stmt->execute([
            'type' => $energyType,
            'date' => $before->format('Y-m-d'),
        ]);

        $val = $stmt->fetchColumn();

        return $val !== false ? (float) $val : null;
    }
}