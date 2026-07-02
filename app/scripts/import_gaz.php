<?php

declare(strict_types=1);

/**
 * Import CSV gaz -> table utility_readings (energy_type=gas)
 *
 * Usage :
 *   php import_gaz.php                         <- dry-run (aucune ecriture)
 *   php import_gaz.php --execute [--user=<id>] <- import reel (defaut: 1er compte)
 *
 * Le script trie les lignes par date croissante avant l'insertion.
 * Il est idempotent : les doublons sont ignores via INSERT IGNORE.
 * Le fichier CSV doit être placé dans docs/meter_readings_export_gaz.csv
 * php /config/www/energyv2/app/scripts/import_gaz.php
 */

$dryRun  = !in_array('--execute', $argv ?? [], true);
$csvPath = __DIR__ . '/../docs/meter_readings_export_gaz.csv';

// ── Bootstrap ─────────────────────────────────────────────────────────────
$config = require __DIR__ . '/../bootstrap.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['database']['host'],
    $config['database']['port'],
    $config['database']['name'],
    $config['database']['charset']
);

try {
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    echo '[FATAL] Connexion DB impossible : ' . $e->getMessage() . "\n";
    exit(1);
}

// ── Lecture CSV ───────────────────────────────────────────────────────────
if (!is_file($csvPath)) {
    echo '[FATAL] Fichier introuvable : ' . $csvPath . "\n";
    exit(1);
}

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    echo "[FATAL] Impossible d'ouvrir le fichier CSV.\n";
    exit(1);
}

// Lire l'en-tete
$header = fgetcsv($handle);
if ($header === false) {
    echo "[FATAL] CSV vide ou mal forme.\n";
    fclose($handle);
    exit(1);
}

// Normaliser les colonnes (trim + lowercase)
$header = array_map(static fn(string $h): string => strtolower(trim($h)), $header);

$colTimestamp = array_search('timestamp', $header, true);
$colGaz       = array_search('gaz naturel', $header, true);

if ($colTimestamp === false || $colGaz === false) {
    echo '[FATAL] Colonnes attendues : "Timestamp", "Gaz naturel". Colonnes trouvees : ' . implode(', ', $header) . "\n";
    fclose($handle);
    exit(1);
}

// ── Parsing des lignes ────────────────────────────────────────────────────
$rows    = [];
$skipped = [];
$lineNum = 1;

while (($line = fgetcsv($handle)) !== false) {
    $lineNum++;

    if (count($line) < 2) {
        $skipped[] = "Ligne {$lineNum} : moins de 2 colonnes, ignoree.";
        continue;
    }

    $rawTs  = trim($line[$colTimestamp]);
    $rawVal = trim($line[$colGaz]);

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawTs);
    if ($dt === false) {
        $skipped[] = "Ligne {$lineNum} : timestamp invalide '{$rawTs}', ignoree.";
        continue;
    }

    if (!is_numeric($rawVal) || (float) $rawVal < 0) {
        $skipped[] = "Ligne {$lineNum} : valeur m3 invalide '{$rawVal}', ignoree.";
        continue;
    }

    $rows[] = [
        'reading_at' => $dt->format('Y-m-d H:i:s'),
        'counter_m3' => round((float) $rawVal, 3),
    ];
}

fclose($handle);

// ── Tri croissant par date ────────────────────────────────────────────────
usort($rows, static fn(array $a, array $b): int => $a['reading_at'] <=> $b['reading_at']);

// ── Resume du parsing ─────────────────────────────────────────────────────
echo sprintf(
    "\n[CSV] %d ligne(s) valide(s) trouvee(s), %d ignoree(s).\n",
    count($rows),
    count($skipped)
);

if ($skipped !== []) {
    echo "\n[WARN] Lignes ignorees :\n";
    foreach ($skipped as $msg) {
        echo '  ' . $msg . "\n";
    }
}

if ($rows === []) {
    echo "[INFO] Aucune donnee a importer.\n";
    exit(0);
}

// Apercu des 3 premieres et dernières lignes (apres tri)
echo "\n[APERCU] Premieres lignes (ordre d'insertion) :\n";
foreach (array_slice($rows, 0, 3) as $row) {
    echo sprintf("  %s  ->  %.3f m3\n", $row['reading_at'], $row['counter_m3']);
}
echo "  ...\n";
echo "[APERCU] Dernieres lignes :\n";
foreach (array_slice($rows, -3) as $row) {
    echo sprintf("  %s  ->  %.3f m3\n", $row['reading_at'], $row['counter_m3']);
}

// ── Mode dry-run ──────────────────────────────────────────────────────────
if ($dryRun) {
    echo "\n[DRY-RUN] Aucune ecriture effectuee.\n";
    echo "[DRY-RUN] Relancez avec --execute pour importer reellement.\n\n";
    exit(0);
}

// ── Import reel ───────────────────────────────────────────────────────────
echo "\n[IMPORT] Debut de l'import (" . count($rows) . " lignes, ordre croissant)...\n";

$userId = \App\Security\UserContext::cliUserId($pdo, \App\Security\UserContext::parseCliUserArg());
echo "[INFO] Import vers l'utilisateur #{$userId}.\n";

$stmt = $pdo->prepare(
    "INSERT IGNORE INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
     VALUES ({$userId}, 'gas', :reading_at, :counter_m3)"
);

$inserted = 0;
$ignored  = 0;
$errors   = [];

foreach ($rows as $row) {
    try {
        $stmt->execute($row);
        if ($stmt->rowCount() > 0) {
            $inserted++;
            echo sprintf("  [OK]  %s  ->  %.3f m3\n", $row['reading_at'], $row['counter_m3']);
        } else {
            $ignored++;
            echo sprintf("  [DUP] %s  ->  %.3f m3  (doublon ignore)\n", $row['reading_at'], $row['counter_m3']);
        }
    } catch (\PDOException $e) {
        $errors[] = sprintf('%s : %s', $row['reading_at'], $e->getMessage());
        echo sprintf("  [ERR] %s  ->  %s\n", $row['reading_at'], $e->getMessage());
    }
}

// ── Bilan ─────────────────────────────────────────────────────────────────
echo "\n[BILAN]\n";
echo "  Inseres  : {$inserted}\n";
echo "  Doublons : {$ignored}\n";
echo "  Erreurs  : " . count($errors) . "\n";

if ($errors !== []) {
    echo "\n[ERREURS DETAIL]\n";
    foreach ($errors as $err) {
        echo '  ' . $err . "\n";
    }
    exit(1);
}

echo "\n[OK] Import termine.\n\n";
exit(0);