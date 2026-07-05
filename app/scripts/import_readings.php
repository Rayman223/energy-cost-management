<?php

declare(strict_types=1);

/**
 * Import en masse de relevés (CSV/JSON) — CLI unifiée multi-tenant.
 *
 * Usage :
 *   php import_readings.php --type=gas|water|electricity --file=<chemin> [options]
 *
 * Options :
 *   --user=<id>          Utilisateur cible (défaut : 1er compte). Cf. UserContext.
 *   --ts-col=<nom>       Colonne d'horodatage (défaut : timestamp).
 *   --value-col=<nom>    Colonne de valeur m³ (gaz/eau ; défaut : counter_m3).
 *   --map=col:reg,...    Mapping colonne→registre (électricité ; défaut : les
 *                        colonnes import_t1/t2, export_t1/t2, production).
 *   --execute            Import réel (par défaut : dry-run, aucune écriture).
 *
 * Idempotent (INSERT IGNORE) : un réimport ne crée aucun doublon. Le dry-run
 * exécute l'import dans une transaction annulée → compteurs fidèles sans écrire.
 *
 * Exemples :
 *   php import_readings.php --type=gas --file=docs/gaz.csv --value-col="Gaz naturel"
 *   php import_readings.php --type=electricity --file=elec.csv --user=2 --execute
 */

use App\Infrastructure\Database;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\UserContext;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportReport;
use App\Service\Import\RowSource;

require_once __DIR__ . '/../autoload.php';

/** Récupère la valeur d'un argument --clé=valeur (null si absent). */
$arg = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with((string) $a, "--{$name}=")) {
            return substr((string) $a, strlen($name) + 3);
        }
    }

    return null;
};

$dryRun = !in_array('--execute', $argv, true);
$type   = strtolower(trim((string) ($arg('type') ?? '')));
$file   = $arg('file');

if (!in_array($type, ImportMapping::ENERGY_TYPES, true)) {
    fwrite(STDERR, '[FATAL] --type requis (electricity|gas|water).' . PHP_EOL);
    exit(1);
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, '[FATAL] --file=<chemin> introuvable : ' . ($file ?? '(manquant)') . PHP_EOL);
    exit(1);
}

// ── Surcharges de mapping ────────────────────────────────────────────────────
$overrides = [];
if (($ts = $arg('ts-col')) !== null && trim($ts) !== '') {
    $overrides['ts_col'] = $ts;
}
if (($vc = $arg('value-col')) !== null && trim($vc) !== '') {
    $overrides['value_col'] = $vc;
}
if (($map = $arg('map')) !== null && trim($map) !== '') {
    $registers = [];
    foreach (explode(',', $map) as $pair) {
        $parts = explode(':', $pair, 2);
        if (count($parts) === 2) {
            $registers[trim($parts[0])] = trim($parts[1]);
        }
    }
    if ($registers !== []) {
        $overrides['registers'] = $registers;
    }
}

try {
    $mapping = ImportMapping::preset($type, $overrides);
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ── Bootstrap DB + tenant ────────────────────────────────────────────────────
$config = require __DIR__ . '/../bootstrap.php';
try {
    $pdo    = (new Database($config['database']))->pdo();
    $userId = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[IMPORT] type=%s user=#%d fichier=%s mode=%s%s",
    $type,
    $userId,
    $file,
    $dryRun ? 'DRY-RUN' : 'EXECUTE',
    PHP_EOL
));

// ── Source de lignes (CSV en flux, JSON sinon) ───────────────────────────────
$service = new BulkImportService();
$isJson  = strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'json';

$pdo->beginTransaction();
try {
    if ($isJson) {
        $raw  = (string) file_get_contents($file);
        $rows = RowSource::fromJson($raw);
    } else {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier.');
        }
        $rows = RowSource::fromCsv($handle);
    }

    if ($mapping->isElectricity()) {
        $report = $service->importElectricity($rows, $mapping, new ElectricityReadingRepository($pdo, $userId));
    } else {
        $report = $service->importUtility($rows, $mapping, new UtilityReadingRepository($pdo, $userId, $type));
    }

    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }

    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ── Bilan ────────────────────────────────────────────────────────────────────
printReport($report, $dryRun);
exit($report->errors() > 0 ? 1 : 0);

function printReport(ImportReport $report, bool $dryRun): void
{
    fwrite(STDOUT, PHP_EOL . '[BILAN]' . PHP_EOL);
    fwrite(STDOUT, sprintf("  Importés : %d%s", $report->imported(), PHP_EOL));
    fwrite(STDOUT, sprintf("  Doublons : %d%s", $report->duplicates(), PHP_EOL));
    fwrite(STDOUT, sprintf("  Erreurs  : %d%s", $report->errors(), PHP_EOL));

    foreach ($report->errorSamples() as $msg) {
        fwrite(STDOUT, '    - ' . $msg . PHP_EOL);
    }

    if ($dryRun) {
        fwrite(STDOUT, PHP_EOL . '[DRY-RUN] Aucune écriture. Relancez avec --execute pour importer.' . PHP_EOL);
    }
}
