<?php

declare(strict_types=1);

use App\Http\Controller\CostController;
use App\Http\Controller\MeterController;
use App\Http\Controller\MeterEntryController;
use App\Http\Controller\ReadingsController;
use App\Http\Controller\TariffController;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Router;
use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Security\AuthGuard;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Security\UserContext;
use App\Service\CostCalculationService;
use App\Service\MeterApiService;
use App\Service\TariffCalculatorService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// X-Content-Type-Options est posé par SecurityHeaders::send() ci-dessous.

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

AuthGuard::protect($config, true);

$request = Request::fromGlobals();

try {
    $db         = new Database($config['database']);
    $pdo        = $db->pdo();

    // Tenant courant : session OIDC, ou tenant unique en mode Basic Auth.
    $userId     = UserContext::currentWebUserId($pdo, $config);

    $elecRepo   = new ElectricityReadingRepository($pdo, $userId);
    $gasRepo    = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepo  = new UtilityReadingRepository($pdo, $userId, 'water');
    $syncState  = new WebhookSyncStateRepository($pdo, $userId);
    $tariffRepo = new TariffRepository($pdo);
    $dynPriceRepo = new DynamicPriceRepository($pdo);
    $costSvc    = new CostCalculationService(
        legacyRepo: $elecRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: $dynPriceRepo,
        dynamicConfig: $config['dynamic_prices'] ?? [],
        waterRepo: $waterRepo,
    );
} catch (\Throwable $e) {
    JsonResponse::error('DB connection failed: ' . $e->getMessage(), 503)->send();
    exit;
}

$meterApi = new MeterApiService((int) ($config['meters']['timeout'] ?? 10));
$meters   = new MeterController(
    $meterApi,
    (string) ($config['meters']['dries_url'] ?? ''),
    (string) ($config['meters']['solar_url'] ?? ''),
);
$readings = new ReadingsController($elecRepo, $gasRepo, $waterRepo, $syncState);
$cost     = new CostController($costSvc);
$tariffs  = new TariffController($tariffRepo);
$entries  = new MeterEntryController($gasRepo, $waterRepo);

$router = new Router();

// ── GET ──────────────────────────────────────────────────────────────────────
$router->add('GET', 'live',           $meters->live(...));
$router->add('GET', 'today',          $readings->today(...));
$router->add('GET', 'monthly_delta',  $readings->monthlyDelta(...));
$router->add('GET', 'chart_data',     $readings->chartData(...));
$router->add('GET', 'gas_history',    $readings->gasHistory(...));
$router->add('GET', 'water_history',  $readings->waterHistory(...));
$router->add('GET', 'sync_status',    $readings->syncStatus(...));
$router->add('GET', 'month_cost',     $cost->monthCost(...));
$router->add('GET', 'cost_estimate',  $cost->costEstimate(...));
$router->add('GET', 'gas_cost',       $cost->gasCost(...));
$router->add('GET', 'gas_month_cost', $cost->gasMonthCost(...));
$router->add('GET', 'water_month_cost', $cost->waterMonthCost(...));
$router->add('GET', 'tariffs',        $tariffs->index(...));

// ── POST ─────────────────────────────────────────────────────────────────────
$router->add('POST', 'gas_entry',   $entries->gas(...));
$router->add('POST', 'water_entry', $entries->water(...));
$router->add('POST', 'save_tariff', $tariffs->save(...));

$router->dispatch($request)->send();
