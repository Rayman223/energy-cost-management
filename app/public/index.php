<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\GasRepository;
use App\Repository\WaterRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\TariffRepository;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Security\WebAccessGuard;
use App\View\View;

// Bootstrap — dégradation gracieuse si la base est indisponible.
$dbError      = null;
$deltas       = null;
$cost         = null;
$gasCostData  = null;
$syncStatus   = null;
$gasLatest    = null;
$waterLatest  = null;
$gasRows      = [];
$waterRows    = [];
$gasInitYear  = (int) date('Y');
$gasInitMonth = (int) date('n');

try {
    $config     = require __DIR__ . '/../bootstrap.php';

    WebAccessGuard::protect($config['web_security'] ?? []);

    $db         = new Database($config['database']);
    $pdo        = $db->pdo();
    $legacyRepo = new LegacyDailyRepository($pdo);
    $gasRepo    = new GasRepository($pdo);
    $waterRepo  = new WaterRepository($pdo);
    $tariffRepo = new TariffRepository($pdo);
    $costSvc    = new CostCalculationService($legacyRepo, $tariffRepo, $gasRepo, new TariffCalculatorService());

    $deltas      = $legacyRepo->getMonthlyDeltas();
    $cost        = $costSvc->estimateCurrentMonthElectricity();
    $gasCostData = $costSvc->estimateLastGasPeriod();
    if (!empty($gasCostData['period_from'])) {
        $gasPeriodFrom = new DateTimeImmutable($gasCostData['period_from']);
        $gasInitYear   = (int) $gasPeriodFrom->format('Y');
        $gasInitMonth  = (int) $gasPeriodFrom->format('n');
    }
    $gasLatest   = $gasRepo->getLatest();
    $waterLatest = $waterRepo->getLatest();
    $gasRows     = $gasRepo->getAllReadings();
    $waterRows   = $waterRepo->getAllReadings();

    $syncStatus = [
        'prelevement_jour'   => $legacyRepo->getLastSentAt('prelevement-jour')?->format('d/m H:i'),
        'prelevement_nuit'   => $legacyRepo->getLastSentAt('prelevement-nuit')?->format('d/m H:i'),
        'injection_jour'     => $legacyRepo->getLastSentAt('injection-jour')?->format('d/m H:i'),
        'injection_nuit'     => $legacyRepo->getLastSentAt('injection-nuit')?->format('d/m H:i'),
        'production_solaire' => $legacyRepo->getLastSentAt('production-solaire')?->format('d/m H:i'),
        'gaz_index'          => $legacyRepo->getLastSentAt('gas-index')?->format('d/m H:i'),
        'water_index'        => $legacyRepo->getLastSentAt('water-index')?->format('d/m H:i'),
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
    'gasRows'      => $gasRows,
    'waterRows'    => $waterRows,
    'syncStatus'   => $syncStatus,
    'initYear'     => (int) date('Y'),
    'initMonth'    => (int) date('n'),
    'gasInitYear'  => $gasInitYear,
    'gasInitMonth' => $gasInitMonth,
]);
