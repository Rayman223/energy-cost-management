<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\UtilityIngestionInterface;
use DateTimeImmutable;

/**
 * API d'ingestion : réception des index poussés par les agents des membres
 * (unitaire ou batch). Idempotente : renvoyer un relevé déjà connu ne crée
 * pas de doublon (contraintes UNIQUE + INSERT IGNORE).
 */
final class IngestController
{
    /** Taille maximale d'un batch (borne les requêtes). */
    private const MAX_BATCH = 1000;

    /** Registres électricité acceptés. */
    private const REGISTERS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

    public function __construct(
        private readonly ElectricityIngestionInterface $electricity,
        private readonly UtilityIngestionInterface $gas,
        private readonly UtilityIngestionInterface $water,
    ) {
    }

    /**
     * POST ingest_electricity
     * Body : {"readings": [{"timestamp": "...", "import_t1": 123.4, ...}, ...]}
     * ou une lecture unique aux mêmes champs à la racine.
     */
    public function electricity(Request $request): JsonResponse
    {
        $readings = $this->normalizeBatch($request, 'timestamp');

        $received = 0;
        $inserted = 0;
        foreach ($readings as $i => $reading) {
            $ts = self::parseTimestamp($reading, 'timestamp', $i);

            $indexes = [];
            foreach (self::REGISTERS as $key) {
                if (!array_key_exists($key, $reading) || $reading[$key] === null || $reading[$key] === '') {
                    continue;
                }
                $indexes[$key] = self::parseIndexValue($reading[$key], $key, $i);
            }

            if ($indexes === []) {
                throw new ValidationException(
                    sprintf('readings[%d] : au moins un registre requis (%s)', $i, implode(', ', self::REGISTERS))
                );
            }

            $received += count($indexes);
            $inserted += $this->electricity->insertIndexes($ts, $indexes);
        }

        return JsonResponse::ok(['ok' => true, 'received' => $received, 'inserted' => $inserted]);
    }

    /** POST ingest_gas — {"readings": [{"reading_at": "...", "counter_m3": 123.456}, ...]} ou unitaire. */
    public function gas(Request $request): JsonResponse
    {
        return $this->ingestUtility($request, $this->gas);
    }

    /** POST ingest_water — même contrat que ingest_gas. */
    public function water(Request $request): JsonResponse
    {
        return $this->ingestUtility($request, $this->water);
    }

    private function ingestUtility(Request $request, UtilityIngestionInterface $repo): JsonResponse
    {
        $readings = $this->normalizeBatch($request, 'reading_at');

        $received = 0;
        $inserted = 0;
        foreach ($readings as $i => $reading) {
            $ts = self::parseTimestamp($reading, 'reading_at', $i);

            $value = filter_var($reading['counter_m3'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($value === false || $value < 0) {
                throw new ValidationException(sprintf('readings[%d].counter_m3 invalide (m³ >= 0 attendu)', $i));
            }

            $received++;
            if ($repo->saveIgnore($ts, $value)) {
                $inserted++;
            }
        }

        return JsonResponse::ok(['ok' => true, 'received' => $received, 'inserted' => $inserted]);
    }

    /**
     * Normalise le corps en liste de lectures : soit {"readings": [...]}, soit
     * une lecture unique à la racine (identifiée par son champ d'horodatage).
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeBatch(Request $request, string $timestampField): array
    {
        $batch = $request->input('readings');

        if ($batch === null) {
            if ($request->input($timestampField) === null) {
                throw new ValidationException(sprintf('Champ "readings" (batch) ou "%s" (unitaire) requis', $timestampField));
            }

            $single = [];
            foreach (array_merge(self::REGISTERS, [$timestampField, 'counter_m3']) as $field) {
                $value = $request->input($field);
                if ($value !== null) {
                    $single[$field] = $value;
                }
            }

            return [$single];
        }

        if (!is_array($batch) || $batch === []) {
            throw new ValidationException('readings doit être une liste non vide');
        }
        if (count($batch) > self::MAX_BATCH) {
            throw new ValidationException('readings : maximum ' . self::MAX_BATCH . ' lectures par requête');
        }

        $out = [];
        foreach (array_values($batch) as $i => $reading) {
            if (!is_array($reading)) {
                throw new ValidationException(sprintf('readings[%d] doit être un objet', $i));
            }
            $out[] = $reading;
        }

        return $out;
    }

    /** @param array<string, mixed> $reading */
    private static function parseTimestamp(array $reading, string $field, int $index): DateTimeImmutable
    {
        $raw = $reading[$field] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new ValidationException(sprintf('readings[%d].%s requis', $index, $field));
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new ValidationException(sprintf('readings[%d].%s invalide : %s', $index, $field, $raw));
        }
    }

    private static function parseIndexValue(mixed $raw, string $key, int $index): float
    {
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < 0) {
            throw new ValidationException(sprintf('readings[%d].%s invalide (index kWh >= 0 attendu)', $index, $key));
        }

        return $value;
    }
}
