<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Pagination;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\BatteryReadingRepository;
use App\Repository\Contract\BatteryIngestionInterface;
use App\Repository\Contract\BatteryRepositoryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Index d'entrée et de sortie des batteries (#26) : saisie manuelle, ingestion
 * par agent, historique paginé et suppression.
 *
 * Un contrôleur à part plutôt qu'une extension de {@see MeterEntryController} et
 * {@see IngestController} : la batterie ajoute une dimension que ni le gaz, ni
 * l'eau, ni le compteur électrique n'ont — **laquelle** ? Toutes les routes
 * doivent donc résoudre une batterie avant de faire quoi que ce soit, et cette
 * résolution est le cœur commun de cette classe.
 *
 * Le plafond est d'un relevé par batterie et par JOUR civil : la valorisation
 * étant mensuelle (#26), une granularité plus fine n'ajouterait aucune précision
 * au bilan et ferait grossir la table sans contrepartie.
 */
final class BatteryReadingController
{
    /** Taille maximale d'un batch d'ingestion (borne les requêtes). */
    private const MAX_BATCH = 1000;

    /** Compteurs acceptés (source unique sur le contrat d'ingestion). */
    private const KINDS = BatteryIngestionInterface::KINDS;

    /**
     * @param Closure(int): BatteryReadingRepository $readingsFor Fabrique le
     *        repository scopé sur une batterie donnée. Une fabrique plutôt qu'une
     *        instance : la batterie visée n'est connue qu'à la lecture de la
     *        requête, et le scope du repository est immuable par construction.
     * @param string $timezone Fuseau de l'utilisateur, où se délimite le jour civil
     *        du plafond. Repli UTC neutre si le profil n'en porte pas.
     */
    public function __construct(
        private readonly BatteryRepositoryInterface $batteries,
        private readonly Closure $readingsFor,
        private readonly string $timezone = 'UTC',
    ) {
    }

    /**
     * POST battery_entry — saisie manuelle d'un relevé (éventuellement antidaté).
     *
     * Échoue à la première anomalie, contrairement à l'ingestion en batch : une
     * saisie manuelle porte une seule intention, la corriger vaut mieux que
     * l'accepter à moitié.
     */
    public function entry(Request $request): JsonResponse
    {
        $batteryId = $this->resolveBatteryId($request->input('battery_id'));
        $repo      = $this->repositoryFor($batteryId);

        $ts = $this->parseTimestamp($request->input('reading_at'));

        $indexes = [];
        foreach (self::KINDS as $kind) {
            $raw = $request->input($kind);
            if ($raw === null || $raw === '') {
                continue;
            }
            $indexes[$kind] = self::parseIndexValue($raw, $kind);
        }

        if ($indexes === []) {
            throw new ValidationException('At least one of ' . implode(' / ', self::KINDS) . ' is required');
        }

        $bounds = $repo->readingBounds($ts, array_keys($indexes));
        foreach ($indexes as $kind => $value) {
            $bound = $bounds[$kind] ?? ['min' => null, 'max' => null, 'exists' => false];
            if ($bound['exists']) {
                throw new ValidationException(
                    'A ' . $kind . ' index already exists at this date (' . $ts->format('Y-m-d H:i:s') . ')'
                );
            }
            self::assertWithinBounds($kind, $value, $bound['min'], $bound['max']);
        }

        // Plafond journalier. L'instant exact est exclu du contrôle par le
        // repository : compléter le second compteur d'une ligne déjà écrite reste
        // possible, ce n'est pas un « autre » relevé du jour.
        if ($repo->readingPresentInDay($ts, $this->timezone)) {
            throw new ValidationException(
                'Only one battery reading per day is allowed (' . $ts->format('Y-m-d') . ')'
            );
        }

        $written = $repo->insertIndexes($ts, $indexes);

        return JsonResponse::ok([
            'ok'         => true,
            'battery_id' => $batteryId,
            'saved_at'   => $ts->format('c'),
            'received'   => count($indexes),
            'inserted'   => $written,
        ]);
    }

    /**
     * POST ingest_battery — ingestion par agent, unitaire ou batch.
     *
     * Body : `{"battery_id": 1, "readings": [{"timestamp": "...", "charge": 1200.5,
     * "discharge": 1000.2}, ...]}`, ou une lecture unique aux mêmes champs à la
     * racine.
     *
     * Idempotente : re-pousser un relevé connu n'écrit rien. Les relevés qui
     * tombent dans un jour déjà servi sont ignorés EN SILENCE et comptés comme
     * doublons — un agent qui pousse toutes les heures ne doit pas voir son batch
     * échouer, seulement se faire dédupliquer.
     */
    public function ingest(Request $request): JsonResponse
    {
        $batteryId = $this->resolveBatteryId($request->input('battery_id'));
        $repo      = $this->repositoryFor($batteryId);

        $readings = $this->normalizeBatch($request);

        $received = 0;
        $written  = 0;
        foreach ($readings as $i => $reading) {
            $ts = $this->parseTimestamp($reading['timestamp'] ?? null, sprintf('readings[%d].timestamp', $i));

            $indexes = [];
            foreach (self::KINDS as $kind) {
                if (!array_key_exists($kind, $reading) || $reading[$kind] === null || $reading[$kind] === '') {
                    continue;
                }
                $indexes[$kind] = self::parseIndexValue($reading[$kind], sprintf('readings[%d].%s', $i, $kind));
            }

            if ($indexes === []) {
                throw new ValidationException(
                    sprintf('readings[%d] : au moins un compteur requis (%s)', $i, implode(', ', self::KINDS))
                );
            }

            $received += count($indexes);

            if ($repo->readingPresentInDay($ts, $this->timezone)) {
                continue;
            }

            $written += $repo->insertIndexes($ts, $indexes);
        }

        return JsonResponse::ok([
            'ok'         => true,
            'battery_id' => $batteryId,
            'received'   => $received,
            'inserted'   => $written,
        ]);
    }

    /** GET battery_history — historique paginé, deltas inclus. */
    public function history(Request $request): JsonResponse
    {
        $repo  = $this->repositoryFor($this->resolveBatteryId($request->input('battery_id')));
        $total = $repo->countReadings();
        $page  = Pagination::fromRequest($request)->clampTo($total);

        return JsonResponse::ok($page->envelope($repo->getReadingsPage($page->perPage(), $page->offset()), $total));
    }

    /** POST delete_battery_reading — suppression d'une ligne d'historique. */
    public function deleteReading(Request $request): JsonResponse
    {
        $repo = $this->repositoryFor($this->resolveBatteryId($request->input('battery_id')));

        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new ValidationException('Invalid reading id');
        }

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteReading($id) ? 1 : 0]);
    }

    /** POST delete_battery_readings_all — vide l'historique d'une batterie. */
    public function deleteAll(Request $request): JsonResponse
    {
        $repo = $this->repositoryFor($this->resolveBatteryId($request->input('battery_id')));

        return JsonResponse::ok(['ok' => true, 'deleted' => $repo->deleteAll()]);
    }

    /**
     * Batterie visée par la requête.
     *
     * `battery_id` est FACULTATIF quand le compte n'en possède qu'une : c'est le
     * cas de la grande majorité des installations, et l'exiger obligerait chaque
     * agent à connaître un identifiant de base de données. Dès qu'il y en a
     * plusieurs, l'ambiguïté est refusée avec la liste des identifiants — deviner
     * écrirait dans la mauvaise batterie sans que rien ne le signale.
     */
    private function resolveBatteryId(mixed $raw): int
    {
        if ($raw !== null && $raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !$this->batteries->owns($id)) {
                throw new ValidationException('Unknown battery_id');
            }

            return $id;
        }

        $fleet = $this->batteries->listAll();
        if ($fleet === []) {
            throw new ValidationException('No battery declared — create one on /batteries first');
        }
        if (count($fleet) > 1) {
            $ids = implode(', ', array_map(static fn ($b): string => (string) $b->id, $fleet));
            throw new ValidationException('battery_id is required (several batteries: ' . $ids . ')');
        }

        return $fleet[0]->id;
    }

    private function repositoryFor(int $batteryId): BatteryReadingRepository
    {
        return ($this->readingsFor)($batteryId);
    }

    /**
     * Horodatage du relevé ; l'instant courant à défaut, comme pour les autres
     * saisies manuelles.
     */
    private function parseTimestamp(mixed $raw, string $field = 'reading_at'): DateTimeImmutable
    {
        return ($raw === null || $raw === '')
            ? new DateTimeImmutable('now')
            : Request::parseDate($raw, $field);
    }

    /**
     * Ramène le corps de requête à une liste de lectures : soit `readings: [...]`,
     * soit une lecture unique à la racine.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeBatch(Request $request): array
    {
        $batch = $request->input('readings');

        if ($batch === null) {
            $single = [];
            foreach (array_merge(['timestamp'], self::KINDS) as $field) {
                $value = $request->input($field);
                if ($value !== null) {
                    $single[$field] = $value;
                }
            }

            return $single === [] ? [] : [$single];
        }

        if (!is_array($batch)) {
            throw new ValidationException('readings must be an array');
        }
        if (count($batch) > self::MAX_BATCH) {
            throw new ValidationException(sprintf('Batch too large (max %d readings)', self::MAX_BATCH));
        }

        $out = [];
        foreach (array_values($batch) as $i => $reading) {
            if (!is_array($reading)) {
                throw new ValidationException(sprintf('readings[%d] must be an object', $i));
            }
            $out[] = $reading;
        }

        return $out;
    }

    /**
     * Index cumulé : positif ou nul (un compteur neuf affiche 0), séparateur
     * décimal virgule accepté — les claviers FR/NL/DE le produisent et
     * `FILTER_VALIDATE_FLOAT` le refuse.
     */
    private static function parseIndexValue(mixed $raw, string $field): float
    {
        $normalized = is_string($raw) ? str_replace(',', '.', $raw) : $raw;
        $value      = filter_var($normalized, FILTER_VALIDATE_FLOAT);

        if ($value === false || $value < 0) {
            throw new ValidationException('Invalid ' . $field . ' value');
        }

        return (float) $value;
    }

    /**
     * Croissance chronologique : un index cumulé ne décroît pas. Bornes
     * inclusives, pour qu'un compteur à l'arrêt (deux relevés identiques) reste
     * accepté.
     *
     * Risque résiduel identique à celui des autres flux (TOCTOU, #130 B4) : le
     * contrôle et l'écriture ne sont pas sérialisés par un verrou. La saisie
     * mono-utilisateur rend la fenêtre négligeable, et le doublon exact reste
     * neutralisé par l'écriture idempotente.
     */
    private static function assertWithinBounds(string $kind, float $value, ?float $min, ?float $max): void
    {
        if ($min !== null && $value < $min) {
            throw new ValidationException($kind . ' must be ≥ previous reading (' . $min . ' kWh)');
        }
        if ($max !== null && $value > $max) {
            throw new ValidationException($kind . ' must be ≤ next reading (' . $max . ' kWh)');
        }
    }
}
