<?php

declare(strict_types=1);

/**
 * Ingestion horaire des index électricité + solaire depuis les API locales des
 * compteurs (HomeWizard P1 + dongle solaire), écrits dans le modèle à registres
 * pour l'utilisateur propriétaire.
 *
 * Usage : php app/scripts/cron_hourly.php [--user=<id>]
 * Sans --user : premier compte de la table users (le propriétaire).
 */

use App\Infrastructure\Database;
use App\Repository\ElectricityReadingRepository;
use App\Security\UserContext;
use App\Service\MeterApiService;

$config   = require __DIR__ . '/../bootstrap.php';
$database = new Database($config['database']);
$pdo      = $database->pdo();
$userId   = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
$repo     = new ElectricityReadingRepository($pdo, $userId);
$meterApi = new MeterApiService((int) ($config['meters']['timeout'] ?? 10));
$paths    = $config['meters']['paths'] ?? [];
$ts       = new DateTimeImmutable('now');
$errors   = [];

// ── Compteur P1 (électricité, 4 registres) ────────────────────────────────
try {
    $p1 = $meterApi->fetchJson($config['meters']['dries_url']);

    $repo->insertIndexes($ts, [
        'import_t1' => $meterApi->readNumericValue($p1, $paths['prelev_jour'] ?? []),
        'import_t2' => $meterApi->readNumericValue($p1, $paths['prelev_nuit'] ?? []),
        'export_t1' => $meterApi->readNumericValue($p1, $paths['injec_jour']  ?? []),
        'export_t2' => $meterApi->readNumericValue($p1, $paths['injec_nuit']  ?? []),
    ]);

    echo "[OK] Index electricite enregistres (user #$userId).\n";
} catch (\Throwable $e) {
    $errors[] = '[ERROR] Electricite: ' . $e->getMessage();
}

// ── Dongle solaire (registre production, index cumulatif kWh) ─────────────
try {
    $solar = $meterApi->fetchJson($config['meters']['solar_url']);

    $repo->insertIndexes($ts, [
        'production' => $meterApi->readNumericValue($solar, $paths['solar_production_wh'] ?? []),
    ]);

    echo "[OK] Index solaire enregistre (user #$userId).\n";
} catch (\Throwable $e) {
    $errors[] = '[ERROR] Solaire: ' . $e->getMessage();
}

// ── Exit ──────────────────────────────────────────────────────────────────
if ($errors !== []) {
    foreach ($errors as $err) {
        echo $err . "\n";
    }
    exit(1);
}

exit(0);
