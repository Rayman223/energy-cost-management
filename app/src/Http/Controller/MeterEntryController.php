<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\GasRepository;
use App\Repository\WaterRepository;
use DateTimeImmutable;

/**
 * Encodage manuel des index gaz / eau. Les deux flux partagent la même logique
 * de validation (valeur > 0, horodatage et index croissants).
 */
final class MeterEntryController
{
    public function __construct(
        private readonly GasRepository $gasRepo,
        private readonly WaterRepository $waterRepo,
    ) {
    }

    public function gas(Request $request): JsonResponse
    {
        return $this->saveReading($request, $this->gasRepo);
    }

    public function water(Request $request): JsonResponse
    {
        return $this->saveReading($request, $this->waterRepo);
    }

    private function saveReading(Request $request, GasRepository|WaterRepository $repo): JsonResponse
    {
        $counterM3 = filter_var($request->input('counter_m3'), FILTER_VALIDATE_FLOAT);
        if ($counterM3 === false || $counterM3 <= 0) {
            throw new ValidationException('Invalid counter_m3 value');
        }

        $readingAt = $request->input('reading_at');
        $ts = $readingAt
            ? Request::parseDate($readingAt, 'reading_at')
            : new DateTimeImmutable('now');

        $latest = $repo->getLatest();
        if ($latest && $ts <= new DateTimeImmutable($latest['reading_at'])) {
            throw new ValidationException('Reading date must be after the latest entry (' . $latest['reading_at'] . ')');
        }
        if ($latest && $counterM3 < (float) $latest['counter_m3']) {
            throw new ValidationException('Counter value must be ≥ previous reading (' . $latest['counter_m3'] . ' m³)');
        }

        $repo->save($ts, $counterM3);

        return JsonResponse::ok(['ok' => true, 'saved_at' => $ts->format('c'), 'counter_m3' => $counterM3]);
    }
}
