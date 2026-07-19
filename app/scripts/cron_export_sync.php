<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Integration\ModuleRegistry;
use App\Repository\UserIntegrationRepository;

/**
 * Sync quotidienne des « connecteurs d'export » (système de modules #70).
 *
 * Itère sur les modules du registre ; pour chaque module globalement activé,
 * synchronise chaque utilisateur ayant opté-in (table user_integrations). Un
 * patch de settings retourné par le module (ex. claimed_at EnergyID au premier
 * envoi) est persisté. Remplace l'ancien cron_daily_webhook.php (dédié EnergyID),
 * conservé comme alias déprécié.
 */

$config = require __DIR__ . '/../bootstrap.php';

$logger = static function (string $message): void {
    echo '[' . date('H:i:s') . '] ' . $message . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

$logger('[start] Lancement sync export — ' . date('Y-m-d H:i:s'));

// Modules actifs globalement : détermine s'il y a du travail AVANT d'ouvrir la
// base (un cron sans module actif ne doit pas échouer sur une base injoignable).
$modules = [];
foreach (ModuleRegistry::all($config) as $module) {
    if ($module->isGloballyEnabled()) {
        $modules[] = $module;
    } else {
        $logger(sprintf('[SKIP] Module %s désactivé globalement.', $module->key()));
    }
}

if ($modules === []) {
    $logger('[OK] Aucun module d\'export actif.');
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

$repo     = new UserIntegrationRepository($pdo);
$until    = new DateTimeImmutable('now');
$hadError = false;

foreach ($modules as $module) {
    $key = $module->key();

    $users = $repo->listEnabledUsers($key);
    if ($users === []) {
        $logger(sprintf('[%s] Aucun utilisateur activé.', $key));
        continue;
    }

    foreach ($users as $entry) {
        $userId = $entry['user_id'];
        $logger(sprintf('[%s][user %d] Sync...', $key, $userId));

        $userLogger = static function (string $m) use ($logger, $key, $userId): void {
            $logger(sprintf('[%s][user %d] %s', $key, $userId, $m));
        };

        try {
            $out = $module->syncUser($pdo, $userId, $entry['settings'], $until, $userLogger);
        } catch (\Throwable $e) {
            $userLogger('[ERROR] ' . $e->getMessage());
            $hadError = true;
            continue;
        }

        if ($out['settingsPatch'] !== []) {
            $repo->patchSettings($userId, $key, $out['settingsPatch']);
        }
    }
}

$logger('[end] Sync export terminée' . ($hadError ? ' (avec erreurs).' : '.'));
exit($hadError ? 1 : 0);
