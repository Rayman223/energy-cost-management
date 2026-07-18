<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
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
    ) {
    }

    public function gasReading(Request $request): JsonResponse
    {
        return $this->deleteUtilityReading($request, $this->gasRepo);
    }

    public function waterReading(Request $request): JsonResponse
    {
        return $this->deleteUtilityReading($request, $this->waterRepo);
    }

    public function electricityReading(Request $request): JsonResponse
    {
        $ts = Request::parseDate($request->input('reading_at'), 'reading_at');
        $deleted = $this->electricityRepo->deleteReadingAt($ts);

        return JsonResponse::ok(['ok' => true, 'deleted' => $deleted]);
    }

    public function gasAll(Request $request): JsonResponse
    {
        return JsonResponse::ok(['ok' => true, 'deleted' => $this->gasRepo->deleteAll()]);
    }

    public function waterAll(Request $request): JsonResponse
    {
        return JsonResponse::ok(['ok' => true, 'deleted' => $this->waterRepo->deleteAll()]);
    }

    public function electricityMeter(Request $request): JsonResponse
    {
        return JsonResponse::ok(['ok' => true, 'deleted' => $this->electricityRepo->deleteMeter()]);
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
