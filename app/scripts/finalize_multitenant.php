<?php

declare(strict_types=1);

/**
 * Finalisation de la migration multi-tenant (P2 étape 2, phase « contract ») :
 *   1. Backfill final des tables legacy (Data_*) vers le compte propriétaire
 *      (rattrape les lignes écrites entre le backfill initial et le déploiement) ;
 *   2. Réattribution des lignes webhook_sync_state historiques (user_id=0) ;
 *   3. Suppression des tables legacy (--execute uniquement).
 *
 * À lancer UNE FOIS, manuellement, après le déploiement de l'étape 2 et après
 * vérification du dashboard. Dry-run par défaut.
 *
 * Usage :
 *   php app/scripts/finalize_multitenant.php                 <- dry-run
 *   php app/scripts/finalize_multitenant.php --execute       <- exécution réelle
 *   php app/scripts/finalize_multitenant.php --user=<id> ... <- propriétaire explicite
 */

use App\Infrastructure\Database;
use App\Infrastructure\MultiTenantBackfill;
use App\Repository\UserRepository;
use App\Security\UserContext;

$config  = require __DIR__ . '/../bootstrap.php';
$execute = in_array('--execute', $argv, true);

$database = new Database($config['database']);
$pdo = $database->pdo();

$userId = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
$owner = (new UserRepository($pdo))->findById($userId);
echo sprintf(
    "[INFO] Propriétaire : #%d — %s\n",
    $userId,
    $owner !== null ? $owner->displayName . ' (' . $owner->oidcIss . '/' . $owner->oidcSub . ')' : '?'
);

$legacyTables = ['Data_Dries', 'Data_Solaire', 'Data_Brusol', 'Data_gaz', 'Data_eau'];

$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
    $stmt->execute(['t' => $table]);

    return (bool) $stmt->fetchColumn();
};

$present = array_values(array_filter($legacyTables, $tableExists));
echo '[INFO] Tables legacy présentes : ' . ($present === [] ? '(aucune)' : implode(', ', $present)) . "\n";

// ── 1. Backfill final (rattrapage) ────────────────────────────────────────
$backfillable = array_intersect($present, ['Data_Dries', 'Data_Solaire', 'Data_gaz', 'Data_eau']);
if ($backfillable !== []) {
    if ($execute) {
        $counts = (new MultiTenantBackfill($pdo, new UserRepository($pdo)))->run($userId);
        unset($counts['owner_user_id']);
        foreach ($counts as $stream => $rows) {
            echo "  [backfill] {$stream} : {$rows} ligne(s) rattrapée(s)\n";
        }
    } else {
        echo "[DRY-RUN] Backfill final de rattrapage vers l'utilisateur #{$userId}.\n";
    }
} else {
    echo "[INFO] Rien à backfiller.\n";
}

// ── 2. Réattribution webhook_sync_state (user_id=0 → owner) ──────────────
$stmt = $pdo->query('SELECT COUNT(*) FROM webhook_sync_state WHERE user_id = 0');
$orphans = $stmt === false ? 0 : (int) $stmt->fetchColumn();
if ($orphans > 0) {
    if ($execute) {
        // INSERT IGNORE + DELETE : évite un conflit si (owner, source) existe déjà.
        $pdo->prepare(
            'INSERT IGNORE INTO webhook_sync_state (user_id, source_name, last_sent_at)
             SELECT :uid, source_name, last_sent_at FROM webhook_sync_state WHERE user_id = 0'
        )->execute(['uid' => $userId]);
        $pdo->exec('DELETE FROM webhook_sync_state WHERE user_id = 0');
        echo "  [sync-state] {$orphans} ligne(s) réattribuée(s) à l'utilisateur #{$userId}.\n";
    } else {
        echo "[DRY-RUN] {$orphans} ligne(s) webhook_sync_state à réattribuer.\n";
    }
} else {
    echo "[INFO] Aucun état de sync orphelin.\n";
}

// ── 3. Suppression des tables legacy ─────────────────────────────────────
if ($present !== []) {
    if ($execute) {
        foreach ($present as $table) {
            $pdo->exec('DROP TABLE ' . $table);
            echo "  [drop] {$table} supprimée.\n";
        }
    } else {
        echo '[DRY-RUN] Tables à supprimer : ' . implode(', ', $present) . "\n";
    }
}

echo $execute ? "[OK] Finalisation terminée.\n" : "[DRY-RUN] Relancez avec --execute pour appliquer.\n";
exit(0);
