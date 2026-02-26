<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\EnergyReading;
use DateTimeImmutable;
use PDO;

final class EnergyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return EnergyReading[]
     */
    public function fetchUnpublishedElectricityReadings(int $limit = 500): array
    {
        $sql = <<<'SQL'
            SELECT id, reading_at, meter_type, metric, metric_kind, unit, interval_code, value
            FROM energy_readings
            WHERE meter_type = 'electricity' AND published_at IS NULL
            ORDER BY reading_at ASC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): EnergyReading => new EnergyReading(
            meterType: $row['meter_type'],
            metric: $row['metric'],
            metricKind: $row['metric_kind'],
            unit: $row['unit'],
            interval: $row['interval_code'],
            timestamp: new DateTimeImmutable($row['reading_at']),
            value: (float) $row['value']
        ), $rows);
    }

    public function markAsPublished(string $meterType, DateTimeImmutable $untilTimestamp): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE energy_readings
             SET published_at = NOW()
             WHERE meter_type = :meter_type AND reading_at <= :until_ts AND published_at IS NULL'
        );

        $stmt->execute([
            'meter_type' => $meterType,
            'until_ts' => $untilTimestamp->format('Y-m-d H:i:s'),
        ]);
    }

    public function saveGasManualReading(DateTimeImmutable $timestamp, float $counterValueM3): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gas_manual_readings (reading_at, counter_m3) VALUES (:reading_at, :counter_m3)'
        );

        $stmt->execute([
            'reading_at' => $timestamp->format('Y-m-d H:i:s'),
            'counter_m3' => $counterValueM3,
        ]);
    }
}
