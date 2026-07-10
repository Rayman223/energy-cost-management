<?php

declare(strict_types=1);

/**
 * Import CSV gaz → utility_readings (energy_type=gas). Multi-tenant.
 *
 * Fine enveloppe autour de {@see \App\Service\BulkImportService} : conserve le
 * contrat historique (chemin CSV fixe, dry-run par défaut, --execute, --user)
 * tout en réutilisant la logique d'import commune (streaming, idempotence,
 * rapport). Pour un import générique, préférer import_readings.php.
 *
 * Usage :
 *   php import_gaz.php                         <- dry-run (aucune écriture)
 *   php import_gaz.php --execute [--user=<id>] <- import réel (défaut : 1er compte)
 *
 * Fichier attendu : docs/meter_readings_export_gaz.csv
 * Colonnes : « Timestamp » (Y-m-d H:i:s) et « Gaz naturel » (m³ ≥ 0).
 */

require_once __DIR__ . '/../autoload.php';
require __DIR__ . '/import_utility_runner.php';

$dryRun  = !in_array('--execute', $argv ?? [], true);
$csvPath = __DIR__ . '/../docs/meter_readings_export_gaz.csv';

exit(runUtilityImport('gas', 'gaz naturel', $csvPath, $dryRun));
