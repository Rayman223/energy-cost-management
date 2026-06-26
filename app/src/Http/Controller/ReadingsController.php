<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\WaterRepository;

/**
 * Données de lecture du dashboard (index courants, deltas, séries, historiques,
 * état de synchronisation EnergyID).
 */
final class ReadingsController
{
    public function __construct(
        private readonly LegacyDailyRepository $legacyRepo,
        private readonly GasRepository $gasRepo,
        private readonly WaterRepository $waterRepo,
    ) {
    }

    public function today(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->legacyRepo->getTodayIndexValues());
    }

    public function monthlyDelta(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->legacyRepo->getMonthlyDeltas());
    }

    public function chartData(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->legacyRepo->getDailyDeltasForChart($request->queryInt('days', 30)));
    }

    public function gasHistory(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->gasRepo->getAllReadings());
    }

    public function waterHistory(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->waterRepo->getAllReadings());
    }

    public function syncStatus(Request $request): JsonResponse
    {
        return JsonResponse::ok([
            'prelevement_jour'   => $this->legacyRepo->getLastSentAt('prelevement-jour')?->format('c'),
            'prelevement_nuit'   => $this->legacyRepo->getLastSentAt('prelevement-nuit')?->format('c'),
            'injection_jour'     => $this->legacyRepo->getLastSentAt('injection-jour')?->format('c'),
            'injection_nuit'     => $this->legacyRepo->getLastSentAt('injection-nuit')?->format('c'),
            'production_solaire' => $this->legacyRepo->getLastSentAt('production-solaire')?->format('c'),
            'gaz_index'          => $this->legacyRepo->getLastSentAt('gas-index')?->format('c'),
            'water_index'        => $this->legacyRepo->getLastSentAt('water-index')?->format('c'),
        ]);
    }
}
