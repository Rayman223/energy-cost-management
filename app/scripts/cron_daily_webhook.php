<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Security\UserContext;
use App\Service\DailyLegacyWebhookSyncService;
use App\Service\EnergyIdPayloadFactory;
use App\Service\EnergyIdV2Client;

$config = require __DIR__ . '/../bootstrap.php';

// Logger : préfixe chaque ligne avec l'heure exacte et flush immédiat.
$logger = static function (string $message): void {
    echo '[' . date('H:i:s') . '] ' . $message . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

$logger('[start] Lancement sync EnergyID — ' . date('Y-m-d H:i:s'));

try {
    $database = new Database($config['database']);
    $logger('[db] Connexion OK.');

    $pdo             = $database->pdo();
    $userId          = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
    $repository      = new ElectricityReadingRepository($pdo, $userId);
    $gasRepository   = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepository = new UtilityReadingRepository($pdo, $userId, 'water');
    $syncState       = new WebhookSyncStateRepository($pdo, $userId);
    $energyIdClient  = new EnergyIdV2Client(
        http:               new HttpClient(),
        provisioningKey:    $config['energyid']['provisioning_key'],
        provisioningSecret: $config['energyid']['provisioning_secret'],
        timeout:            (int) ($config['energyid']['timeout'] ?? 15)
    );

    $service = new DailyLegacyWebhookSyncService(
        electricityRepository: $repository,
        gasRepository:   $gasRepository,
        waterRepository: $waterRepository,
        syncState:       $syncState,
        payloadFactory:  new EnergyIdPayloadFactory(),
        energyIdClient:  $energyIdClient,
        device:          $config['energyid']['device'],
        logger:          $logger,
    );

    $until   = new DateTimeImmutable('now');
    $reports = $service->syncUntil($until);

} catch (\Throwable $e) {
    $logger('[FATAL] ' . $e->getMessage());
    $logger('[FATAL] ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

if ($reports === []) {
    $logger('[OK] Rien a envoyer, tout est a jour.');
    exit(0);
}

$errors = array_filter($reports, static fn (array $r): bool => ($r['result']['ok'] ?? false) === false);

if ($errors !== []) {
    foreach ($errors as $error) {
        if (($error['result']['type'] ?? '') === 'not_claimed') {
            $logger(sprintf(
                '[ACTION] Device not claimed. claimCode=%s claimUrl=%s exp=%s',
                $error['result']['claimCode'] ?? '-',
                $error['result']['claimUrl'] ?? '-',
                $error['result']['exp'] ?? '-'
            ));
            continue;
        }

        $logger(sprintf(
            '[ERROR] %s/%s - attempts=%s status=%s error=%s body=%s type=%s',
            $error['source'],
            $error['remoteId'],
            $error['result']['attempts'] ?? 'n/a',
            $error['result']['status'] ?? 'n/a',
            $error['result']['error'] ?? '-',
            $error['result']['body'] ?? '-',
            $error['result']['type'] ?? '-'
        ));
    }
    $logger('[end] Termine avec erreurs.');
    exit(1);
}

$logger('[OK] Sync quotidienne EnergyID terminee (' . count($reports) . ' envoi(s) effectue(s)).');
exit(0);