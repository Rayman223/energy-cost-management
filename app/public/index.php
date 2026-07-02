<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Security\UserContext;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Http\SecurityHeaders;
use App\Security\AuthGuard;
use App\View\View;

// Bootstrap — dégradation gracieuse si la base est indisponible.
$dbError      = null;
$deltas       = null;
$cost         = null;
$gasCostData  = null;
$syncStatus   = null;
$gasLatest    = null;
$waterLatest  = null;
$waterCostData = null;
$gasInitYear  = (int) date('Y');
$gasInitMonth = (int) date('n');
$waterInitYear  = (int) date('Y');
$waterInitMonth = (int) date('n');

require_once __DIR__ . '/../autoload.php';

SecurityHeaders::send();

try {
    $config     = require __DIR__ . '/../bootstrap.php';

    AuthGuard::protect($config);

    $db         = new Database($config['database']);
    $pdo        = $db->pdo();

    // Tenant courant : session OIDC, ou tenant unique en mode Basic Auth.
    $userId     = UserContext::currentWebUserId($pdo, $config);

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
    $costSvc    = new CostCalculationService(
        legacyRepo: $elecRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: $dynPriceRepo,
        dynamicConfig: $config['dynamic_prices'] ?? [],
        waterRepo: $waterRepo,
    );

    $deltas      = $elecRepo->getMonthlyDeltas();
    $cost        = $costSvc->estimateCurrentMonthElectricity();
    $cost['dynamic'] = $costSvc->estimateCurrentMonthElectricityDynamic();
    $gasCostData = $costSvc->estimateLastGasPeriod();
    if (!empty($gasCostData['period_from'])) {
        $gasPeriodFrom = new DateTimeImmutable($gasCostData['period_from']);
        $gasInitYear   = (int) $gasPeriodFrom->format('Y');
        $gasInitMonth  = (int) $gasPeriodFrom->format('n');
    }
    $gasLatest   = $gasRepo->getLatest();
    $waterLatest = $waterRepo->getLatest();
    if ($waterLatest !== null && !empty($waterLatest['reading_at'])) {
        $waterPeriod    = new DateTimeImmutable((string) $waterLatest['reading_at']);
        $waterInitYear  = (int) $waterPeriod->format('Y');
        $waterInitMonth = (int) $waterPeriod->format('n');
    }
    $waterCostData = $costSvc->estimateMonthWater($waterInitYear, $waterInitMonth);

    $syncStatus = [
        'prelevement_jour'   => $syncState->getLastSentAt('prelevement-jour')?->format('d/m H:i'),
        'prelevement_nuit'   => $syncState->getLastSentAt('prelevement-nuit')?->format('d/m H:i'),
        'injection_jour'     => $syncState->getLastSentAt('injection-jour')?->format('d/m H:i'),
        'injection_nuit'     => $syncState->getLastSentAt('injection-nuit')?->format('d/m H:i'),
        'production_solaire' => $syncState->getLastSentAt('production-solaire')?->format('d/m H:i'),
        'gaz_index'          => $syncState->getLastSentAt('gas-index')?->format('d/m H:i'),
        'water_index'        => $syncState->getLastSentAt('water-index')?->format('d/m H:i'),
    ];
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

echo (new View(__DIR__ . '/../templates'))->render('dashboard', [
    'dbError'      => $dbError,
    'deltas'       => $deltas,
    'cost'         => $cost,
    'gasCostData'  => $gasCostData,
    'gasLatest'    => $gasLatest,
    'waterLatest'  => $waterLatest,
    'waterCostData' => $waterCostData,
    'syncStatus'   => $syncStatus,
    'initYear'     => (int) date('Y'),
    'initMonth'    => (int) date('n'),
    'gasInitYear'  => $gasInitYear,
    'gasInitMonth' => $gasInitMonth,
    'waterInitYear'  => $waterInitYear,
    'waterInitMonth' => $waterInitMonth,
]);
