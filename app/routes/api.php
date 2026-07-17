<?php

declare(strict_types=1);

use App\Http\Controller\CostController;
use App\Http\Controller\IngestController;
use App\Http\Controller\MeterEntryController;
use App\Http\Controller\ReadingsController;
use App\Http\Controller\TariffController;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Router;
use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Repository\ApiTokenRepository;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Security\ApiToken;
use App\Security\AuthGuard;
use App\Security\UserContext;
use App\Security\WebAccessGuard;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Support\DynamicPricing;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// X-Content-Type-Options est posé par SecurityHeaders::send() ci-dessous.

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en JSON 503 (cohérent avec le Content-Type déjà émis) plutôt qu'en fatal (#130 C6).
// bootstrap.php charge l'autoloader avant de valider la config, donc SecurityHeaders
// reste disponible dans le catch pour poser les en-têtes de sécurité sur l'erreur.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    echo json_encode(['error' => 'Service unavailable']);

    return;
}

SecurityHeaders::send();

$request = Request::fromGlobals();

// ── Connexion BDD (avant l'auth : les jetons Bearer y sont vérifiés) ────────
try {
    $db  = new Database($config['database']);
    $pdo = $db->pdo();
} catch (\Throwable $e) {
    JsonResponse::error('DB connection failed: ' . $e->getMessage(), 503)->send();
    exit;
}

// ── Authentification : jeton Bearer (machines) OU session/Basic (navigateur) ─
$security = $config['web_security'] ?? [];
$security = is_array($security) ? $security : [];

$bearer   = ApiToken::fromAuthorizationHeader(
    isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION']
        : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : null)
);
$viaToken = false;

if ($bearer !== null && str_starts_with($bearer, ApiToken::TOKEN_PREFIX)) {
    // L'allowlist IP (si configurée) s'applique aussi aux agents.
    WebAccessGuard::enforceIp($security, true);

    $tokenRepo = new ApiTokenRepository($pdo);
    $auth = $tokenRepo->authenticate($bearer);
    if ($auth === null) {
        JsonResponse::error('Invalid or revoked token', 401)->send();
        exit;
    }

    $rateLimit = (int) ($config['api']['rate_limit_per_hour'] ?? 600);
    if ($tokenRepo->consumeRateLimit($auth['token_id'], $rateLimit) === false) {
        JsonResponse::error('Rate limit exceeded', 429)->send();
        exit;
    }

    $userId   = $auth['user_id'];
    $viaToken = true;
} else {
    AuthGuard::protect($config, true);
    $userId = UserContext::currentWebUserId($pdo, $config);
}

// ── Wiring des repositories scopés ──────────────────────────────────────────
try {
    $users      = new UserRepository($pdo);
    $isAdmin    = ($users->findById($userId)?->isAdmin()) ?? false;
    $profile    = $users->getProfile($userId);

    // Zone de marché ENTSO-E de l'utilisateur (profil), sinon celle de la config.
    $zone = $profile['bidding_zone'] ?? null;
    $zone = ($zone !== null && $zone !== '')
        ? $zone
        : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

    $elecRepo   = new ElectricityReadingRepository($pdo, $userId);
    $gasRepo    = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepo  = new UtilityReadingRepository($pdo, $userId, 'water');
    $syncState  = new WebhookSyncStateRepository($pdo, $userId);
    $tariffRepo = new TariffRepository($pdo, $userId, $isAdmin);
    $dynPriceRepo = new DynamicPriceRepository($pdo, $zone);
    $pricingMode  = (string) ($profile['pricing_mode'] ?? 'fixed');
    $costSvc    = new CostCalculationService(
        legacyRepo: $elecRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: $dynPriceRepo,
        dynamicConfig: $config['dynamic_prices'] ?? [],
        waterRepo: $waterRepo,
        pricingMode: $pricingMode,
    );
} catch (\Throwable $e) {
    JsonResponse::error('Bootstrap failed: ' . $e->getMessage(), 503)->send();
    exit;
}

$readings = new ReadingsController($elecRepo, $gasRepo, $waterRepo);
$cost     = new CostController($costSvc, DynamicPricing::isEnabled($config));
$tariffs  = new TariffController($tariffRepo);
$entries  = new MeterEntryController($gasRepo, $waterRepo, $elecRepo, $syncState);
$ingest   = new IngestController($elecRepo, $gasRepo, $waterRepo);

$router = new Router();

// ── Ingestion (agents) : unitaire + batch, idempotente. ───────────────────────
// SEULES routes autorisées à un jeton Bearer (scope « ingest ») : un jeton
// d'agent compromis ne peut donc que pousser des index, pas lire les données ni
// écrire des tarifs. L'affinage multi-scopes viendra en P7.
$router->add('POST', 'ingest_electricity', $ingest->electricity(...));
$router->add('POST', 'ingest_gas',         $ingest->gas(...));
$router->add('POST', 'ingest_water',       $ingest->water(...));

// ── Routes de session (navigateur) : refusées aux requêtes par jeton ──────────
if ($viaToken === false) {
    // GET (la route « live » a été retirée en P4 : plus de polling des compteurs LAN)
    $router->add('GET', 'today',          $readings->today(...));
    $router->add('GET', 'monthly_delta',  $readings->monthlyDelta(...));
    $router->add('GET', 'chart_data',     $readings->chartData(...));
    $router->add('GET', 'gas_history',    $readings->gasHistory(...));
    $router->add('GET', 'water_history',  $readings->waterHistory(...));
    $router->add('GET', 'electricity_history', $readings->electricityHistory(...));
    $router->add('GET', 'month_cost',     $cost->monthCost(...));
    $router->add('GET', 'cost_estimate',  $cost->costEstimate(...));
    $router->add('GET', 'gas_cost',       $cost->gasCost(...));
    $router->add('GET', 'gas_month_cost', $cost->gasMonthCost(...));
    $router->add('GET', 'water_month_cost', $cost->waterMonthCost(...));
    $router->add('GET', 'tariffs',        $tariffs->index(...));

    // POST (saisie manuelle + tarifs)
    $router->add('POST', 'electricity_entry', $entries->electricity(...));
    $router->add('POST', 'gas_entry',         $entries->gas(...));
    $router->add('POST', 'water_entry',       $entries->water(...));
    $router->add('POST', 'save_tariff', $tariffs->save(...));

    // La gestion des jetons API se fait sur la page « Mon compte » (account.php).
}

$router->dispatch($request)->send();
