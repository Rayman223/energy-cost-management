<?php

declare(strict_types=1);

/**
 * Import CSV eau → utility_readings (energy_type=water). Multi-tenant.
 *
 * Fine enveloppe autour de {@see \App\Service\BulkImportService} : conserve le
 * contrat historique (chemin CSV fixe, dry-run par défaut, --execute, --user)
 * tout en réutilisant la logique d'import commune (streaming, idempotence,
 * rapport). Pour un import générique, préférer import_readings.php.
 *
 * Usage :
 *   php import_eau.php                         <- dry-run (aucune écriture)
 *   php import_eau.php --execute [--user=<id>] <- import réel (défaut : 1er compte)
 *
 * Fichier attendu : docs/meter_readings_export_water.csv
 * Colonnes : « Timestamp » (Y-m-d H:i:s) et « Eau » (m³ ≥ 0 ; 0 = mise en service).
 */

require_once __DIR__ . '/../autoload.php';
require __DIR__ . '/import_utility_runner.php';

$dryRun  = !in_array('--execute', $argv, true);
$csvPath = __DIR__ . '/../docs/meter_readings_export_water.csv';

exit(runUtilityImport('water', 'eau', $csvPath, $dryRun));
