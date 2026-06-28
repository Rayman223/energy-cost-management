<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\DynamicPriceRepository;
use App\Service\EntsoePriceClient;
use App\Service\EntsoePriceParser;

/**
 * Récupère les prix day-ahead du marché spot (ENTSO-E) et les stocke en base.
 *
 * À planifier une fois par jour, après la publication day-ahead (~13h CET) :
 *   30 13 * * *  php /var/www/Manage-energy-costs/app/scripts/cron_dynamic_prices.php
 */

$config = require __DIR__ . '/../bootstrap.php';

/** @var array<string,mixed> $dynCfg */
$dynCfg = $config['dynamic_prices'] ?? [];

if (($dynCfg['enabled'] ?? false) !== true) {
    echo "[SKIP] Tarif dynamique désactivé (dynamic_prices.enabled = false).\n";
    exit(0);
}

if (($dynCfg['provider'] ?? 'entsoe') !== 'entsoe') {
    echo '[ERROR] Provider non supporté: ' . (string) ($dynCfg['provider'] ?? '') . "\n";
    exit(1);
}

$token = (string) ($dynCfg['security_token'] ?? '');
if ($token === '' || $token === 'change_me') {
    echo "[ERROR] Token ENTSO-E manquant (dynamic_prices.security_token).\n";
    exit(1);
}

$database = new Database($config['database']);
$repo     = new DynamicPriceRepository($database->pdo());
$client   = new EntsoePriceClient(
    http:          new HttpClient(),
    parser:        new EntsoePriceParser(),
    apiUrl:        (string) ($dynCfg['api_url'] ?? 'https://web-api.tp.entsoe.eu/api'),
    securityToken: $token,
    biddingZone:   (string) ($dynCfg['bidding_zone'] ?? '10YBE----------2'),
    timeout:       (int) ($dynCfg['timeout'] ?? 30),
);

// Fenêtre : aujourd'hui 00:00 → après-demain 00:00 (locale) afin de couvrir
// le jour courant et le jour J+1 publié en day-ahead.
$from = new DateTimeImmutable('today 00:00');
$to   = $from->modify('+2 days');

try {
    $prices = $client->fetchDayAheadPrices($from, $to);
} catch (\Throwable $e) {
    echo '[ERROR] ' . $e->getMessage() . "\n";
    exit(1);
}

if ($prices === []) {
    echo "[WARN] Aucun prix retourné pour la fenêtre demandée.\n";
    exit(0);
}

$count = $repo->upsertPrices($prices, (string) ($dynCfg['provider'] ?? 'entsoe'));

echo sprintf(
    "[OK] %d prix dynamiques enregistrés (%s → %s).\n",
    $count,
    $from->format('Y-m-d'),
    $to->format('Y-m-d'),
);
exit(0);
