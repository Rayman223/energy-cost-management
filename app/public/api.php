<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\TariffRepository;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Security\WebAccessGuard;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config = require __DIR__ . '/../bootstrap.php';

WebAccessGuard::protect($config['web_security'] ?? [], true);

function jsonOut(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db         = new Database($config['database']);
    $pdo        = $db->pdo();
    $legacyRepo = new LegacyDailyRepository($pdo);
    $gasRepo    = new GasRepository($pdo);
    $tariffRepo = new TariffRepository($pdo);
    $costSvc    = new CostCalculationService(
        legacyRepo: $legacyRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
    );
} catch (\Throwable $e) {
    jsonOut(['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()], 503);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // ── GET actions ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        match ($action) {
            'live' => (static function () use ($config): never {
                $meterApi = new \App\Service\MeterApiService((int) ($config['meters']['timeout'] ?? 10));
                $result   = ['ok' => true, 'timestamp' => date('c')];

                try {
                    $dries = $meterApi->fetchJson($config['meters']['dries_url']);
                    $result['dries_w'] = $meterApi->readNumericValue($dries, ['active_power_w']);
                } catch (\Throwable $e) {
                    $result['dries_error'] = $e->getMessage();
                }

                try {
                    $solar = $meterApi->fetchJson($config['meters']['solar_url']);
                    $result['solar_w'] = $meterApi->readNumericValue($solar, ['active_power_w']);
                } catch (\Throwable $e) {
                    $result['solar_error'] = $e->getMessage();
                }

                jsonOut($result);
            })(),
            'today'        => jsonOut($legacyRepo->getTodayIndexValues()),
            'monthly_delta'=> jsonOut($legacyRepo->getMonthlyDeltas()),
            'chart_data'   => jsonOut($legacyRepo->getDailyDeltasForChart((int) ($_GET['days'] ?? 30))),
            'month_cost'   => (static function () use ($costSvc): never {
                $year  = (int) ($_GET['year']  ?? date('Y'));
                $month = (int) ($_GET['month'] ?? date('n'));
                if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                    jsonOut(['ok' => false, 'error' => 'Invalid year/month'], 422);
                }
                jsonOut($costSvc->estimateMonthElectricity($year, $month));
            })(),
            'gas_history'  => jsonOut($gasRepo->getAllReadings()),
            'cost_estimate'=> jsonOut($costSvc->estimateCurrentMonthElectricity()),
            'gas_cost'       => jsonOut($costSvc->estimateLastGasPeriod()),
            'gas_month_cost' => (static function () use ($costSvc): never {
                $year  = (int) ($_GET['year']  ?? date('Y'));
                $month = (int) ($_GET['month'] ?? date('n'));
                if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                    jsonOut(['ok' => false, 'error' => 'Invalid year/month'], 422);
                }
                jsonOut($costSvc->estimateMonthGas($year, $month));
            })(),
            'sync_status'  => jsonOut([
                'prelevement_jour'  => $legacyRepo->getLastSentAt('prelevement-jour')?->format('c'),
                'prelevement_nuit'  => $legacyRepo->getLastSentAt('prelevement-nuit')?->format('c'),
                'injection_jour'    => $legacyRepo->getLastSentAt('injection-jour')?->format('c'),
                'injection_nuit'    => $legacyRepo->getLastSentAt('injection-nuit')?->format('c'),
                'production_solaire'=> $legacyRepo->getLastSentAt('production-solaire')?->format('c'),
            ]),
            'tariffs'      => jsonOut([
                'electricity' => array_map(
                    static fn ($g) => [
                        'id'         => $g->id,
                        'name'       => $g->name,
                        'valid_from' => $g->validFrom->format('Y-m-d'),
                        'valid_to'   => $g->validTo?->format('Y-m-d'),
                        'lines'      => $g->toTariffArray(),
                    ],
                    $tariffRepo->findAll('electricity')
                ),
                'gas' => array_map(
                    static fn ($g) => [
                        'id'         => $g->id,
                        'name'       => $g->name,
                        'valid_from' => $g->validFrom->format('Y-m-d'),
                        'valid_to'   => $g->validTo?->format('Y-m-d'),
                        'lines'      => $g->toTariffArray(),
                    ],
                    $tariffRepo->findAll('gas')
                ),
            ]),
            default => jsonOut(['ok' => false, 'error' => 'Unknown action'], 400),
        };
    }

    // ── POST actions ─────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        match ($action) {
            'gas_entry' => (static function () use ($gasRepo, $body): never {
                $counterM3 = filter_var($body['counter_m3'] ?? null, FILTER_VALIDATE_FLOAT);
                $readingAt = $body['reading_at'] ?? null;

                if ($counterM3 === false || $counterM3 <= 0) {
                    jsonOut(['ok' => false, 'error' => 'Invalid counter_m3 value'], 422);
                }

                $ts = $readingAt
                    ? new \DateTimeImmutable($readingAt)
                    : new \DateTimeImmutable('now');

                // Validate not before last reading
                $latest = $gasRepo->getLatest();
                if ($latest && $ts <= new \DateTimeImmutable($latest['reading_at'])) {
                    jsonOut(['ok' => false, 'error' => 'Reading date must be after the latest entry (' . $latest['reading_at'] . ')'], 422);
                }
                if ($latest && $counterM3 < (float) $latest['counter_m3']) {
                    jsonOut(['ok' => false, 'error' => 'Counter value must be ≥ previous reading (' . $latest['counter_m3'] . ' m³)'], 422);
                }

                $gasRepo->save($ts, $counterM3);
                jsonOut(['ok' => true, 'saved_at' => $ts->format('c'), 'counter_m3' => $counterM3]);
            })(),

            'save_tariff' => (static function () use ($tariffRepo, $body): never {
                $required = ['energy_type', 'name', 'valid_from', 'lines'];
                foreach ($required as $field) {
                    if (empty($body[$field])) {
                        jsonOut(['ok' => false, 'error' => "Missing field: $field"], 422);
                    }
                }
                if (!in_array($body['energy_type'], ['electricity', 'gas'], true)) {
                    jsonOut(['ok' => false, 'error' => 'energy_type must be electricity or gas'], 422);
                }
                $id = $tariffRepo->saveGrid(
                    energyType: $body['energy_type'],
                    name: $body['name'],
                    validFrom: new \DateTimeImmutable($body['valid_from']),
                    validTo: isset($body['valid_to']) ? new \DateTimeImmutable($body['valid_to']) : null,
                    lines: (array) $body['lines'],
                );
                jsonOut(['ok' => true, 'id' => $id]);
            })(),

            default => jsonOut(['ok' => false, 'error' => 'Unknown POST action'], 400),
        };
    }

    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);

} catch (\Throwable $e) {
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}