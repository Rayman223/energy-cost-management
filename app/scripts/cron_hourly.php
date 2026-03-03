<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\LegacyIngestionRepository;
use App\Service\MeterApiService;
use App\Service\EnergyIngestionService;

$config = require __DIR__ . '/../bootstrap.php';

$database = new Database($config['database']);
$repository = new LegacyIngestionRepository($database->pdo());
$meterApi = new MeterApiService((int) ($config['meters']['timeout'] ?? 10));

$driesPayload = $meterApi->fetchJson($config['meters']['dries_url']);
$solarPayload = $meterApi->fetchJson($config['meters']['solar_url']);

$paths = $config['meters']['paths'] ?? [];

$timestamp = new DateTimeImmutable('now');

$repository->insertDataDries(
    timestamp: $timestamp,
    prelevJour: $meterApi->readNumericValue($driesPayload, $paths['prelev_jour'] ?? []),
    prelevNuit: $meterApi->readNumericValue($driesPayload, $paths['prelev_nuit'] ?? []),
    injecJour: $meterApi->readNumericValue($driesPayload, $paths['injec_jour'] ?? []),
    injecNuit: $meterApi->readNumericValue($driesPayload, $paths['injec_nuit'] ?? []),
);

$repository->insertDataSolaire(
    timestamp: $timestamp,
    productionWh: $meterApi->readNumericValue($solarPayload, $paths['solar_production_wh'] ?? []),
);

echo "[OK] Ingestion horaire compteurs enregistrée (Data_Dries + Data_Solaire/Data_Brusol).\n";
exit(0);
