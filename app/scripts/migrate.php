<?php

declare(strict_types=1);

/**
 * Applique les migrations SQL versionnées (app/sql/migrations/).
 *
 * Usage :
 *   php app/scripts/migrate.php             # applique les migrations en attente
 *   php app/scripts/migrate.php --dry-run   # liste sans appliquer
 */

use App\Infrastructure\Database;
use App\Infrastructure\MigrationRunner;

$config = require __DIR__ . '/../bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$database = new Database($config['database']);
$runner = new MigrationRunner($database->pdo(), __DIR__ . '/../sql/migrations');

$applied = $runner->migrate($dryRun);

if ($applied === []) {
    echo "Aucune migration en attente.\n";
    exit(0);
}

$prefix = $dryRun ? '[DRY-RUN] À appliquer' : 'Appliquées';
echo $prefix . ' : ' . implode(', ', $applied) . "\n";
exit(0);
