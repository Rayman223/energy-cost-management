<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\LegacyIngestionRepository;
use App\Service\MeterApiService;

$config   = require __DIR__ . '/../bootstrap.php';
$database = new Database($config['database']);
$repo     = new LegacyIngestionRepository($database->pdo());
$meterApi = new MeterApiService((int) ($config['meters']['timeout'] ?? 10));
$paths    = $config['meters']['paths'] ?? [];
$ts       = new DateTimeImmutable('now');
$errors   = [];

// ── P1 meter (Dries) ──────────────────────────────────────────────────────
try {
    $dries = $meterApi->fetchJson($config['meters']['dries_url']);

    $repo->insertDataDries(
        timestamp:  $ts,
        prelevJour: $meterApi->readNumericValue($dries, $paths['prelev_jour'] ?? []),
        prelevNuit: $meterApi->readNumericValue($dries, $paths['prelev_nuit'] ?? []),
        injecJour:  $meterApi->readNumericValue($dries, $paths['injec_jour']  ?? []),
        injecNuit:  $meterApi->readNumericValue($dries, $paths['injec_nuit']  ?? []),
    );

    echo "[OK] Data_Dries enregistre.\n";
} catch (\Throwable $e) {
    $errors[] = '[ERROR] Dries: ' . $e->getMessage();
}

// ── Solar dongle ──────────────────────────────────────────────────────────
// total_power_export_kwh : index cumulatif kWh, meme structure que P1 meter.
try {
    $solar = $meterApi->fetchJson($config['meters']['solar_url']);

    $repo->insertDataSolaire(
        timestamp:     $ts,
        productionKwh: $meterApi->readNumericValue($solar, $paths['solar_production_wh'] ?? []),
    );

    echo "[OK] Data_Solaire enregistre.\n";
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