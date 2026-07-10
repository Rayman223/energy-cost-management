<?php
/**
 * Migration script — Energy (prd) → energyv2 (dev)
 *
 * Copie Data_Brusol, Data_Dries, Data_Solaire depuis l'ancienne DB vers la nouvelle.
 * Les deux DB doivent être accessibles depuis le même serveur MySQL.
 *
 * Usage :
 *   php app/tools/migrate_db.php            → dry-run (affiche stats, n'insère rien)
 *   php app/tools/migrate_db.php live       → insertion réelle
 *   http://…/app/tools/migrate_db.php       → dry-run
 *   http://…/app/tools/migrate_db.php?mode=live → insertion réelle
 */

// ── Timeout & mémoire ─────────────────────────────────────────────────────
set_time_limit(0);           // pas de coupure PHP pour ce script uniquement
ini_set('memory_limit', '256M');

$config = require __DIR__ . '/../bootstrap.php';

use App\Infrastructure\Database;

// ── Mode ──────────────────────────────────────────────────────────────────
$isDryRun = true;
if (PHP_SAPI === 'cli') {
    $isDryRun = !in_array('live', $argv ?? [], true);
} else {
    $isDryRun = ($_GET['mode'] ?? 'dry') !== 'live';
}

// ── Tables à migrer ───────────────────────────────────────────────────────
const TABLES = [
    'Data_Dries'   => ['timestamp', 'Prelev_jour', 'Prelev_nuit', 'Injec_jour', 'Injec_nuit'],
    'Data_Solaire' => ['timestamp', 'production'],
    'Data_Brusol'  => ['timestamp', 'production'],
];

const SRC_DB     = 'Energy'; // ancienne DB
const CHUNK_SIZE = 500;      // lignes par INSERT multi-valeurs

$isCli = PHP_SAPI === 'cli';

// ── Helpers ───────────────────────────────────────────────────────────────
function line(string $msg): void {
    global $isCli;
    echo $isCli ? $msg . "\n" : $msg . '<br>';
    if (!$isCli) flush();
}

function section(string $title): void {
    global $isCli;
    if ($isCli) {
        echo "\n── $title " . str_repeat('─', max(0, 50 - strlen($title))) . "\n";
    } else {
        echo '<div class="section-title">' . htmlspecialchars($title) . '</div>';
        flush();
    }
}

function showError(string $msg, bool $isCli): void {
    if ($isCli) {
        echo "  ✗ ERREUR : $msg\n";
    } else {
        echo '<pre style="color:#f87171">✗ ERREUR : ' . htmlspecialchars($msg) . '</pre>';
        flush();
    }
}

// ── HTML shell ────────────────────────────────────────────────────────────
if (!$isCli): ?>
<!DOCTYPE html>
<html lang="fr" data-confirm-title="Confirmation" data-confirm-ok="Confirmer" data-confirm-cancel="Annuler">
<head>
<meta charset="UTF-8">
<title>Migration DB</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #0b0d10; color: #c8d0dc; padding: 2rem; }
  h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: .4rem; }
  .badge { display:inline-block; padding:.25rem .75rem; border-radius:999px; font-size:.78rem; font-weight:700; margin-bottom:1.5rem; }
  .badge.dry  { background:#78350f; color:#fcd34d; }
  .badge.live { background:#14532d; color:#6ee7b7; }
  .back { display:inline-block; margin-bottom:1.5rem; color:#41b3f5; text-decoration:none; font-size:.85rem; }
  .back:hover { text-decoration:underline; }
  .section-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#556070; margin:1.5rem 0 .75rem; border-bottom:1px solid #1e293b; padding-bottom:.4rem; }
  .info-grid { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
  .info-card { background:#111318; border:1px solid #1f2530; border-radius:8px; padding:.8rem 1.1rem; min-width:160px; }
  .info-card .label { font-size:.68rem; text-transform:uppercase; color:#556070; margin-bottom:.2rem; }
  .info-card .value { font-size:1.05rem; font-weight:600; color:#f1f5f9; font-family:monospace; }
  .info-card .value.green  { color:#6ee7b7; }
  .info-card .value.orange { color:#fb923c; }
  .info-card .value.muted  { color:#556070; }
  .ok   { color:#6ee7b7; }
  .warn { color:#fb923c; }
  .err  { color:#f87171; }
  pre { background:#111318; border:1px solid #1f2530; border-radius:6px; padding:12px; font-size:.78rem; overflow-x:auto; margin:.5rem 0 1rem; color:#f87171; }
  .btn { display:inline-flex; gap:8px; padding:9px 20px; border-radius:6px; font-size:.82rem; font-weight:700; text-transform:uppercase; text-decoration:none; margin-right:8px; }
  .btn-live { background:#2fd58e; color:#0b0d10; }
  .btn-dry  { background:#1e293b; color:#c8d0dc; border:1px solid #334155; }
</style>
<link rel="stylesheet" href="../public/assets/css/confirm.css">
</head>
<body>
<a href="../public/index.php" class="back">← Retour au dashboard</a>
<h1>Migration DB — <?= SRC_DB ?> → <?= htmlspecialchars($config['database']['name']) ?></h1>
<?php
$modeLabel = $isDryRun ? 'DRY-RUN (simulation)' : '⚠ MODE RÉEL — insertions en cours';
$modeClass = $isDryRun ? 'dry' : 'live';
echo "<span class='badge $modeClass'>$modeLabel</span><br><br>";
echo "<a href='?mode=dry'  class='btn btn-dry'>Dry-run</a>";
echo "<a href='?mode=live' class='btn btn-live' data-confirm='Insérer les données manquantes dans "
    . htmlspecialchars($config['database']['name'], ENT_QUOTES) . " ?' data-confirm-ok='Exécuter (live)' data-confirm-danger>Exécuter (live)</a>";
echo '<br><br>';
flush();
endif;

// ── Connexion DB destination ───────────────────────────────────────────────
try {
    $dst = (new Database($config['database']))->pdo();
} catch (\Throwable $e) {
    showError('Connexion DB destination : ' . $e->getMessage(), $isCli);
    if (!$isCli) echo '<script src="../public/assets/js/confirm.js" defer></script></body></html>';
    exit(1);
}

// ── Connexion DB source (même host, DB différente) ────────────────────────
try {
    $srcConfig = array_merge($config['database'], ['name' => SRC_DB]);
    $src       = (new Database($srcConfig))->pdo();
} catch (\Throwable $e) {
    showError('Connexion DB source (' . SRC_DB . ') : ' . $e->getMessage(), $isCli);
    if (!$isCli) echo '</body></html>';
    exit(1);
}

// ── Migration par table ────────────────────────────────────────────────────
$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach (TABLES as $table => $columns) {
    section($table);

    try {

        // ── Vérifications d'existence ──────────────────────────────────────
        $chkSrc = $src->prepare('SHOW TABLES LIKE :t');
        $chkSrc->execute(['t' => $table]);
        if (!$chkSrc->fetchColumn()) {
            line("  ⚠ Table absente dans " . SRC_DB . ", ignorée.");
            continue;
        }

        $chkDst = $dst->prepare('SHOW TABLES LIKE :t');
        $chkDst->execute(['t' => $table]);
        if (!$chkDst->fetchColumn()) {
            line("  ⚠ Table absente dans la DB destination, ignorée.");
            continue;
        }

        // ── Comptages ─────────────────────────────────────────────────────
        $countSrc = (int) $src->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $countDst = (int) $dst->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();

        // ── Étape 1 : déduplication de la destination ─────────────────────
        $dupCount = (int) $dst->query(
            "SELECT COUNT(*) FROM `$table` d
             WHERE d.id NOT IN (
                 SELECT MIN(id) FROM `$table`
                 GROUP BY DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s')
             )"
        )->fetchColumn();

        if ($dupCount > 0) {
            $dupMsg = "⚠ $dupCount doublon(s) détecté(s) en destination."
                    . ($isDryRun ? " (dry-run : non supprimés)" : " Suppression en cours…");
            if (!$isCli) {
                echo "<p style='color:#fb923c;font-family:monospace;font-size:.8rem;margin-bottom:8px'>$dupMsg</p>";
                flush();
            } else {
                line("  $dupMsg");
            }

            if (!$isDryRun) {
                $dst->exec(
                    "DELETE FROM `$table`
                     WHERE id NOT IN (
                         SELECT * FROM (
                             SELECT MIN(id) FROM `$table`
                             GROUP BY DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s')
                         ) AS keep
                     )"
                );
                $countDst = (int) $dst->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                line("  ✓ Doublons supprimés. Destination : $countDst lignes.");
            }
        }

        if (!$isCli) {
            echo "<div class='info-grid'>";
            echo "<div class='info-card'><div class='label'>Lignes source</div>"
               . "<div class='value'>" . number_format($countSrc) . "</div></div>";
            echo "<div class='info-card'><div class='label'>Lignes destination (après dédup)</div>"
               . "<div class='value'>" . number_format($countDst) . "</div></div>";
            if ($dupCount > 0 && $isDryRun) {
                echo "<div class='info-card'><div class='label'>Doublons à supprimer</div>"
                   . "<div class='value' style='color:#fb923c'>" . number_format($dupCount) . "</div></div>";
            }
            echo "</div>";
            flush();
        } else {
            line("  Source : $countSrc lignes | Destination (après dédup) : $countDst lignes");
        }

        if ($countSrc === 0) {
            line("  ⚠ Table source vide, rien à migrer.");
            continue;
        }

        // ── Étape 2 : chargement des timestamps destination en mémoire ────
        // DATE_FORMAT normalise les formats (espaces, T, millisecondes…).
        $existingTs = [];
        foreach (
            $dst->query(
                "SELECT DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') FROM `$table`"
            )->fetchAll(PDO::FETCH_COLUMN) as $ts
        ) {
            $existingTs[$ts] = true;
        }

        // ── Étape 3 : lecture source + filtrage PHP ───────────────────────
        $cols    = implode(', ', array_map(fn($c) => "`$c`", $columns));
        $srcRows = $src->query(
            "SELECT $cols, DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') AS ts_norm
             FROM `$table` ORDER BY timestamp ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $toInsert = [];
        $skipped  = 0;
        foreach ($srcRows as $row) {
            $tsNorm = $row['ts_norm'];
            unset($row['ts_norm']);
            if (isset($existingTs[$tsNorm])) {
                $skipped++;
            } else {
                $toInsert[] = $row;
            }
        }
        $inserted = count($toInsert);

        // ── Étape 4 : insertion par chunks (INSERT multi-valeurs) ─────────
        // ~500× plus rapide que les inserts un par un de la version originale.
        // En dry-run : on affiche le compte sans rien toucher.
        $errors  = 0;
        $errMsgs = [];

        if (!$isDryRun && $inserted > 0) {
            $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $dst->beginTransaction();
            try {
                foreach (array_chunk($toInsert, CHUNK_SIZE) as $chunk) {
                    $allPlaceholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));
                    $stmt = $dst->prepare(
                        "INSERT IGNORE INTO `$table` ($cols) VALUES $allPlaceholders"
                    );
                    $params = [];
                    foreach ($chunk as $row) {
                        foreach ($columns as $col) {
                            $params[] = $row[$col];
                        }
                    }
                    $stmt->execute($params);
                }
                $dst->commit();
            } catch (\Throwable $e) {
                $dst->rollBack();
                $errors++;
                $errMsgs[] = $e->getMessage();
                $inserted  = 0;
                line("  ✗ Transaction annulée : " . $e->getMessage());
            }
        }

        $totalInserted += $inserted;
        $totalSkipped  += $skipped;
        $totalErrors   += $errors;

        if (!$isCli) {
            echo "<div class='info-grid'>";
            $label = $isDryRun ? 'À insérer (simulation)' : 'Insérées';
            echo "<div class='info-card'><div class='label'>$label</div>"
               . "<div class='value " . ($inserted > 0 ? 'green' : 'muted') . "'>"
               . number_format($inserted) . "</div></div>";
            echo "<div class='info-card'><div class='label'>Déjà présentes (skip)</div>"
               . "<div class='value muted'>" . number_format($skipped) . "</div></div>";
            if ($errors > 0) {
                echo "<div class='info-card'><div class='label'>Erreurs</div>"
                   . "<div class='value err'>" . number_format($errors) . "</div></div>";
            }
            echo "</div>";
            foreach ($errMsgs as $msg) {
                echo "<pre>" . htmlspecialchars($msg) . "</pre>";
            }
            flush();
        } else {
            $label = $isDryRun ? 'Seraient insérées' : 'Insérées';
            line("  $label : $inserted | Skippées : $skipped"
               . ($errors > 0 ? " | ERREURS : $errors" : ''));
        }

    } catch (\Throwable $e) {
        // Catch-all : affiche l'erreur sans tuer le reste du script
        showError("[$table] " . $e->getMessage(), $isCli);
        $totalErrors++;
    }
}

// ── Résumé ─────────────────────────────────────────────────────────────────
section('Résumé');

if (!$isCli) {
    echo "<div class='info-grid'>";
    $lbl = $isDryRun ? 'Total à insérer' : 'Total inséré';
    echo "<div class='info-card'><div class='label'>$lbl</div>"
       . "<div class='value " . ($totalInserted > 0 ? 'green' : 'muted') . "'>"
       . number_format($totalInserted) . "</div></div>";
    echo "<div class='info-card'><div class='label'>Total skippé</div>"
       . "<div class='value muted'>" . number_format($totalSkipped) . "</div></div>";
    if ($totalErrors > 0) {
        echo "<div class='info-card'><div class='label'>Total erreurs</div>"
           . "<div class='value err'>" . number_format($totalErrors) . "</div></div>";
    }
    echo "</div>";
    echo '<script src="../public/assets/js/confirm.js" defer></script></body></html>';
} else {
    $lbl = $isDryRun ? '[DRY-RUN] Seraient insérées' : '[OK] Insérées';
    line("$lbl : $totalInserted | Skippées : $totalSkipped"
       . ($totalErrors > 0 ? " | ERREURS : $totalErrors" : ''));
}

exit($totalErrors > 0 ? 1 : 0);