<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\MeterResolver;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;

/**
 * Suppression de relevés depuis la page « saisie/historique » : un relevé précis
 * (par id pour gaz/eau, par horodatage pour l'électricité — les 5 registres
 * partagent un reading_at) ou l'intégralité d'un fluide (pour repartir propre
 * après un import fautif).
 *
 * Câblé avec les repositories concrets : les méthodes de suppression ne font pas
 * partie des interfaces d'ingestion (seams de test d'écriture), qu'on garde
 * minimales. Réservé aux routes de session (jamais aux jetons Bearer d'agent).
 */
final class ReadingDeletionController
{
    public function __construct(
        private readonly UtilityReadingRepository $gasRepo,
        private readonly UtilityReadingRepository $waterRepo,
        private readonly ElectricityReadingRepository $electricityRepo,
        // Compteur visé par la suppression (#55). null = compteur par défaut.
        private readonly ?MeterResolver $meters = null,
    ) {
    }

    /** Compteur visé, résolu à la demande ; null si aucun résolveur câblé. */
    private function meterFor(Request $request, string $energyType): ?int
    {
        return $this->meters?->resolve($request->param('meter_id'), $energyType);
    }

    public function gasReading(Request $request): JsonResponse
    {
        return $this->deleteUtilityReading($request, $this->gasRepo->forMeter($this->meterFor($request, 'gas')));
    }

    public function waterReading(Request $request): JsonResponse
    {
        return $this->deleteUtilityReading($request, $this->waterRepo->forMeter($this->meterFor($request, 'water')));
    }

    public function electricityReading(Request $request): JsonResponse
    {
        $ts   = Request::parseDate($request->input('reading_at'), 'reading_at');
        $repo = $this->electricityRepo->forMeter($this->meterFor($request, 'electricity'));

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteReadingAt($ts)]);
    }

    public function gasAll(Request $request): JsonResponse
    {
        $repo = $this->gasRepo->forMeter($this->meterFor($request, 'gas'));

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteAll()]);
    }

    public function waterAll(Request $request): JsonResponse
    {
        $repo = $this->waterRepo->forMeter($this->meterFor($request, 'water'));

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteAll()]);
    }

    /**
     * Supprime UN compteur électrique et tout son historique — celui qui est visé,
     * à défaut le compteur par défaut. Jamais le parc entier (#55).
     */
    public function electricityMeter(Request $request): JsonResponse
    {
        $repo = $this->electricityRepo->forMeter($this->meterFor($request, 'electricity'));

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteMeter()]);
    }

    private function deleteUtilityReading(Request $request, UtilityReadingRepository $repo): JsonResponse
    {
        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new ValidationException('Invalid reading id');
        }

        $deleted = $repo->deleteReading($id);

        return JsonResponse::ok(['ok' => true, 'deleted' => $deleted ? 1 : 0]);
    }
}
