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

use App\Domain\ReadingGranularity;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\UserContext;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportReport;
use App\Service\Import\ImportRunner;
use App\Service\Import\RowSource;
use App\Support\CliArguments;
use App\Support\DynamicPricing;

require_once __DIR__ . '/../../vendor/autoload.php';

$cliArgs = $argv ?? [];

/** Récupère la valeur d'un argument --clé=valeur (null si absent). */
$arg = static function (string $name) use ($cliArgs): ?string {
    return CliArguments::value($cliArgs, $name);
};

$dryRun = !in_array('--execute', $cliArgs, true);
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

// Plafonnement des index élec par créneau, comme la voie web (issue #165) : un par
// jour en tarif fixe, un par tranche de 15 min en tarif dynamique. Sans effet sur
// gaz/eau. Créneau délimité dans le fuseau du profil de l'utilisateur cible.
$throttle = DynamicPricing::isEnabled($config) ? ReadingGranularity::QuarterHour : ReadingGranularity::Day;
$timezone = (new UserRepository($pdo))->getProfile($userId)->timezone ?? 'UTC';

fwrite(STDOUT, sprintf(
    "[IMPORT] type=%s user=#%d fichier=%s mode=%s%s",
    $type,
    $userId,
    $file,
    $dryRun ? 'DRY-RUN' : 'EXECUTE',
    PHP_EOL
));

// ── Source de lignes (CSV en flux, JSON sinon) ───────────────────────────────
$isJson = strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'json';
$handle = null;
try {
    if ($isJson) {
        $rows = RowSource::fromJson((string) file_get_contents($file));
    } else {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier.');
        }
        $rows = RowSource::fromCsv($handle);
    }

    // Orchestration transaction/dry-run/plafond partagée avec l'UI web.
    $report = (new ImportRunner())->run($pdo, $mapping, $rows, $userId, $type, $dryRun, false, $throttle, $timezone);
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($handle)) {
        fclose($handle);
    }
}

// ── Bilan ────────────────────────────────────────────────────────────────────
printReport($report, $dryRun);
// Le process échoue (1) sur une défaillance réelle (base/infra) OU un import
// tronqué (données perdues) — pas sur de simples lignes ignorées (validation),
// comme les anciens import_gaz/eau (cf. #75).
exit($report->writeErrors() > 0 || $report->truncated() ? 1 : 0);

function printReport(ImportReport $report, bool $dryRun): void
{
    fwrite(STDOUT, PHP_EOL . '[BILAN]' . PHP_EOL);
    fwrite(STDOUT, sprintf("  Importés : %d%s", $report->imported(), PHP_EOL));
    fwrite(STDOUT, sprintf("  Doublons : %d%s", $report->duplicates(), PHP_EOL));
    // « Ignorées » = lignes rejetées par la validation ; « Échecs » = écritures
    // base/infra en erreur (ce qui fait réellement échouer le process).
    fwrite(STDOUT, sprintf("  Ignorées : %d%s", $report->errors() - $report->writeErrors(), PHP_EOL));
    fwrite(STDOUT, sprintf("  Échecs   : %d%s", $report->writeErrors(), PHP_EOL));

    foreach ($report->errorSamples() as $msg) {
        fwrite(STDOUT, '    - ' . $msg . PHP_EOL);
    }

    if ($report->truncated()) {
        fwrite(STDOUT, PHP_EOL . '[TRONQUÉ] Plafond de lignes atteint : seules les premières lignes ont été traitées.' . PHP_EOL);
    }
    if ($dryRun) {
        fwrite(STDOUT, PHP_EOL . '[DRY-RUN] Aucune écriture. Relancez avec --execute pour importer.' . PHP_EOL);
    }
}
