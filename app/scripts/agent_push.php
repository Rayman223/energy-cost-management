<?php

declare(strict_types=1);

/**
 * Agent client de référence : lit les index sur les compteurs LOCAUX
 * (HomeWizard P1 + dongle solaire, cf. config `meters`) et les POUSSE vers
 * l'API du serveur communautaire avec un jeton API.
 *
 * À planifier chez le membre (cron horaire, Home Assistant, etc.) :
 *   0 * * * *  php agent_push.php
 *
 * Configuration (app/config/config.php) :
 *   'agent' => [
 *       'api_url'   => 'https://energie.example.eu/api',
 *       'api_token' => 'mec_…',   // créé via l'action api_token_create
 *   ],
 * Réutilise `meters` (URLs locales + chemins JSON) pour la lecture.
 *
 * Idempotent : renvoyer un relevé déjà transmis ne crée pas de doublon.
 */

use App\Infrastructure\HttpClient;
use App\Service\MeterApiService;

$config = require __DIR__ . '/../bootstrap.php';

$agentCfg = $config['agent'] ?? [];
$apiUrl   = (string) ($agentCfg['api_url'] ?? '');
$apiToken = (string) ($agentCfg['api_token'] ?? '');

if ($apiUrl === '' || $apiToken === '' || $apiToken === 'change_me') {
    echo "[ERROR] Config agent manquante (agent.api_url / agent.api_token).\n";
    exit(1);
}

$meterApi = new MeterApiService((int) ($config['meters']['timeout'] ?? 10));
$paths    = $config['meters']['paths'] ?? [];
$ts       = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$reading  = ['timestamp' => $ts];
$errors   = [];

// ── Lecture locale : compteur P1 (électricité) ────────────────────────────
try {
    $p1 = $meterApi->fetchJson($config['meters']['dries_url']);
    $reading['import_t1'] = $meterApi->readNumericValue($p1, $paths['prelev_jour'] ?? []);
    $reading['import_t2'] = $meterApi->readNumericValue($p1, $paths['prelev_nuit'] ?? []);
    $reading['export_t1'] = $meterApi->readNumericValue($p1, $paths['injec_jour']  ?? []);
    $reading['export_t2'] = $meterApi->readNumericValue($p1, $paths['injec_nuit']  ?? []);
} catch (\Throwable $e) {
    $errors[] = '[WARN] Lecture P1 impossible: ' . $e->getMessage();
}

// ── Lecture locale : dongle solaire ───────────────────────────────────────
try {
    $solar = $meterApi->fetchJson($config['meters']['solar_url']);
    $reading['production'] = $meterApi->readNumericValue($solar, $paths['solar_production_wh'] ?? []);
} catch (\Throwable $e) {
    $errors[] = '[WARN] Lecture solaire impossible: ' . $e->getMessage();
}

if (count($reading) === 1) { // seul le timestamp
    foreach ($errors as $err) {
        echo $err . "\n";
    }
    echo "[ERROR] Aucun index lu, rien à pousser.\n";
    exit(1);
}

// ── Push vers l'API communautaire ─────────────────────────────────────────
$http = new HttpClient();
$response = $http->postJson(
    $apiUrl . '?action=ingest_electricity',
    ['readings' => [$reading]],
    (int) ($agentCfg['timeout'] ?? 15),
    ['Authorization' => 'Bearer ' . $apiToken],
);

foreach ($errors as $err) {
    echo $err . "\n";
}

if ($response['ok'] !== true) {
    echo sprintf(
        "[ERROR] Push refusé — status=%s error=%s body=%s\n",
        $response['status'],
        $response['error'] !== '' ? $response['error'] : '-',
        $response['body'] !== '' ? $response['body'] : '-',
    );
    exit(1);
}

echo '[OK] Index poussés (' . $ts . ') — ' . $response['body'] . "\n";
exit(0);
