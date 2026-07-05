<?php

declare(strict_types=1);

/**
 * Runner partagé des imports CSV gaz/eau historiques (import_gaz.php /
 * import_eau.php). Factorise le bootstrap + l'appel au service d'import commun.
 * Non destiné à être exécuté directement.
 */

use App\Infrastructure\Database;
use App\Repository\UtilityReadingRepository;
use App\Security\UserContext;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
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
        $service = new BulkImportService();

        fwrite(STDOUT, sprintf(
            "[IMPORT] type=%s user=#%d fichier=%s mode=%s%s",
            $type,
            $userId,
            $csvPath,
            $dryRun ? 'DRY-RUN' : 'EXECUTE',
            PHP_EOL
        ));

        $pdo->beginTransaction();
        try {
            $report = $service->importUtility(
                RowSource::fromCsv($handle),
                $mapping,
                new UtilityReadingRepository($pdo, $userId, $type),
            );
            fclose($handle);
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

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

        return $report->errors() > 0 ? 1 : 0;
    }
}
