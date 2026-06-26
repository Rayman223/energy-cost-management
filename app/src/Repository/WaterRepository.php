<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\MeterReadingRepositoryInterface;
use DateTimeImmutable;
use PDO;

final class WaterRepository implements MeterReadingRepositoryInterface
{
    private const TABLE = 'Data_eau';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(DateTimeImmutable $readingAt, float $counterM3): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (reading_at, counter_m3) VALUES (:reading_at, :counter_m3)'
        );
        $stmt->execute([
            'reading_at' => $readingAt->format('Y-m-d H:i:s'),
            'counter_m3' => $counterM3,
        ]);
    }

    /**
     * Return ALL readings ordered DESC (newest first), each with its delta
     * vs the immediately preceding reading.
     *
     * The very first (oldest) reading in the database will always have
     * delta_m3 = null since there is nothing before it to compare against.
     *
     * @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    public function getAllReadings(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, reading_at, counter_m3
             FROM ' . self::TABLE . '
             ORDER BY reading_at ASC'
        );
        $rowsAsc = $stmt->fetchAll();

        if (empty($rowsAsc)) {
            return [];
        }

        $result = [];
        $prev   = null;

        foreach ($rowsAsc as $row) {
            $delta = $prev !== null
                ? round((float) $row['counter_m3'] - (float) $prev['counter_m3'], 3)
                : null;

            $result[] = [
                'id'         => (int) $row['id'],
                'reading_at' => $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
                'delta_m3'   => $delta,
            ];
            $prev = $row;
        }

        return array_reverse($result); // DESC for display
    }

    /** @return array<string, mixed>|null */
    public function getLatest(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT id, reading_at, counter_m3 FROM ' . self::TABLE . ' ORDER BY reading_at DESC LIMIT 1'
        );
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Fetch water readings strictly after $fromExclusive and up to $toInclusive.
     * Returns rows shaped as {timestamp, value} to match the sync service contract.
     *
     * @return array<int, array{timestamp: string, value: string}>
     */
    public function fetchReadingsSince(
        ?DateTimeImmutable $fromExclusive,
        DateTimeImmutable $toInclusive
    ): array {
        $sql    = 'SELECT reading_at AS timestamp, counter_m3 AS value
                FROM ' . self::TABLE . '
                WHERE reading_at <= :to';
        $params = ['to' => $toInclusive->format('Y-m-d H:i:s')];

        if ($fromExclusive !== null) {
            $sql           .= ' AND reading_at > :from';
            $params['from'] = $fromExclusive->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}