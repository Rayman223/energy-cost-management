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

    /**
     * Get the two gas readings that bracket a given calendar month.
     *
     * Strategy (mirrors electricity):
     *   $from = reading closest to the 1st of month M
     *   $to   = reading closest to the 1st of month M+1,
     *           that is strictly after $from
     *
     * @return array{from: array|null, to: array|null}
     */
    public function getTwoReadingsForMonth(int $year, int $month): array
    {
        $firstOfMonth = sprintf('%04d-%02d-01', $year, $month);

        $nextYear     = $month === 12 ? $year + 1 : $year;
        $nextMonth    = $month === 12 ? 1         : $month + 1;
        $firstOfNext  = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

        // ── $from : reading closest to the 1st of month M ────────────────────
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3
             FROM ' . self::TABLE . '
             ORDER BY ABS(DATEDIFF(reading_at, :ref)) ASC, reading_at ASC
             LIMIT 1'
        );
        $stmt->execute(['ref' => $firstOfMonth]);
        $from = $stmt->fetch() ?: null;

        if ($from === null) {
            return ['from' => null, 'to' => null];
        }

        // ── $to : reading closest to the 1st of month M+1,
        //         strictly after $from to avoid picking the same row ──────────
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3
             FROM ' . self::TABLE . '
             WHERE reading_at > :from_date
             ORDER BY ABS(DATEDIFF(reading_at, :ref)) ASC, reading_at ASC
             LIMIT 1'
        );
        $stmt->execute([
            'ref'       => $firstOfNext,
            'from_date' => $from['reading_at'],
        ]);
        $to = $stmt->fetch() ?: null;

        return ['from' => $from, 'to' => $to];
    }
}