<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Repository\BatteryReadingRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Service\BulkImportService;
use App\Service\ReadingGranularityPolicy;
use PDO;
use RuntimeException;

/**
 * Orchestrateur d'import partagé : ouvre la transaction, applique le plafond de
 * lignes (stop-and-report), dispatche vers {@see BulkImportService} puis
 * commit/rollback (dry-run). Point d'entrée unique des deux voies — self-service
 * (page compte) et CLI — afin d'éviter la duplication de l'orchestration
 * transaction/dry-run. (L'import admin pour un tiers a été retiré en #90.)
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
     * @param array<string, mixed> $post  Champs $_POST (energy_type, ts_col, value_col, unit, registers, battery_id, dry_run, overwrite).
     * @param array<string, mixed> $files Entrée $_FILES (clé `import_file`).
     * @param string $timezone Fuseau de l'utilisateur : délimite le jour civil du
     *        plafond des index de batterie (#26). Sans objet pour les autres types.
     * @throws RuntimeException si le téléversement ou le format est invalide.
     * @throws \InvalidArgumentException si le mapping ou le type d'énergie est invalide.
     *         Message sûr à afficher (aucun détail interne) : la route le présente tel quel.
     */
    public function runFromRequest(PDO $pdo, int $targetUserId, array $post, array $files, ?ReadingGranularityPolicy $throttle = null, string $timezone = 'UTC'): ImportReport
    {
        $energyType = strtolower(trim((string) ($post['energy_type'] ?? '')));
        $dryRun     = ($post['dry_run'] ?? '') === '1';
        $replace    = ($post['overwrite'] ?? '') === '1';

        // Batteries (#26) : un import vise UNE batterie, jamais le foyer. La cible
        // est donc portée par la requête et non déduite du fichier — deux batteries
        // mêlées dans un même CSV seraient indétectables ligne par ligne.
        $batteryId = filter_var($post['battery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $batteryId = $batteryId === false ? null : $batteryId;

        $file = is_array($files['import_file'] ?? null) ? $files['import_file'] : [];

        return $this->runUploaded($pdo, $targetUserId, $energyType, self::parseOverrides($post), $file, $dryRun, $replace, $throttle, $batteryId, $timezone);
    }

    /**
     * Traduit les champs de mapping d'un POST en surcharges pour
     * {@see ImportMapping::preset()}. Champ vide = surcharge absente (on garde le
     * défaut du preset).
     *
     * Le formulaire poste `registers[<register_key>] = <colonne>` — le sens naturel
     * d'un `<label>` (« quelle colonne alimente l'index heure pleine ? »). Le preset
     * attend l'inverse (`colonne => register_key`, une colonne ne pouvant alimenter
     * qu'un registre) : on inverse ici. Aucune validation des clés de registre à ce
     * stade — {@see ImportMapping::preset()} rejette déjà les inconnues.
     *
     * Statique et publique pour être testable sans PDO ni téléversement.
     *
     * @param array<string, mixed> $post
     * @return array{ts_col?: string, value_col?: string, unit?: string, registers?: array<string, string>}
     * @throws \InvalidArgumentException si deux registres visent la même colonne.
     */
    public static function parseOverrides(array $post): array
    {
        $overrides = [];
        foreach (['ts_col', 'value_col', 'unit'] as $field) {
            $value = is_scalar($post[$field] ?? null) ? trim((string) $post[$field]) : '';
            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }

        $registers = [];
        $posted    = is_array($post['registers'] ?? null) ? $post['registers'] : [];
        foreach ($posted as $registerKey => $column) {
            if (!is_scalar($column) || !is_string($registerKey)) {
                continue;
            }
            $column = strtolower(trim((string) $column));
            if ($column === '') {
                // Registre laissé vide : l'utilisateur ne veut pas l'importer.
                continue;
            }
            if (isset($registers[$column])) {
                // Sans ce garde, l'inversion écraserait silencieusement le premier
                // registre et l'import serait faux sans que rien ne le signale.
                throw new \InvalidArgumentException(sprintf(
                    'La colonne « %s » est affectée à deux index (« %s » et « %s ») : une colonne ne peut alimenter qu\'un seul index.',
                    $column,
                    $registers[$column],
                    strtolower(trim($registerKey))
                ));
            }
            $registers[$column] = strtolower(trim($registerKey));
        }

        // Aucun mapping saisi → pas de surcharge : le preset garde son défaut (une
        // colonne par clé de registre), donc les fichiers déjà conformes passent.
        if ($registers !== []) {
            $overrides['registers'] = $registers;
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $file      Entrée $_FILES (name, tmp_name, error, size…).
     * @param array{ts_col?: string, value_col?: string, unit?: string, registers?: array<string, string>} $overrides
     * @throws RuntimeException si le téléversement ou le format est invalide.
     */
    public function runUploaded(
        PDO $pdo,
        int $targetUserId,
        string $energyType,
        array $overrides,
        array $file,
        bool $dryRun,
        bool $replace = false,
        ?ReadingGranularityPolicy $throttle = null,
        ?int $batteryId = null,
        string $timezone = 'UTC',
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
            return $this->run($pdo, $mapping, $this->openRows($tmp, $ext === 'json'), $targetUserId, $energyType, $dryRun, $replace, $throttle, $batteryId, $timezone);
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
        bool $replace = false,
        ?ReadingGranularityPolicy $throttle = null,
        ?int $batteryId = null,
        string $timezone = 'UTC',
    ): ImportReport {
        $report = new ImportReport();

        // Cible de l'import batterie exigée AVANT toute écriture : sans elle, il n'y
        // a pas d'import « par défaut » raisonnable — écrire dans la première
        // batterie venue rattacherait des index au mauvais matériel, et le bilan
        // qui en découle serait faux sans que rien ne le signale.
        if ($mapping->isBattery() && $batteryId === null) {
            throw new \InvalidArgumentException('Sélectionnez la batterie à alimenter avant d\'importer.');
        }

        $pdo->beginTransaction();
        try {
            $capped = $this->capped($rows, $report);

            if ($mapping->isBattery()) {
                /** @var int $batteryId garanti non nul par le contrôle ci-dessus */
                $this->service->importBattery($capped, $mapping, new BatteryReadingRepository($pdo, $targetUserId, $batteryId), $report, $replace, $timezone);
            } elseif ($mapping->isElectricity()) {
                $this->service->importElectricity($capped, $mapping, new ElectricityReadingRepository($pdo, $targetUserId), $report, $replace, $throttle);
            } else {
                $this->service->importUtility($capped, $mapping, new UtilityReadingRepository($pdo, $targetUserId, $energyType), $report, $replace);
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
