<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\UtilityIngestionInterface;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportReport;
use DateTimeImmutable;

/**
 * Import en masse de relevés (CSV/JSON) vers le modèle multi-tenant, en flux et
 * idempotent. Contrairement à {@see \App\Http\Controller\IngestController} (API,
 * qui échoue à la première ligne invalide), ce service **collecte** les erreurs
 * ligne à ligne et poursuit, afin de produire un rapport complet
 * (importés / doublons / erreurs) sur de gros fichiers.
 *
 * L'écriture idempotente passe par les mêmes seams que l'API
 * (`insertIndexes` / `saveIgnore`, en `INSERT IGNORE` sur les `UNIQUE`
 * composites), ce qui rend le service testable avec les fakes d'ingestion.
 * L'appelant scope le sink sur l'utilisateur cible et gère la transaction.
 */
final class BulkImportService
{
    /**
     * Importe des relevés électricité (modèle à registres).
     *
     * @param iterable<int, array<string, string>> $rows numéro de ligne => colonnes normalisées
     */
    public function importElectricity(iterable $rows, ImportMapping $mapping, ElectricityIngestionInterface $sink): ImportReport
    {
        $report = new ImportReport();

        foreach ($rows as $lineNo => $row) {
            $ts = self::parseTimestamp($row, $mapping->timestampColumn, $lineNo, $report);
            if ($ts === null) {
                continue;
            }

            $indexes = [];
            $rowError = false;
            foreach ($mapping->registerColumns as $col => $registerKey) {
                if (!array_key_exists($col, $row) || $row[$col] === '') {
                    continue;
                }
                $value = self::parseValue($row[$col]);
                if ($value === null) {
                    $report->addError(sprintf('Ligne %d : valeur invalide pour « %s » (%s)', $lineNo, $col, $row[$col]));
                    $rowError = true;
                    break;
                }
                $indexes[$registerKey] = $value;
            }

            if ($rowError) {
                continue;
            }
            if ($indexes === []) {
                $report->addError(sprintf('Ligne %d : aucun registre renseigné.', $lineNo));
                continue;
            }

            try {
                $inserted = $sink->insertIndexes($ts, $indexes);
            } catch (\Throwable) {
                // Erreur au niveau base (valeur hors bornes, verrou…) : on la
                // compte et on poursuit — une ligne fautive ne doit pas annuler
                // tout l'import. Aucun détail interne exposé.
                $report->addError(sprintf('Ligne %d : erreur d\'écriture en base.', $lineNo));
                continue;
            }
            $report->addImported($inserted);
            $report->addDuplicate(count($indexes) - $inserted);
        }

        return $report;
    }

    /**
     * Importe des relevés gaz/eau (index compteur m³).
     *
     * @param iterable<int, array<string, string>> $rows numéro de ligne => colonnes normalisées
     */
    public function importUtility(iterable $rows, ImportMapping $mapping, UtilityIngestionInterface $sink): ImportReport
    {
        $report = new ImportReport();
        $valueColumn = $mapping->valueColumn ?? 'counter_m3';

        foreach ($rows as $lineNo => $row) {
            $ts = self::parseTimestamp($row, $mapping->timestampColumn, $lineNo, $report);
            if ($ts === null) {
                continue;
            }

            $raw = $row[$valueColumn] ?? '';
            if ($raw === '') {
                $report->addError(sprintf('Ligne %d : colonne valeur « %s » absente ou vide.', $lineNo, $valueColumn));
                continue;
            }
            $value = self::parseValue($raw);
            if ($value === null) {
                $report->addError(sprintf('Ligne %d : valeur m³ invalide (%s).', $lineNo, $raw));
                continue;
            }

            try {
                $isNew = $sink->saveIgnore($ts, $value);
            } catch (\Throwable) {
                $report->addError(sprintf('Ligne %d : erreur d\'écriture en base.', $lineNo));
                continue;
            }
            $isNew ? $report->addImported() : $report->addDuplicate();
        }

        return $report;
    }

    /**
     * Formats d'horodatage acceptés (stricts). On refuse tout ce que
     * `new DateTimeImmutable()` accepterait de trop souple (« now », « +1 day »,
     * « 2026 »…) : ces valeurs seraient datées à l'instant de l'import et
     * casseraient l'idempotence (un réimport tomberait sur un nouvel horodatage).
     */
    private const TIMESTAMP_FORMATS = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'];

    /**
     * @param array<string, string> $row
     */
    private static function parseTimestamp(array $row, string $column, int $lineNo, ImportReport $report): ?DateTimeImmutable
    {
        $raw = $row[$column] ?? '';
        if ($raw === '') {
            $report->addError(sprintf('Ligne %d : horodatage « %s » absent.', $lineNo, $column));

            return null;
        }

        foreach (self::TIMESTAMP_FORMATS as $format) {
            // `!` réinitialise les champs non fournis (Y-m-d → minuit) ; l'aller-
            // retour garantit une correspondance exacte (rejette « now », « 2026 »…).
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($dt !== false && $dt->format($format) === $raw) {
                return $dt;
            }
        }

        $report->addError(sprintf('Ligne %d : horodatage invalide (%s) — format attendu Y-m-d H:i:s.', $lineNo, $raw));

        return null;
    }

    /** Valide un index/valeur : float ≥ 0, ou null si invalide. */
    private static function parseValue(string $raw): ?float
    {
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < 0) {
            return null;
        }

        return $value;
    }
}
