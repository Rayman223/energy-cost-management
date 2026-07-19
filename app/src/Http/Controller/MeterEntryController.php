<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\SyncStateKeys;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\WebhookSyncStateRepository;
use App\Support\Dates;
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
        private readonly ?WebhookSyncStateRepository $syncState = null,
    ) {
    }

    public function gas(Request $request): JsonResponse
    {
        return $this->saveReading($request, $this->gasRepo, [SyncStateKeys::GAS]);
    }

    public function water(Request $request): JsonResponse
    {
        return $this->saveReading($request, $this->waterRepo, [SyncStateKeys::WATER]);
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

            $value = filter_var(self::normalizeDecimal($raw), FILTER_VALIDATE_FLOAT);
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

        // Uniquement si au moins une ligne a réellement été insérée (INSERT IGNORE
        // renvoie 0 sur doublon déjà présent/synchronisé) : cohérent avec gaz/eau,
        // qui ne reculent le watermark qu'après un save() réussi.
        if ($inserted > 0) {
            $this->rewindSyncWatermarks(SyncStateKeys::ELEC, $ts);
        }

        return JsonResponse::ok([
            'ok'       => true,
            'saved_at' => $ts->format('c'),
            'received' => count($indexes),
            'inserted' => $inserted,
        ]);
    }

    /**
     * @param list<string> $sources Clés webhook_sync_state du/des flux concernés.
     */
    private function saveReading(Request $request, MeterReadingRepositoryInterface $repo, array $sources): JsonResponse
    {
        $counterM3 = filter_var(self::normalizeDecimal($request->input('counter_m3')), FILTER_VALIDATE_FLOAT);
        // >= 0 (et non > 0) : un compteur neuf peut légitimement afficher 0 ; la
        // croissance chronologique reste garantie par assertWithinBounds (#130 B8).
        if ($counterM3 === false || $counterM3 < 0) {
            throw new ValidationException('Invalid counter_m3 value');
        }

        $readingAt = $request->input('reading_at');
        $ts = $readingAt
            ? Request::parseDate($readingAt, 'reading_at')
            : new DateTimeImmutable('now');

        $before = $repo->getReadingBefore($ts);
        $after  = $repo->getReadingAfter($ts);
        // Normalisé en UTC pour comparer à reading_at (stocké en UTC), quel que
        // soit l'offset porté par $ts (saisie client possiblement horodatée).
        $tsStr  = Dates::toDbString($ts);

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

        $this->rewindSyncWatermarks($sources, $ts);

        return JsonResponse::ok(['ok' => true, 'saved_at' => $ts->format('c'), 'counter_m3' => $counterM3]);
    }

    /**
     * Après l'insertion d'un relevé (possiblement antidaté), recule le watermark
     * de synchro EnergyID des flux concernés pour que le relevé soit repêché au
     * prochain envoi (#130 B2). Le recul cible `reading_at moins 1 seconde` (le
     * filtre de sync relit en `>` strict) ; `rewindLastSentAt` n'abaisse jamais
     * à la hausse, donc un relevé récent (postérieur au watermark) est un no-op.
     *
     * @param list<string> $sources
     */
    private function rewindSyncWatermarks(array $sources, DateTimeImmutable $readingAt): void
    {
        if ($this->syncState === null) {
            return;
        }

        $target = $readingAt->modify('-1 second');
        foreach ($sources as $source) {
            $this->syncState->rewindLastSentAt($source, $target);
        }
    }

    /**
     * Normalise une saisie décimale : accepte la virgule comme séparateur
     * (usage FR/NL/DE) en la ramenant au point attendu par filter_var (#130 B8).
     */
    private static function normalizeDecimal(mixed $raw): mixed
    {
        return is_string($raw) ? str_replace(',', '.', $raw) : $raw;
    }

    /**
     * Valide qu'une valeur reste dans les bornes de croissance chronologique
     * (relevé précédent ≤ valeur ≤ relevé suivant, bornes inclusives). Logique
     * partagée par les flux gaz/eau et électricité.
     *
     * Risque résiduel (TOCTOU, #130 B4) : le contrôle de bornes et l'INSERT ne
     * sont pas sérialisés par un verrou (pas de SELECT ... FOR UPDATE), donc deux
     * saisies concurrentes à des horodatages adjacents peuvent théoriquement
     * franchir chacune le contrôle avant l'écriture de l'autre. En pratique la
     * saisie manuelle mono-utilisateur rend la fenêtre négligeable ; le doublon
     * exact reste attrapé par la contrainte d'unicité (gaz/eau) ou l'INSERT
     * IGNORE (élec).
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
