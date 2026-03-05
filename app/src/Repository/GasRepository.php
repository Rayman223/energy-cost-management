<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

final class GasRepository
{
    private const TABLE = 'Data_gaz';

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

    /** @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> */
    public function getLastReadings(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3
             FROM ' . self::TABLE . '
             ORDER BY reading_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows   = array_reverse($stmt->fetchAll()); // ASC for delta calc
        $result = [];
        $prev   = null;

        foreach ($rows as $row) {
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

        return array_reverse($result); // back to DESC for display
    }

    public function getLatest(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT id, reading_at, counter_m3 FROM ' . self::TABLE . ' ORDER BY reading_at DESC LIMIT 1'
        );
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array{from:array|null, to:array|null} */
    public function getLastTwoReadings(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, reading_at, counter_m3 FROM ' . self::TABLE . ' ORDER BY reading_at DESC LIMIT 2'
        );
        $rows = $stmt->fetchAll();

        return [
            'from' => $rows[1] ?? null,
            'to'   => $rows[0] ?? null,
        ];
    }
}