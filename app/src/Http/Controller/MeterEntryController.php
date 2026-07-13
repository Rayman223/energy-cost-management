<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use DateTimeImmutable;
use PDOException;

/**
 * Encodage manuel des index gaz / eau / électricité. Un relevé peut être
 * antidaté : la valeur doit alors rester bornée entre le relevé précédent et le
 * relevé suivant à la date saisie (croissance chronologique, bornes inclusives).
 */
final class MeterEntryController
{
    public function __construct(
        private readonly MeterReadingRepositoryInterface $gasRepo,
        private readonly MeterReadingRepositoryInterface $waterRepo,
        private readonly ElectricityIngestionInterface $electricityRepo,
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

    public function electricity(Request $request): JsonResponse
    {
        $readingAt = $request->input('reading_at');
        $ts = $readingAt
            ? Request::parseDate($readingAt, 'reading_at')
            : new DateTimeImmutable('now');

        $indexes = [];
        foreach (ElectricityIngestionInterface::REGISTERS as $key) {
            $raw = $request->input($key);
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
            if ($value === false || $value < 0) {
                throw new ValidationException('Invalid ' . $key . ' value');
            }

            $indexes[$key] = (float) $value;
        }

        if ($indexes === []) {
            throw new ValidationException('At least one electricity index is required');
        }

        $bounds = $this->electricityRepo->readingBounds($ts, array_keys($indexes));
        foreach ($indexes as $key => $value) {
            $bound = $bounds[$key] ?? ['min' => null, 'max' => null, 'exists' => false];
            if ($bound['exists']) {
                throw new ValidationException('A reading already exists at this date for ' . $key . ' (' . $ts->format('Y-m-d H:i:s') . ')');
            }
            $this->assertWithinBounds($key, $value, $bound['min'], $bound['max']);
        }

        $inserted = $this->electricityRepo->insertIndexes($ts, $indexes);

        return JsonResponse::ok([
            'ok'       => true,
            'saved_at' => $ts->format('c'),
            'received' => count($indexes),
            'inserted' => $inserted,
        ]);
    }

    private function saveReading(Request $request, MeterReadingRepositoryInterface $repo): JsonResponse
    {
        $counterM3 = filter_var($request->input('counter_m3'), FILTER_VALIDATE_FLOAT);
        if ($counterM3 === false || $counterM3 <= 0) {
            throw new ValidationException('Invalid counter_m3 value');
        }

        $readingAt = $request->input('reading_at');
        $ts = $readingAt
            ? Request::parseDate($readingAt, 'reading_at')
            : new DateTimeImmutable('now');

        $before = $repo->getReadingBefore($ts);
        $after  = $repo->getReadingAfter($ts);
        $tsStr  = $ts->format('Y-m-d H:i:s');

        if (($before && $before['reading_at'] === $tsStr) || ($after && $after['reading_at'] === $tsStr)) {
            throw new ValidationException('A reading already exists at this date (' . $tsStr . ')');
        }
        $this->assertWithinBounds(
            'Counter value',
            $counterM3,
            $before !== null ? (float) $before['counter_m3'] : null,
            $after !== null ? (float) $after['counter_m3'] : null,
            'm³',
        );

        try {
            $repo->save($ts, $counterM3);
        } catch (PDOException $e) {
            // Course entre la vérification de doublon et l'INSERT : la contrainte
            // uq_utility_readings (SQLSTATE 23000) tranche → erreur métier, pas un 500.
            if ($e->getCode() === '23000') {
                throw new ValidationException('A reading already exists at this date (' . $tsStr . ')');
            }
            throw $e;
        }

        return JsonResponse::ok(['ok' => true, 'saved_at' => $ts->format('c'), 'counter_m3' => $counterM3]);
    }

    /**
     * Valide qu'une valeur reste dans les bornes de croissance chronologique
     * (relevé précédent ≤ valeur ≤ relevé suivant, bornes inclusives). Logique
     * partagée par les flux gaz/eau et électricité.
     */
    private function assertWithinBounds(string $label, float $value, ?float $min, ?float $max, string $unit = ''): void
    {
        $suffix = $unit === '' ? '' : ' ' . $unit;
        if ($min !== null && $value < $min) {
            throw new ValidationException($label . ' must be ≥ previous reading (' . $min . $suffix . ')');
        }
        if ($max !== null && $value > $max) {
            throw new ValidationException($label . ' must be ≤ next reading (' . $max . $suffix . ')');
        }
    }
}
