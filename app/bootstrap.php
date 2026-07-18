<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/autoload.php';

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Le fichier app/config/config.php est manquant. Copiez config.example.php puis adaptez vos valeurs.');
}

$config = require $configPath;

// Validation non bloquante : on journalise les constats à signal runtime mais on
// ne throw jamais — index.php:15 garantit contractuellement que le routeur survit
// à un bootstrap cassé, et la prod ne doit pas 500 pour un config imparfait. On
// journalise les ERROR (database) ET les dérives silencieuses dangereuses
// (sentinelle en section active, clé déplacée dont la valeur est désormais
// ignorée) ; les sections absentes / clés inconnues restent muettes pour ne pas
// spammer (cf. ConfigIssue::isRuntimeSignal). Le rapport complet est le métier de
// app/scripts/config_check.php.
if (is_array($config)) {
    foreach (App\Config\ConfigValidator::validate($config) as $issue) {
        if ($issue->isRuntimeSignal()) {
            error_log('[config] ' . $issue->severity . ' ' . $issue->path . ' : ' . $issue->message);
        }
    }
}

date_default_timezone_set($config['timezone'] ?? 'UTC');

return $config;
