<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

final class LegacyIngestionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertDataDries(
        DateTimeImmutable $timestamp,
        float $prelevJour,
        float $prelevNuit,
        float $injecJour,
        float $injecNuit
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO Data_Dries (timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit)
             VALUES (:timestamp, :prelev_jour, :prelev_nuit, :injec_jour, :injec_nuit)'
        );

        $stmt->execute([
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'prelev_jour' => $prelevJour,
            'prelev_nuit' => $prelevNuit,
            'injec_jour' => $injecJour,
            'injec_nuit' => $injecNuit,
        ]);
    }

    /**
     * Store solar production index.
     * The solar dongle (same structure as P1 meter) exposes total_power_export_kwh — a cumulative kWh counter.
     * The value is stored as-is in kWh. LegacyDailyRepository treats Data_Solaire as kWh (no /1000 conversion).
     */
    public function insertDataSolaire(DateTimeImmutable $timestamp, float $productionKwh): void
    {
        $table = $this->tableExists('Data_Solaire') ? 'Data_Solaire' : 'Data_Brusol';

        $stmt = $this->pdo->prepare(
            sprintf('INSERT INTO %s (timestamp, production) VALUES (:timestamp, :production)', $table)
        );

        $stmt->execute([
            'timestamp'  => $timestamp->format('Y-m-d H:i:s'),
            'production' => $productionKwh,
        ]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}
