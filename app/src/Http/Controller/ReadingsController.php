<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Pagination;
use App\Http\Request;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\ElectricityReadingRepository;
use App\Service\UtilityConsumptionSeriesService;

/**
 * Données de lecture du dashboard (index courants, deltas, séries, historiques).
 * Toutes les données sont scopées par l'utilisateur courant (repositories
 * construits avec le UserContext).
 */
final class ReadingsController
{
    public function __construct(
        private readonly ElectricityReadingRepository $electricityRepo,
        private readonly MeterReadingRepositoryInterface $gasRepo,
        private readonly MeterReadingRepositoryInterface $waterRepo,
        private readonly UtilityConsumptionSeriesService $volumeSeries = new UtilityConsumptionSeriesService(),
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

    /**
     * Historiques paginés (#257) : `{ items, total, page, per_page }`. Sans la
     * pagination, l'électricité était plafonnée à 100 horodatages — les relevés
     * plus anciens étaient inatteignables depuis la page « Saisie des index ».
     */
    public function gasHistory(Request $request): JsonResponse
    {
        return $this->utilityHistory($request, $this->gasRepo);
    }

    public function waterHistory(Request $request): JsonResponse
    {
        return $this->utilityHistory($request, $this->waterRepo);
    }

    public function electricityHistory(Request $request): JsonResponse
    {
        $total = $this->electricityRepo->countHistory();
        $page  = Pagination::fromRequest($request)->clampTo($total);
        $data  = $this->electricityRepo->getHistoryPage($page->perPage(), $page->offset());

        // `previous` : relevé immédiatement plus ancien que la page, hors liste.
        // Les deltas électricité sont calculés par registre côté client, qui en a
        // besoin pour la première ligne d'une page autre que la première.
        return JsonResponse::ok($page->envelope($data['items'], $total, ['previous' => $data['previous']]));
    }

    private function utilityHistory(Request $request, MeterReadingRepositoryInterface $repo): JsonResponse
    {
        $total = $repo->countReadings();
        $page  = Pagination::fromRequest($request)->clampTo($total);

        return JsonResponse::ok($page->envelope($repo->getReadingsPage($page->perPage(), $page->offset()), $total));
    }

    // ── Séries mensuelles (graphiques du dashboard, #238) ────────────────────
    // Les graphes agrègent par mois calendaire : les relevés bruts (gaz_history,
    // water_history, chart_data) restent exposés pour la page « Relevés » et la
    // vue élec 30 jours.

    public function gasMonthlySeries(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->volumeSeries->build($this->gasRepo, $request->queryInt('months', 12)));
    }

    public function waterMonthlySeries(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->volumeSeries->build($this->waterRepo, $request->queryInt('months', 12)));
    }

    public function electricityMonthlySeries(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->electricityRepo->getMonthlyDeltaSeries($request->queryInt('months', 12)));
    }
}
