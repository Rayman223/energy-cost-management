<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

final class EnergyIngestionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cette méthode est appelée par le cron chaque heure.
     * Elle illustre l'enregistrement des index en DB (électricité).
     */
    public function ingestHourlyElectricityReading(array $rawData): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO energy_readings (reading_at, meter_type, metric, metric_kind, unit, interval_code, value)
             VALUES (:reading_at, :meter_type, :metric, :metric_kind, :unit, :interval_code, :value)'
        );

        $stmt->execute([
            'reading_at' => $rawData['reading_at'],
            'meter_type' => 'electricity',
            'metric' => $rawData['metric'] ?? 'gridImport',
            'metric_kind' => $rawData['metric_kind'] ?? 'total',
            'unit' => $rawData['unit'] ?? 'Wh',
            'interval_code' => $rawData['interval'] ?? 'PT1H',
            'value' => $rawData['value'],
        ]);
    }
}
