<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\BulkImportService;
use PDO;
use RuntimeException;

/**
 * Orchestrateur d'import partagé : ouvre la transaction, applique le plafond de
 * lignes (stop-and-report), dispatche vers {@see BulkImportService} puis
 * commit/rollback (dry-run). Point d'entrée unique pour les trois voies —
 * self-service (page compte), admin (page admin) et CLI — afin d'éviter la
 * duplication de l'orchestration transaction/dry-run.
 *
 * - {@see self::runFromRequest()} / {@see self::runUploaded()} : fichier téléversé
 *   (UI web) — valident le téléversement (taille, format, `is_uploaded_file`).
 * - {@see self::run()} : cœur transactionnel, réutilisable hors upload (CLI).
 */
final class ImportRunner
{
    public function __construct(
        private readonly BulkImportService $service = new BulkImportService(),
        private readonly int $maxBytes = 8_388_608,   // 8 Mo
        private readonly int $maxRows = 200_000,
    ) {
    }

    /**
     * Extrait les champs d'import d'une requête POST et délègue au téléversement.
     *
     * @param array<string, mixed> $post  Champs $_POST (energy_type, ts_col, value_col, dry_run).
     * @param array<string, mixed> $files Entrée $_FILES (clé `import_file`).
     * @throws RuntimeException si le téléversement ou le format est invalide.
     */
    public function runFromRequest(PDO $pdo, int $targetUserId, array $post, array $files): ImportReport
    {
        $energyType = strtolower(trim((string) ($post['energy_type'] ?? '')));

        $overrides = [];
        $tsCol = trim((string) ($post['ts_col'] ?? ''));
        if ($tsCol !== '') {
            $overrides['ts_col'] = $tsCol;
        }
        $valueCol = trim((string) ($post['value_col'] ?? ''));
        if ($valueCol !== '') {
            $overrides['value_col'] = $valueCol;
        }
        $dryRun = ($post['dry_run'] ?? '') === '1';

        $file = is_array($files['import_file'] ?? null) ? $files['import_file'] : [];

        return $this->runUploaded($pdo, $targetUserId, $energyType, $overrides, $file, $dryRun);
    }

    /**
     * @param array<string, mixed> $file      Entrée $_FILES (name, tmp_name, error, size…).
     * @param array{ts_col?: string, value_col?: string, registers?: array<string, string>} $overrides
     * @throws RuntimeException si le téléversement ou le format est invalide.
     */
    public function runUploaded(
        PDO $pdo,
        int $targetUserId,
        string $energyType,
        array $overrides,
        array $file,
        bool $dryRun,
    ): ImportReport {
        $tmp  = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $err  = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;

        if ($err === UPLOAD_ERR_NO_FILE || $tmp === '') {
            throw new RuntimeException('Aucun fichier fourni.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Échec du téléversement (code ' . $err . ').');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new RuntimeException('Fichier non valide (téléversement attendu).');
        }
        if ($size > $this->maxBytes) {
            throw new RuntimeException(sprintf('Fichier trop volumineux (max %d Mo).', intdiv($this->maxBytes, 1_048_576)));
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'], true)) {
            throw new RuntimeException('Format non supporté : CSV ou JSON attendu.');
        }

        $mapping = ImportMapping::preset($energyType, $overrides);

        // Voie web : assainir la présentation de l'erreur (pas de fuite de
        // schéma/SQL vers l'utilisateur). La CLI, elle, appelle run() en direct et
        // affiche la cause réelle à l'opérateur.
        try {
            return $this->run($pdo, $mapping, $this->openRows($tmp, $ext === 'json'), $targetUserId, $energyType, $dryRun);
        } catch (\InvalidArgumentException $e) {
            // Erreurs « métier » (format/fichier) : message sûr à afficher.
            throw new RuntimeException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // Toute autre erreur (base, driver…) : détail journalisé, message
            // générique côté utilisateur.
            error_log('[import] ' . $e->getMessage());
            throw new RuntimeException('L\'import a échoué (erreur interne). Vérifiez le fichier ou réessayez.', 0, $e);
        }
    }

    /**
     * Cœur transactionnel partagé (UI + CLI) : plafonne les lignes, importe dans
     * une transaction, puis commit (ou rollback en dry-run). Au-delà du plafond,
     * le rapport est marqué **tronqué** et les N premières lignes sont conservées
     * (stop-and-report) plutôt que tout annuler.
     *
     * Assure le rollback puis **propage l'exception d'origine** : la présentation
     * est du ressort de l'appelant — la voie web ({@see self::runUploaded()})
     * assainit le message, la CLI affiche la cause réelle.
     *
     * @param iterable<int, array<string, string>> $rows
     * @throws \Throwable l'exception d'origine, après rollback.
     */
    public function run(
        PDO $pdo,
        ImportMapping $mapping,
        iterable $rows,
        int $targetUserId,
        string $energyType,
        bool $dryRun,
    ): ImportReport {
        $report = new ImportReport();

        $pdo->beginTransaction();
        try {
            $capped = $this->capped($rows, $report);

            if ($mapping->isElectricity()) {
                $this->service->importElectricity($capped, $mapping, new ElectricityReadingRepository($pdo, $targetUserId), $report);
            } else {
                $this->service->importUtility($capped, $mapping, new UtilityReadingRepository($pdo, $targetUserId, $energyType), $report);
            }

            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $report;
    }

    /**
     * @return iterable<int, array<string, string>>
     */
    private function openRows(string $path, bool $isJson): iterable
    {
        if ($isJson) {
            // JSON : matérialisé en mémoire (non streamé) — borné par $maxBytes en
            // amont, puis par le plafond de lignes ci-dessous. Cf. docs/import.md.
            return RowSource::fromJson((string) file_get_contents($path));
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire le fichier.');
        }

        return RowSource::fromCsv($handle);
    }

    /**
     * Borne le nombre de lignes traitées (garde-fou anti-abus / mémoire). Au-delà
     * du plafond, marque le rapport tronqué et s'arrête : les lignes déjà lues
     * sont importées (stop-and-report), au lieu d'annuler tout l'import.
     *
     * @param iterable<int, array<string, string>> $rows
     * @return iterable<int, array<string, string>>
     */
    private function capped(iterable $rows, ImportReport $report): iterable
    {
        $n = 0;
        foreach ($rows as $key => $row) {
            if (++$n > $this->maxRows) {
                $report->markTruncated();

                return;
            }
            yield $key => $row;
        }
    }
}
