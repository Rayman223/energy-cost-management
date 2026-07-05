<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\BulkImportService;
use PDO;
use RuntimeException;

/**
 * Orchestrateur d'import depuis un **fichier téléversé** (UI web), partagé par
 * le self-service (page compte) et l'import admin (page admin).
 *
 * Valide le téléversement (fichier réellement uploadé, taille, format), construit
 * les repos scopés sur l'utilisateur **cible**, puis délègue à
 * {@see BulkImportService} dans une transaction (annulée en dry-run).
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

        $ext    = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'], true)) {
            throw new RuntimeException('Format non supporté : CSV ou JSON attendu.');
        }

        $mapping = ImportMapping::preset($energyType, $overrides);

        $pdo->beginTransaction();
        try {
            $rows = $this->openRows($tmp, $ext === 'json');

            if ($mapping->isElectricity()) {
                $report = $this->service->importElectricity($rows, $mapping, new ElectricityReadingRepository($pdo, $targetUserId));
            } else {
                $report = $this->service->importUtility($rows, $mapping, new UtilityReadingRepository($pdo, $targetUserId, $energyType));
            }

            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            // Erreurs « métier » (format/fichier/limite) : message sûr à afficher.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // Toute autre erreur (base, driver…) : détail journalisé, message
            // générique côté utilisateur (pas de fuite de schéma/SQL).
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[import] ' . $e->getMessage());
            throw new RuntimeException('L\'import a échoué (erreur interne). Vérifiez le fichier ou réessayez.', 0, $e);
        }

        return $report;
    }

    /**
     * @return iterable<int, array<string, string>>
     */
    private function openRows(string $path, bool $isJson): iterable
    {
        if ($isJson) {
            return $this->capped(RowSource::fromJson((string) file_get_contents($path)));
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire le fichier.');
        }

        return $this->capped(RowSource::fromCsv($handle));
    }

    /**
     * Borne le nombre de lignes traitées (garde-fou anti-abus / mémoire).
     *
     * @param iterable<int, array<string, string>> $rows
     * @return iterable<int, array<string, string>>
     */
    private function capped(iterable $rows): iterable
    {
        $n = 0;
        foreach ($rows as $key => $row) {
            if (++$n > $this->maxRows) {
                // InvalidArgumentException = message « métier » affichable tel quel.
                throw new \InvalidArgumentException(sprintf('Fichier trop volumineux (max %d lignes).', $this->maxRows));
            }
            yield $key => $row;
        }
    }
}
