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

$config  = require __DIR__ . '/../bootstrap.php';

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

const SRC_DB = 'Energy';   // ancienne DB
// DST_DB = celle configurée dans config.php (energyv2)

$isCli = PHP_SAPI === 'cli';

// ── Helpers ───────────────────────────────────────────────────────────────
function line(string $msg): void {
    global $isCli;
    echo $isCli ? $msg . "\n" : $msg . '<br>';
}

function section(string $title): void {
    global $isCli;
    if ($isCli) {
        echo "\n── $title " . str_repeat('─', max(0, 50 - strlen($title))) . "\n";
    } else {
        echo '<div class="section-title">' . htmlspecialchars($title) . '</div>';
    }
}

// ── HTML shell ────────────────────────────────────────────────────────────
if (!$isCli): ?>
<!DOCTYPE html>
<html lang="fr">
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
  table { width:100%; border-collapse:collapse; font-size:.82rem; margin-bottom:1.5rem; }
  thead th { background:#111318; color:#556070; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; padding:.6rem 1rem; text-align:left; }
  tbody tr { border-top:1px solid #1e2530; }
  tbody tr:hover { background:#111318; }
  tbody td { padding:.5rem 1rem; font-family:monospace; color:#a0aec0; font-size:.8rem; }
  .ok   { color:#6ee7b7; }
  .warn { color:#fb923c; }
  .err  { color:#f87171; }
  pre { background:#111318; border:1px solid #1f2530; border-radius:6px; padding:12px; font-size:.78rem; overflow-x:auto; margin:.5rem 0 1rem; color:#f87171; }
  .btn { display:inline-flex; gap:8px; padding:9px 20px; border-radius:6px; font-size:.82rem; font-weight:700; text-transform:uppercase; text-decoration:none; margin-right:8px; }
  .btn-live { background:#2fd58e; color:#0b0d10; }
  .btn-dry  { background:#1e293b; color:#c8d0dc; border:1px solid #334155; }
</style>
</head>
<body>
<a href="../public/index.php" class="back">← Retour au dashboard</a>
<h1>Migration DB — <?= SRC_DB ?> → <?= htmlspecialchars($config['database']['name']) ?></h1>
<?php
$modeLabel = $isDryRun ? 'DRY-RUN (simulation)' : '⚠ MODE RÉEL — insertions en cours';
$modeClass = $isDryRun ? 'dry' : 'live';
echo "<span class='badge $modeClass'>$modeLabel</span><br><br>";
echo "<a href='?mode=dry'  class='btn btn-dry'>Dry-run</a>";
echo "<a href='?mode=live' class='btn btn-live' onclick=\"return confirm('Insérer les données manquantes dans " . htmlspecialchars($config['database']['name']) . " ?')\">Exécuter (live)</a>";
echo '<br><br>';
endif; // end HTML shell

// ── Connexion ─────────────────────────────────────────────────────────────
try {
    $dst = (new Database($config['database']))->pdo();
} catch (\Throwable $e) {
    line('ERREUR connexion DB destination : ' . $e->getMessage());
    if (!$isCli) echo '</body></html>';
    exit(1);
}

// Connexion à la DB source (même host, DB différente)
try {
    $srcConfig = array_merge($config['database'], ['name' => SRC_DB]);
    $src = (new Database($srcConfig))->pdo();
} catch (\Throwable $e) {
    line('ERREUR connexion DB source (' . SRC_DB . ') : ' . $e->getMessage());
    if (!$isCli) echo '</body></html>';
    exit(1);
}

// ── Migration par table ────────────────────────────────────────────────────
$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach (TABLES as $table => $columns) {
    section($table);

    // Vérifier que la table source existe
    $check = $src->prepare('SHOW TABLES LIKE :t');
    $check->execute(['t' => $table]);
    if (!$check->fetchColumn()) {
        line("  ⚠ Table absente dans " . SRC_DB . ", ignorée.");
        continue;
    }

    // Vérifier que la table destination existe
    $checkDst = $dst->prepare('SHOW TABLES LIKE :t');
    $checkDst->execute(['t' => $table]);
    if (!$checkDst->fetchColumn()) {
        line("  ⚠ Table absente dans la DB destination, ignorée.");
        continue;
    }

    // Compter les lignes source
    $countSrc = (int) $src->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $countDst = (int) $dst->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();

    if (!$isCli) {
        echo "<div class='info-grid'>";
        echo "  <div class='info-card'><div class='label'>Lignes source</div><div class='value'>" . number_format($countSrc) . "</div></div>";
        echo "  <div class='info-card'><div class='label'>Lignes destination (avant)</div><div class='value'>" . number_format($countDst) . "</div></div>";
        echo "</div>";
    } else {
        line("  Source : $countSrc lignes | Destination actuelle : $countDst lignes");
    }

    if ($countSrc === 0) {
        line("  ⚠ Table source vide, rien à migrer.");
        continue;
    }

    // Lire toutes les lignes source
    $cols    = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $srcRows = $src->query("SELECT $cols FROM `$table` ORDER BY timestamp ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les timestamps déjà présents en destination (pour éviter les doublons)
    $existingTs = [];
    $tsRows = $dst->query("SELECT timestamp FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tsRows as $ts) {
        $existingTs[$ts] = true;
    }

    $placeholders = implode(', ', array_map(fn($c) => ":$c", $columns));
    $insertSql    = "INSERT INTO `$table` ($cols) VALUES ($placeholders)";
    $stmt         = $isDryRun ? null : $dst->prepare($insertSql);

    $inserted = 0;
    $skipped  = 0;
    $errors   = 0;
    $samples  = []; // pour affichage

    if (!$isDryRun) $dst->beginTransaction();

    foreach ($srcRows as $row) {
        if (isset($existingTs[$row['timestamp']])) {
            $skipped++;
            continue;
        }

        if (!$isDryRun) {
            try {
                $stmt->execute($row);
                $inserted++;
            } catch (\Throwable $e) {
                $errors++;
                if (count($samples) < 3) {
                    $samples[] = ['ts' => $row['timestamp'], 'err' => $e->getMessage()];
                }
            }
        } else {
            $inserted++; // dry-run : on compte ce qui serait inséré
        }
    }

    if (!$isDryRun) {
        if ($errors === 0) {
            $dst->commit();
        } else {
            $dst->rollBack();
            line("  ✗ Transaction annulée à cause de $errors erreur(s).");
        }
    }

    $totalInserted += $inserted;
    $totalSkipped  += $skipped;
    $totalErrors   += $errors;

    if (!$isCli) {
        echo "<div class='info-grid'>";
        $label = $isDryRun ? 'À insérer (simulation)' : 'Insérées';
        echo "  <div class='info-card'><div class='label'>" . $label . "</div><div class='value " . ($inserted > 0 ? 'green' : 'muted') . "'>" . number_format($inserted) . "</div></div>";
        echo "  <div class='info-card'><div class='label'>Déjà présentes (skip)</div><div class='value muted'>" . number_format($skipped) . "</div></div>";
        if ($errors > 0) {
            echo "  <div class='info-card'><div class='label'>Erreurs</div><div class='value err'>" . number_format($errors) . "</div></div>";
        }
        echo "</div>";

        foreach ($samples as $s) {
            echo "<pre>Erreur sur {$s['ts']} : " . htmlspecialchars($s['err']) . "</pre>";
        }
    } else {
        $label = $isDryRun ? 'Seraient insérées' : 'Insérées';
        line("  $label : $inserted | Skippées : $skipped" . ($errors > 0 ? " | ERREURS : $errors" : ''));
    }
}

// ── Résumé ─────────────────────────────────────────────────────────────────
section('Résumé');

if (!$isCli) {
    echo "<div class='info-grid'>";
    $lbl = $isDryRun ? 'Total à insérer' : 'Total inséré';
    echo "  <div class='info-card'><div class='label'>$lbl</div><div class='value " . ($totalInserted > 0 ? 'green' : 'muted') . "'>" . number_format($totalInserted) . "</div></div>";
    echo "  <div class='info-card'><div class='label'>Total skippé</div><div class='value muted'>" . number_format($totalSkipped) . "</div></div>";
    if ($totalErrors > 0) {
        echo "  <div class='info-card'><div class='label'>Total erreurs</div><div class='value err'>" . number_format($totalErrors) . "</div></div>";
    }
    echo "</div>";
    echo '</body></html>';
} else {
    $lbl = $isDryRun ? '[DRY-RUN] Seraient insérées' : '[OK] Insérées';
    line("$lbl : $totalInserted | Skippées : $totalSkipped" . ($totalErrors > 0 ? " | ERREURS : $totalErrors" : ''));
}

exit($totalErrors > 0 ? 1 : 0);