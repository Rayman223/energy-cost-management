<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\LegacyDailyRepository;
use App\Service\DailyLegacyWebhookSyncService;
use App\Service\EnergyIdPayloadFactory;
use App\Service\EnergyIdV2Client;

$config = require __DIR__ . '/../bootstrap.php';

try {
    $database = new Database($config['database']);
    $repository = new LegacyDailyRepository($database->pdo());
    $energyIdClient = new EnergyIdV2Client(
        http: new HttpClient(),
        provisioningKey: $config['energyid']['provisioning_key'],
        provisioningSecret: $config['energyid']['provisioning_secret'],
        timeout: (int) ($config['energyid']['timeout'] ?? 15)
    );

    $service = new DailyLegacyWebhookSyncService(
        repository: $repository,
        payloadFactory: new EnergyIdPayloadFactory(),
        energyIdClient: $energyIdClient,
        device: $config['energyid']['device'],
    );

    $until   = new DateTimeImmutable('now');
    $reports = $service->syncUntil($until);

} catch (\Throwable $e) {
    echo '[FATAL] ' . $e->getMessage() . "\n";
    exit(1);
}

$errors = array_filter($reports, static fn (array $r): bool => ($r['result']['ok'] ?? false) === false);

if ($errors !== []) {
    foreach ($errors as $error) {
        if (($error['result']['type'] ?? '') === 'not_claimed') {
            echo sprintf(
                "[ACTION] Device not claimed. claimCode=%s claimUrl=%s exp=%s\n",
                $error['result']['claimCode'] ?? '-',
                $error['result']['claimUrl'] ?? '-',
                $error['result']['exp'] ?? '-'
            );
            continue;
        }

        echo sprintf(
            "[ERROR] %s/%s - attempts=%s status=%s error=%s body=%s type=%s\n",
            $error['source'],
            $error['remoteId'],
            $error['result']['attempts'] ?? 'n/a',
            $error['result']['status'] ?? 'n/a',
            $error['result']['error'] ?? '-',
            $error['result']['body'] ?? '-',
            $error['result']['type'] ?? '-'
        );
    }
    exit(1);
}

echo '[OK] Sync quotidienne EnergyID terminee (' . count($reports) . " metrique(s) traitee(s)).\n";
exit(0);