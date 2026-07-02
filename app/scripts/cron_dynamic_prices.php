<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\DynamicPriceRepository;
use App\Service\EntsoePriceClient;
use App\Service\EntsoePriceParser;

/**
 * Récupère les prix day-ahead du marché spot (ENTSO-E) et les stocke en base,
 * pour TOUTES les zones de marché utilisées : celles des profils utilisateurs
 * (user_profiles.bidding_zone) + la zone par défaut de la config.
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
$pdo      = $database->pdo();

// Zones à couvrir : zone de config + zones distinctes des profils utilisateurs.
$zones = [(string) ($dynCfg['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE)];
$stmt  = $pdo->query(
    "SELECT DISTINCT bidding_zone FROM user_profiles WHERE bidding_zone IS NOT NULL AND bidding_zone <> ''"
);
if ($stmt !== false) {
    foreach ($stmt->fetchAll() as $row) {
        $zones[] = (string) $row['bidding_zone'];
    }
}
$zones = array_values(array_unique($zones));

// Fenêtre : aujourd'hui 00:00 → après-demain 00:00 (locale) afin de couvrir
// le jour courant et le jour J+1 publié en day-ahead.
$from = new DateTimeImmutable('today 00:00');
$to   = $from->modify('+2 days');

$hadError = false;
foreach ($zones as $zone) {
    $client = new EntsoePriceClient(
        http:          new HttpClient(),
        parser:        new EntsoePriceParser(),
        apiUrl:        (string) ($dynCfg['api_url'] ?? 'https://web-api.tp.entsoe.eu/api'),
        securityToken: $token,
        biddingZone:   $zone,
        timeout:       (int) ($dynCfg['timeout'] ?? 30),
    );

    try {
        $prices = $client->fetchDayAheadPrices($from, $to);
    } catch (\Throwable $e) {
        echo "[ERROR] {$zone} : " . $e->getMessage() . "\n";
        $hadError = true;
        continue;
    }

    if ($prices === []) {
        echo "[WARN] {$zone} : aucun prix retourné pour la fenêtre demandée.\n";
        continue;
    }

    $repo  = new DynamicPriceRepository($pdo, $zone);
    $count = $repo->upsertPrices($prices, (string) ($dynCfg['provider'] ?? 'entsoe'));

    echo sprintf("[OK] %s : %d prix enregistrés (%s → %s).\n", $zone, $count, $from->format('Y-m-d'), $to->format('Y-m-d'));
}

exit($hadError ? 1 : 0);
