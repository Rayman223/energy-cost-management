<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Service\AnnualConsumptionService;
use App\Service\CostCalculationService;
use App\Support\Dates;

/**
 * Estimations de coûts (électricité mois courant/donné, gaz dernière période/mois)
 * et récapitulatif annuel toutes énergies.
 */
final class CostController
{
    /**
     * @param bool $dynamicEnabled Tarif dynamique activé côté serveur
     *   (`dynamic_prices.enabled`). Faux ⇒ la clé `dynamic` est omise de la
     *   réponse et l'UI n'affiche pas la section dédiée.
     */
    public function __construct(
        private readonly CostCalculationService $costSvc,
        private readonly AnnualConsumptionService $annualSvc,
        private readonly bool $dynamicEnabled = true,
    ) {
    }

    public function monthCost(Request $request): JsonResponse
    {
        [$year, $month] = $this->yearMonth($request);

        $data = $this->costSvc->estimateMonthElectricity($year, $month);
        if ($this->dynamicEnabled) {
            $data['dynamic'] = $this->costSvc->estimateMonthElectricityDynamic($year, $month);
        }

        return JsonResponse::ok($data);
    }

    public function costEstimate(Request $request): JsonResponse
    {
        $data = $this->costSvc->estimateCurrentMonthElectricity();
        if ($this->dynamicEnabled) {
            $data['dynamic'] = $this->costSvc->estimateCurrentMonthElectricityDynamic();
        }

        return JsonResponse::ok($data);
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

    public function waterMonthCost(Request $request): JsonResponse
    {
        [$year, $month] = $this->yearMonth($request);

        return JsonResponse::ok($this->costSvc->estimateMonthWater($year, $month));
    }

    /**
     * Récapitulatif de consommation d'une année civile, toutes énergies (#41).
     *
     * Calcul PARESSEUX, appelé après le rendu du dashboard : une estimation
     * électricité sur 365 jours déclenche le découpage tarifaire et, sous contrat
     * dynamique, la résolution d'une série de prix quart-horaire sur l'année —
     * trop lourd pour être exécuté à chaque chargement de page.
     */
    public function annualConsumption(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->annualSvc->build($this->year($request)));
    }

    /**
     * Année depuis la requête (défaut : année courante), validée.
     *
     * Défaut lu en UTC comme {@see yearMonth()}, pour la même raison (#21) : un
     * appel sans paramètre doit retomber sur l'année que la page vient d'afficher.
     */
    private function year(Request $request): int
    {
        [$currentYear] = Dates::currentYearMonth();

        $year = $request->queryInt('year', $currentYear);

        if ($year < AnnualConsumptionService::MIN_YEAR || $year > AnnualConsumptionService::MAX_YEAR) {
            throw new ValidationException('Invalid year');
        }

        return $year;
    }

    /**
     * Année/mois depuis la requête (défaut : période courante), validés.
     *
     * @return array{0:int, 1:int}
     */
    private function yearMonth(Request $request): array
    {
        // Défaut en UTC, comme le mois amorcé par le dashboard (#21) : lu dans le
        // fuseau PHP, un appel sans paramètre retomberait sur un autre mois que
        // celui que la page vient d'afficher.
        [$currentYear, $currentMonth] = Dates::currentYearMonth();

        $year  = $request->queryInt('year', $currentYear);
        $month = $request->queryInt('month', $currentMonth);

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new ValidationException('Invalid year/month');
        }

        return [$year, $month];
    }
}
