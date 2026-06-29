<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use DateTimeImmutable;
use PDO;

final class GasRepository implements GasReadingRepositoryInterface, MeterReadingRepositoryInterface
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

    /**
     * @deprecated Use getAllReadings() for UI display.
     *             Kept for legacy callers.
     *
     * @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    public function getLastReadings(int $limit = 10): array
    {
        return array_slice($this->getAllReadings(), 0, $limit);
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

    /** @return array{from: array<string, mixed>|null, to: array<string, mixed>|null} */
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
     * Fenêtre de relevés pour l'interpolation à minuit d'un mois calendaire :
     * le dernier relevé AVANT minuit le 1er de M, TOUS les relevés du mois, et le
     * premier relevé À/APRÈS minuit le 1er de M+1. Cette fenêtre fournit les
     * segments adjacents nécessaires pour interpoler (ou extrapoler) l'index aux
     * deux bornes du mois — cf. MonthlyConsumptionInterpolator.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForInterpolation(int $year, int $month): array
    {
        return self::fetchInterpolationWindow($this->pdo, self::TABLE, $year, $month);
    }

    /**
     * Logique partagée gaz/eau de la fenêtre d'interpolation (même schéma de
     * table : reading_at + counter_m3).
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public static function fetchInterpolationWindow(PDO $pdo, string $table, int $year, int $month): array
    {
        $firstOfMonth = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $nextYear    = $month === 12 ? $year + 1 : $year;
        $nextMonth   = $month === 12 ? 1         : $month + 1;
        $firstOfNext = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        // Dernier relevé avant le mois ∪ relevés du mois ∪ premier relevé après le mois.
        $sql = '(SELECT reading_at, counter_m3 FROM ' . $table . ' WHERE reading_at < :start ORDER BY reading_at DESC LIMIT 1)'
            . ' UNION ALL '
            . '(SELECT reading_at, counter_m3 FROM ' . $table . ' WHERE reading_at >= :start AND reading_at < :next ORDER BY reading_at ASC)'
            . ' UNION ALL '
            . '(SELECT reading_at, counter_m3 FROM ' . $table . ' WHERE reading_at >= :next ORDER BY reading_at ASC LIMIT 1)'
            . ' ORDER BY reading_at ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['start' => $firstOfMonth, 'next' => $firstOfNext]);

        return array_map(
            static fn (array $row): array => [
                'reading_at' => (string) $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Fetch gas readings strictly after $fromExclusive and up to $toInclusive.
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
            $sql             .= ' AND reading_at > :from';
            $params['from']   = $fromExclusive->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}