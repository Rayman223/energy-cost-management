<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\ElectricityReadingRepository;
use App\Repository\EnergyIdIntegrationRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Service\DailyLegacyWebhookSyncService;
use App\Service\EnergyIdPayloadFactory;
use App\Service\EnergyIdV2Client;

/**
 * Sync quotidienne vers EnergyID, PAR UTILISATEUR ayant activé l'intégration
 * (opt-in, BE/NL). Chaque membre a un device dérivé de son id ; le claim se fait
 * dans son propre compte EnergyID (le claimCode est loggué la 1re fois).
 */

$config = require __DIR__ . '/../bootstrap.php';

$logger = static function (string $message): void {
    echo '[' . date('H:i:s') . '] ' . $message . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

$logger('[start] Lancement sync EnergyID — ' . date('Y-m-d H:i:s'));

$energyCfg = $config['energyid'] ?? [];
if (($energyCfg['enabled'] ?? true) === false) {
    $logger('[SKIP] EnergyID désactivé globalement (energyid.enabled = false).');
    exit(0);
}

try {
    $database = new Database($config['database']);
    $pdo      = $database->pdo();
    $logger('[db] Connexion OK.');
} catch (\Throwable $e) {
    $logger('[FATAL] ' . $e->getMessage());
    exit(1);
}

$integrationRepo = new EnergyIdIntegrationRepository($pdo);
$enabled = $integrationRepo->listEnabled();

if ($enabled === []) {
    $logger('[OK] Aucun utilisateur avec EnergyID activé.');
    exit(0);
}

$energyIdClient = new EnergyIdV2Client(
    http:               new HttpClient(),
    provisioningKey:    $energyCfg['provisioning_key'] ?? '',
    provisioningSecret: $energyCfg['provisioning_secret'] ?? '',
    timeout:            (int) ($energyCfg['timeout'] ?? 15),
);

/** @var array<string, mixed> $deviceTemplate */
$deviceTemplate = is_array($energyCfg['device'] ?? null) ? $energyCfg['device'] : [];
$until          = new DateTimeImmutable('now');
$hadError       = false;

foreach ($enabled as $entry) {
    $userId   = $entry['user_id'];
    $deviceId = $entry['device_id'];
    $logger(sprintf('[user %d] Sync (device %s)...', $userId, $deviceId));

    // Device par utilisateur : template global + deviceId propre au membre.
    $device = array_merge($deviceTemplate, ['deviceId' => $deviceId]);

    $service = new DailyLegacyWebhookSyncService(
        electricityRepository: new ElectricityReadingRepository($pdo, $userId),
        gasRepository:         new UtilityReadingRepository($pdo, $userId, 'gas'),
        waterRepository:       new UtilityReadingRepository($pdo, $userId, 'water'),
        syncState:             new WebhookSyncStateRepository($pdo, $userId),
        payloadFactory:        new EnergyIdPayloadFactory(),
        energyIdClient:        $energyIdClient,
        device:                $device,
        logger:                static function (string $m) use ($logger, $userId): void {
            $logger('[user ' . $userId . '] ' . $m);
        },
    );

    try {
        $reports = $service->syncUntil($until);
    } catch (\Throwable $e) {
        $logger(sprintf('[user %d] [ERROR] %s', $userId, $e->getMessage()));
        $hadError = true;
        continue;
    }

    // Un envoi réussi (webhook obtenu) → device réclamé côté EnergyID.
    foreach ($reports as $report) {
        if (($report['result']['ok'] ?? false) === true) {
            $integrationRepo->markClaimed($userId);
            break;
        }
        if (($report['result']['type'] ?? '') === 'not_claimed') {
            $logger(sprintf(
                '[user %d] [ACTION] Device non réclamé. claimCode=%s claimUrl=%s',
                $userId,
                $report['result']['claimCode'] ?? '-',
                $report['result']['claimUrl'] ?? '-',
            ));
        }
    }
}

$logger('[end] Sync EnergyID terminée' . ($hadError ? ' (avec erreurs).' : '.'));
exit($hadError ? 1 : 0);
