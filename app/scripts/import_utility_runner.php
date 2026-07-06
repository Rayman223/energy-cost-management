<?php

declare(strict_types=1);

/**
 * Runner partagé des imports CSV gaz/eau historiques (import_gaz.php /
 * import_eau.php). Factorise le bootstrap + l'appel au service d'import commun.
 * Non destiné à être exécuté directement.
 */

use App\Infrastructure\Database;
use App\Security\UserContext;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportRunner;
use App\Service\Import\RowSource;

if (!function_exists('runUtilityImport')) {
    /**
     * @param 'gas'|'water' $type
     * @return int Code de sortie (0 = OK, 1 = erreur).
     */
    function runUtilityImport(string $type, string $valueColumn, string $csvPath, bool $dryRun): int
    {
        if (!is_file($csvPath)) {
            fwrite(STDERR, '[FATAL] Fichier introuvable : ' . $csvPath . PHP_EOL);

            return 1;
        }

        $config = require __DIR__ . '/../bootstrap.php';

        try {
            $pdo    = (new Database($config['database']))->pdo();
            $userId = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
        } catch (\Throwable $e) {
            fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            fwrite(STDERR, "[FATAL] Impossible d'ouvrir le fichier CSV." . PHP_EOL);

            return 1;
        }

        $mapping = ImportMapping::preset($type, ['value_col' => $valueColumn]);

        fwrite(STDOUT, sprintf(
            "[IMPORT] type=%s user=#%d fichier=%s mode=%s%s",
            $type,
            $userId,
            $csvPath,
            $dryRun ? 'DRY-RUN' : 'EXECUTE',
            PHP_EOL
        ));

        try {
            // Orchestration transaction/dry-run/plafond partagée avec l'UI web.
            $report = (new ImportRunner())->run(
                $pdo,
                $mapping,
                RowSource::fromCsv($handle),
                $userId,
                $type,
                $dryRun,
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);

            return 1;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

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

        // Échec (1) sur défaillance réelle (base/infra) OU import tronqué (données
        // perdues) ; les lignes ignorées (validation) restent en 0 (cf. #75).
        return $report->writeErrors() > 0 || $report->truncated() ? 1 : 0;
    }
}
