<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Service\EnergyIngestionService;

$config = require __DIR__ . '/../bootstrap.php';

$database = new Database($config['database']);
$ingestion = new EnergyIngestionService($database->pdo());

// TODO: remplacer ce bloc par lecture réelle des compteurs/API locale.
$ingestion->ingestHourlyElectricityReading([
    'reading_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:00:00'),
    'metric' => 'gridImport',
    'metric_kind' => 'total',
    'unit' => 'Wh',
    'interval' => 'PT1H',
    'value' => random_int(100, 1200),
]);

echo "[OK] Ingestion horaire enregistrée en DB.\n";
exit(0);
