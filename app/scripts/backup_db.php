<?php

declare(strict_types=1);

/**
 * Sauvegarde SQL complète de la base — 100 % PHP (via PDO), sans dépendre du
 * binaire `mysqldump`. Pensé pour tourner tel quel dans le container web
 * (ex. swag/Alpine) où seul PHP est disponible.
 *
 * Produit une archive horodatée et compressée dans backups/ :
 *   backups/<base>_AAAA-MM-JJ_HHMMSS.sql.gz
 * avec verrou anti-concurrence (flock), écriture atomique (.part → rename) et
 * rotation des sauvegardes de plus de RETENTION_DAYS jours.
 *
 * Usage :
 *   php app/scripts/backup_db.php
 *
 * Crontab (tous les jours à 3h) :
 *   0 3 * * * /usr/bin/php /chemin/app/scripts/backup_db.php >> /var/log/energy-backup.log 2>&1
 */

use App\Infrastructure\Database;
use App\Service\DatabaseBackupService;

$config = require __DIR__ . '/../bootstrap.php';

const RETENTION_DAYS = 30;

$projectDir = dirname(__DIR__, 2);
$backupDir = $projectDir . '/backups';
$lockFile = $projectDir . '/var/backup-db.lock';

function log_line(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function log_error(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

// --- Verrou d'exclusion (flock) : atomique et relâché automatiquement à la fin
// du process (y compris crash/kill), donc jamais de verrou fantôme. ---------
if (!is_dir(dirname($lockFile)) && !@mkdir(dirname($lockFile), 0775, true) && !is_dir(dirname($lockFile))) {
    log_error("ERREUR : impossible de créer le répertoire de verrou (" . dirname($lockFile) . ').');
    exit(1);
}
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_error("ERREUR : backup déjà en cours (lock: {$lockFile}).");
    exit(1);
}

if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    log_error("ERREUR : impossible de créer le répertoire de backups ({$backupDir}).");
    exit(1);
}

$database = new Database($config['database']);
$pdo = $database->pdo();
$dbName = (string) $config['database']['name'];

$timestamp = date('Y-m-d_His');
$finalPath = "{$backupDir}/{$dbName}_{$timestamp}.sql.gz";
$partPath = $finalPath . '.part';

// Purge le .part si le dump échoue en cours de route : jamais d'archive
// tronquée laissée derrière (elle passerait pour un backup valide). Après une
// promotion réussie (rename), le .part n'existe plus : is_file() est faux et
// rien n'est supprimé.
register_shutdown_function(static function () use ($partPath): void {
    if (is_file($partPath)) {
        @unlink($partPath);
    }
});

log_line("Début du backup de la base '{$dbName}' ...");

try {
    // Vue cohérente de la base pendant tout le dump (équivalent
    // --single-transaction de mysqldump, pour les tables InnoDB).
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $gz = gzopen($partPath, 'wb9');
    if ($gz === false) {
        throw new RuntimeException("ouverture impossible de {$partPath}");
    }

    try {
        $service = new DatabaseBackupService($pdo);
        foreach ($service->dump() as $chunk) {
            if (gzwrite($gz, $chunk) === false) {
                throw new RuntimeException("écriture gzip échouée dans {$partPath}");
            }
        }
    } finally {
        gzclose($gz);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    log_error('ERREUR : backup échoué — ' . $e->getMessage());
    exit(1);
}

// Promotion atomique : l'archive finale n'apparaît qu'une fois le dump complet.
if (!rename($partPath, $finalPath)) {
    log_error("ERREUR : impossible de promouvoir {$partPath} en {$finalPath}.");
    exit(1);
}

$sizeKib = round(filesize($finalPath) / 1024, 1);
log_line("Backup terminé : {$dbName}_{$timestamp}.sql.gz ({$sizeKib} Kio)");

// --- Rotation (suppression des backups > RETENTION_DAYS jours) --------------
$deleted = 0;
$threshold = time() - RETENTION_DAYS * 86400;
foreach (glob("{$backupDir}/{$dbName}_*.sql.gz") ?: [] as $file) {
    if (filemtime($file) < $threshold && @unlink($file)) {
        $deleted++;
    }
}
if ($deleted > 0) {
    log_line("Rotation : {$deleted} ancien(s) backup(s) supprimé(s) (> " . RETENTION_DAYS . ' jours)');
}

log_line('Backup OK');
exit(0);
