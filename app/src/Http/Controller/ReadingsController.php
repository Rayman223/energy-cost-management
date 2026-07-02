<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\ElectricityReadingRepository;
use App\Repository\WebhookSyncStateRepository;

/**
 * Données de lecture du dashboard (index courants, deltas, séries, historiques,
 * état de synchronisation EnergyID). Toutes les données sont scopées par
 * l'utilisateur courant (repositories construits avec le UserContext).
 */
final class ReadingsController
{
    public function __construct(
        private readonly ElectricityReadingRepository $electricityRepo,
        private readonly MeterReadingRepositoryInterface $gasRepo,
        private readonly MeterReadingRepositoryInterface $waterRepo,
        private readonly WebhookSyncStateRepository $syncState,
    ) {
    }

    public function today(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->electricityRepo->getTodayIndexValues());
    }

    public function monthlyDelta(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->electricityRepo->getMonthlyDeltas());
    }

    public function chartData(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->electricityRepo->getDailyDeltasForChart($request->queryInt('days', 30)));
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
            'prelevement_jour'   => $this->syncState->getLastSentAt('prelevement-jour')?->format('c'),
            'prelevement_nuit'   => $this->syncState->getLastSentAt('prelevement-nuit')?->format('c'),
            'injection_jour'     => $this->syncState->getLastSentAt('injection-jour')?->format('c'),
            'injection_nuit'     => $this->syncState->getLastSentAt('injection-nuit')?->format('c'),
            'production_solaire' => $this->syncState->getLastSentAt('production-solaire')?->format('c'),
            'gaz_index'          => $this->syncState->getLastSentAt('gas-index')?->format('c'),
            'water_index'        => $this->syncState->getLastSentAt('water-index')?->format('c'),
        ]);
    }
}
