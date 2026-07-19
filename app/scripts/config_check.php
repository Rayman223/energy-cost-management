<?php

declare(strict_types=1);

/**
 * Valide un fichier de configuration contre {@see App\Config\ConfigSchema} et
 * imprime un rapport lisible. Ne se connecte à rien (pas de bootstrap applicatif,
 * pas de PDO) : purement statique.
 *
 * Usage :
 *   php app/scripts/config_check.php
 *   php app/scripts/config_check.php --file=app/config/config.example.php --schema-only --strict
 *
 * Options :
 *   --file=<path>   Fichier à valider (défaut : app/config/config.php).
 *   --schema-only   Désactive le contrôle des sentinelles (les `change_me` sont
 *                   attendus dans le template) — mode du garde CI.
 *   --strict        Sort en code 2 si au moins un WARNING.
 *
 * Codes de sortie : 0 = OK (warnings tolérés) · 1 = ≥1 ERROR · 2 = ≥1 WARNING avec --strict.
 */

use App\Config\ConfigIssue;
use App\Config\ConfigSchema;
use App\Config\ConfigValidator;

require_once __DIR__ . '/../../vendor/autoload.php';

$args        = $argv ?? [];
$schemaOnly  = in_array('--schema-only', $args, true);
$strict      = in_array('--strict', $args, true);
$file        = __DIR__ . '/../config/config.php';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $raw  = substr($arg, strlen('--file='));
        $file = str_starts_with($raw, '/') ? $raw : (string) getcwd() . '/' . $raw;
    }
}

if (!is_file($file)) {
    fwrite(STDERR, "[ERROR] Fichier introuvable : {$file}\n");
    exit(1);
}

/** @var mixed $config */
$config = require $file;
if (!is_array($config)) {
    fwrite(STDERR, "[ERROR] {$file} ne retourne pas un tableau de configuration.\n");
    exit(1);
}

/** @var array<string, mixed> $config */
$issues = ConfigValidator::validate($config, !$schemaOnly);

$errors   = array_values(array_filter($issues, static fn (ConfigIssue $i): bool => $i->isError()));
$warnings = array_values(array_filter($issues, static fn (ConfigIssue $i): bool => !$i->isError()));

foreach ($errors as $issue) {
    echo '[ERROR] ' . $issue->path . ' : ' . $issue->message . "\n";
}
foreach ($warnings as $issue) {
    echo '[WARN]  ' . $issue->path . ' : ' . $issue->message . "\n";
}

// Sections présentes et sans le moindre constat → ligne [OK] récapitulative.
$flagged = [];
foreach ($issues as $issue) {
    $flagged[explode('.', $issue->path)[0]] = true;
}
$root          = ConfigSchema::root();
$rootChildren  = is_array($root['children'] ?? null) ? $root['children'] : [];
$knownSections = array_keys($rootChildren);
$clean         = [];
foreach ($knownSections as $section) {
    if (array_key_exists($section, $config) && !isset($flagged[$section])) {
        $clean[] = $section;
    }
}
if ($clean !== []) {
    echo '[OK]    ' . implode(', ', $clean) . "\n";
}

if ($errors !== []) {
    exit(1);
}
if ($strict && $warnings !== []) {
    exit(2);
}
exit(0);
