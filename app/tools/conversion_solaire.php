<?php
/**
 * Migration tool — Data_Brusol → Data_Solaire
 *
 * Kept in app/tools/ because the physical solar dongle is not yet fully reliable.
 * Run with ?DRY_RUN=1 (default) or ?DRY_RUN=0 for live insertion.
 *
 * Usage:
 *   php app/tools/conversion_solaire.php             (CLI, dry-run)
 *   php app/tools/conversion_solaire.php DRY_RUN=0   (CLI, live)
 *   http://…/app/tools/conversion_solaire.php        (web, dry-run)
 *   http://…/app/tools/conversion_solaire.php?DRY_RUN=0 (web, live)
 */

$config = require __DIR__ . '/../bootstrap.php';

use App\Infrastructure\Database;

$DRY_RUN = true;
if (PHP_SAPI === 'cli') {
    // Accept CLI arg e.g. DRY_RUN=0
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, 'DRY_RUN=')) {
            $DRY_RUN = (trim(explode('=', $arg, 2)[1] ?? '1') !== '0');
        }
    }
} else {
    $DRY_RUN = ($_GET['DRY_RUN'] ?? '1') !== '0';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Migration Data_Solaire</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #0b0d10; color: #e2e8f0; padding: 2rem; }
  h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: .4rem; }
  .badge { display: inline-block; padding: .25rem .75rem; border-radius: 999px; font-size: .78rem; font-weight: 700; margin-bottom: 1.5rem; }
  .badge.dry  { background: #78350f; color: #fcd34d; }
  .badge.live { background: #14532d; color: #6ee7b7; }
  .back { display:inline-block;margin-bottom:1.5rem;color:#41b3f5;text-decoration:none;font-size:.85rem; }
  .back:hover { text-decoration:underline; }
  .section { margin-bottom: 2rem; }
  .section-title { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #556070; margin-bottom: .75rem; border-bottom: 1px solid #1e293b; padding-bottom: .4rem; }
  .info-grid { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
  .info-card { background: #111318; border: 1px solid #1f2530; border-radius: 8px; padding: .9rem 1.2rem; min-width: 200px; }
  .info-card .label { font-size: .7rem; text-transform: uppercase; color: #556070; margin-bottom: .2rem; }
  .info-card .value { font-size: 1.1rem; font-weight: 600; color: #f1f5f9; }
  table { width: 100%; border-collapse: collapse; font-size: .875rem; }
  thead th { background: #111318; color: #556070; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: .6rem 1rem; text-align: left; }
  tbody tr { border-bottom: 1px solid #1e2530; transition: background .15s; }
  tbody tr:hover { background: #111318; }
  tbody td { padding: .55rem 1rem; color: #a0aec0; }
  .tag { display: inline-block; padding: .15rem .55rem; border-radius: 4px; font-size: .7rem; font-weight: 700; }
  .tag.existant { background: #1e3a5f; color: #60a5fa; }
  .tag.dryrun   { background: #3b1a00; color: #fb923c; }
  .tag.insert   { background: #14532d; color: #6ee7b7; }
  .up   { color: #6ee7b7; }
  .warn { color: #f87171; }
  .summary { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 1.5rem; }
  .summary .info-card .value.green  { color: #6ee7b7; }
  .summary .info-card .value.orange { color: #fb923c; }
  pre { background: #111318; border: 1px solid #1f2530; border-radius: 6px; padding: 12px; font-size: .78rem; overflow-x: auto; margin-top: 8px; color: #f87171; }
</style>
</head>
<body>

<a href="../public/index.php" class="back">← Retour au dashboard</a>
<h1>Migration Data_Brusol → Data_Solaire</h1>

<?php
$modeLabel = $DRY_RUN ? 'DRY-RUN (simulation)' : '⚠ MODE RÉEL — insertions en DB';
$modeClass = $DRY_RUN ? 'dry' : 'live';
echo "<span class='badge $modeClass'>$modeLabel</span>";

if (!$DRY_RUN) {
    echo "<p style='color:#f87171;margin-bottom:1rem;font-size:.85rem;'>Toutes les lignes manquantes seront insérées dans Data_Solaire. Opération irréversible.</p>";
}

try {
    $pdo = (new Database($config['database']))->pdo();

    // ── Last known entry before the gap ──────────────────────────────────
    $stmt = $pdo->query(
        "SELECT Timestamp, Production FROM Data_Solaire
         WHERE Timestamp < '2026-02-01'
         ORDER BY Timestamp DESC LIMIT 1"
    );
    $lastKnown = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lastKnown) {
        echo "<pre>Aucune entrée trouvée avant février 2026 dans Data_Solaire.\nVérifiez la table avant d'exécuter ce script.</pre>";
        exit;
    }

    $indexStart = (float) $lastKnown['Production'];
    $dateStart  = $lastKnown['Timestamp'];

    echo "<div class='info-grid'>";
    echo "  <div class='info-card'><div class='label'>Dernier timestamp connu</div><div class='value'>{$dateStart}</div></div>";
    echo "  <div class='info-card'><div class='label'>Index de départ</div><div class='value'>{$indexStart} kWh</div></div>";
    echo "</div>";

    // ── Existing entries (Feb onwards) ────────────────────────────────────
    $stmt      = $pdo->query(
        "SELECT Timestamp, Production FROM Data_Solaire
         WHERE Timestamp >= '2026-02-01' ORDER BY Timestamp ASC"
    );
    $existants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class='section'>";
    echo "<div class='section-title'>Entrées existantes dans Data_Solaire (fév+) — " . count($existants) . " ligne(s)</div>";

    if (count($existants) > 0) {
        echo "<table><thead><tr><th>Statut</th><th>Timestamp</th><th>Index (kWh)</th><th>Cohérence</th></tr></thead><tbody>";
        $prev = $indexStart;
        foreach ($existants as $e) {
            $val     = (float) $e['Production'];
            $anom    = $val < $prev
                ? "<span class='warn'>⚠ Inférieur au précédent ($prev kWh)</span>"
                : "<span class='up'>✓ OK</span>";
            $prev    = $val;
            echo "<tr><td><span class='tag existant'>EXISTANT</span></td><td>{$e['Timestamp']}</td><td>{$val}</td><td>{$anom}</td></tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p style='color:#556070;font-size:.85rem'>Aucune entrée existante — table propre ✓</p>";
    }
    echo "</div>";

    // ── Missing rows from Data_Brusol ─────────────────────────────────────
    $stmt    = $pdo->prepare(
        "SELECT b.timestamp, b.production
         FROM Data_Brusol b
         LEFT JOIN Data_Solaire s ON s.Timestamp = b.timestamp
         WHERE b.timestamp >= '2026-02-01'
           AND b.timestamp <= NOW()
           AND s.Timestamp IS NULL
         ORDER BY b.timestamp ASC"
    );
    $stmt->execute();
    $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nbRows  = count($rows);

    $tagClass  = $DRY_RUN ? 'dryrun' : 'insert';
    $tagLabel  = $DRY_RUN ? 'DRY-RUN' : 'INSERT';

    echo "<div class='section'>";
    echo "<div class='section-title'>Lignes à insérer depuis Data_Brusol — {$nbRows} ligne(s)</div>";

    if ($nbRows === 0) {
        echo "<p style='color:#556070;font-size:.85rem'>Rien à insérer, Data_Solaire est déjà à jour ✓</p></div></body></html>";
        exit;
    }

    $insertStmt = (!$DRY_RUN)
        ? $pdo->prepare("INSERT INTO Data_Solaire (Timestamp, Production) VALUES (:ts, :prod)")
        : null;

    $indexCumul = $indexStart;
    $count      = 0;

    if (!$DRY_RUN) $pdo->beginTransaction();

    echo "<table><thead><tr><th>Statut</th><th>Timestamp</th><th>Production jour (kWh)</th><th>Index cumulé (kWh)</th></tr></thead><tbody>";

    foreach ($rows as $row) {
        $dayKwh     = (float) $row['production'] / 1000;
        $indexCumul += $dayKwh;
        $count++;

        echo "<tr>";
        echo "<td><span class='tag {$tagClass}'>{$tagLabel}</span></td>";
        echo "<td>{$row['timestamp']}</td>";
        echo "<td>+" . number_format($dayKwh, 3) . "</td>";
        echo "<td>" . number_format($indexCumul, 3) . "</td>";
        echo "</tr>";

        if (!$DRY_RUN) {
            $insertStmt->execute([':ts' => $row['timestamp'], ':prod' => $indexCumul]);
        }
    }

    echo "</tbody></table></div>";

    if (!$DRY_RUN) $pdo->commit();

    echo "<div class='summary'>";
    echo "  <div class='info-card'><div class='label'>Index de départ</div><div class='value'>{$indexStart} kWh</div></div>";
    echo "  <div class='info-card'><div class='label'>Index final</div><div class='value green'>" . number_format($indexCumul, 3) . " kWh</div></div>";
    echo "  <div class='info-card'><div class='label'>Lignes " . ($DRY_RUN ? 'simulées' : 'insérées') . "</div><div class='value orange'>{$count}</div></div>";
    echo "</div>";

} catch (\PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "<pre>Erreur PDO : " . htmlspecialchars($e->getMessage()) . "</pre>";
} catch (\Throwable $e) {
    echo "<pre>Erreur : " . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
</body>
</html>
