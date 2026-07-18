<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\UtilityIngestionInterface;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportReport;
use App\Service\Import\ReadingParser;
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
     * @param ImportReport|null $report Rapport à remplir (créé si null) — permet à
     *        l'orchestrateur de pré-créer le rapport (cap/troncature partagés).
     * @param bool $replace Ré-import « écraser » : les valeurs déjà présentes au
     *        même horodatage sont mises à jour au lieu d'être ignorées.
     */
    public function importElectricity(iterable $rows, ImportMapping $mapping, ElectricityIngestionInterface $sink, ?ImportReport $report = null, bool $replace = false): ImportReport
    {
        $report ??= new ImportReport();

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
                $value = ReadingParser::parseValue($row[$col]);
                if ($value === null) {
                    $report->addError(sprintf('Ligne %d : valeur invalide pour « %s » (%s)', $lineNo, $col, $row[$col]));
                    $rowError = true;
                    break;
                }
                // Ramène la valeur lue à l'unité canonique de stockage (kWh) : le
                // facteur vaut 1 en kWh, 0.001 si le fichier est en Wh.
                $indexes[$registerKey] = $value * $mapping->unitToCanonicalFactor;
            }

            if ($rowError) {
                continue;
            }
            if ($indexes === []) {
                $report->addError(sprintf('Ligne %d : aucun registre renseigné.', $lineNo));
                continue;
            }

            try {
                $inserted = $sink->insertIndexes($ts, $indexes, $replace);
            } catch (\Throwable) {
                // Erreur au niveau base (valeur hors bornes, verrou…) : on la
                // compte comme échec réel et on poursuit — une ligne fautive ne
                // doit pas annuler tout l'import. Aucun détail interne exposé.
                $report->addWriteError(sprintf('Ligne %d : erreur d\'écriture en base.', $lineNo));
                continue;
            }
            $report->addImported($inserted);
            $report->addDuplicate(count($indexes) - $inserted);
            if ($inserted > 0) {
                $report->noteImportedAt($ts);
            }
        }

        return $report;
    }

    /**
     * Importe des relevés gaz/eau (index compteur m³).
     *
     * @param iterable<int, array<string, string>> $rows numéro de ligne => colonnes normalisées
     * @param ImportReport|null $report Rapport à remplir (créé si null) — permet à
     *        l'orchestrateur de pré-créer le rapport (cap/troncature partagés).
     * @param bool $replace Ré-import « écraser » : les valeurs déjà présentes au
     *        même horodatage sont mises à jour au lieu d'être ignorées.
     */
    public function importUtility(iterable $rows, ImportMapping $mapping, UtilityIngestionInterface $sink, ?ImportReport $report = null, bool $replace = false): ImportReport
    {
        $report ??= new ImportReport();
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
            $value = ReadingParser::parseValue($raw);
            if ($value === null) {
                $report->addError(sprintf('Ligne %d : valeur invalide (%s).', $lineNo, $raw));
                continue;
            }
            // Ramène la valeur lue à l'unité canonique de stockage (m³) : le
            // facteur vaut 1 en m³, 0.001 si le fichier est en litres.
            $value *= $mapping->unitToCanonicalFactor;

            try {
                $isNew = $sink->saveIgnore($ts, $value, $replace);
            } catch (\Throwable) {
                $report->addWriteError(sprintf('Ligne %d : erreur d\'écriture en base.', $lineNo));
                continue;
            }
            if ($isNew) {
                $report->addImported();
                $report->noteImportedAt($ts);
            } else {
                $report->addDuplicate();
            }
        }

        return $report;
    }

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

        $dt = ReadingParser::parseTimestampStrict($raw);
        if ($dt === null) {
            $report->addError(sprintf('Ligne %d : horodatage invalide (%s) — format attendu Y-m-d H:i:s.', $lineNo, $raw));
        }

        return $dt;
    }
}
