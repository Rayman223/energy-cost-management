<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Service\CostCalculationService;

/**
 * Estimations de coûts (électricité mois courant/donné, gaz dernière période/mois).
 */
final class CostController
{
    public function __construct(private readonly CostCalculationService $costSvc)
    {
    }

    public function monthCost(Request $request): JsonResponse
    {
        [$year, $month] = $this->yearMonth($request);

        return JsonResponse::ok($this->costSvc->estimateMonthElectricity($year, $month));
    }

    public function costEstimate(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->costSvc->estimateCurrentMonthElectricity());
    }

    public function gasCost(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->costSvc->estimateLastGasPeriod());
    }

    public function gasMonthCost(Request $request): JsonResponse
    {
        [$year, $month] = $this->yearMonth($request);

        return JsonResponse::ok($this->costSvc->estimateMonthGas($year, $month));
    }

    /**
     * Année/mois depuis la requête (défaut : période courante), validés.
     *
     * @return array{0:int, 1:int}
     */
    private function yearMonth(Request $request): array
    {
        $year  = $request->queryInt('year', (int) date('Y'));
        $month = $request->queryInt('month', (int) date('n'));

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new ValidationException('Invalid year/month');
        }

        return [$year, $month];
    }
}
