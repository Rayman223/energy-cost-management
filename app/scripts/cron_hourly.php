<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\EnergyRepository;
use App\Service\EnergyIngestionService;
use App\Service\EnergyWebhookService;

$config = require __DIR__ . '/../bootstrap.php';

$database = new Database($config['database']);
$pdo = $database->pdo();

$ingestion = new EnergyIngestionService($pdo);

// TODO: remplacer par appel réel aux compteurs/API locales.
$ingestion->ingestHourlyElectricityReading([
    'reading_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:00:00'),
    'metric' => 'gridImport',
    'metric_kind' => 'total',
    'unit' => 'Wh',
    'interval' => 'PT1H',
    'value' => random_int(100, 1200),
]);

$repository = new EnergyRepository($pdo);
$webhookService = new EnergyWebhookService(
    repository: $repository,
    httpClient: new HttpClient(),
    webhookUrl: $config['webhook']['url'],
    remoteId: $config['site']['remote_id'],
    remoteName: $config['site']['remote_name'],
    timeout: (int) ($config['webhook']['timeout'] ?? 15)
);

$result = $webhookService->publishHourlyBatch();

if ($result['ok']) {
    echo "[OK] Cron terminé: " . ($result['message'] ?? 'Webhook envoyé') . PHP_EOL;
    exit(0);
}

echo '[ERROR] Envoi webhook échoué (' . ($result['status'] ?? 'n/a') . '): ' . ($result['error'] ?: $result['body']) . PHP_EOL;
exit(1);
