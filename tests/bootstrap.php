<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit.
 *
 * On charge l'autoloader Composer, qui expose PHPUnit ainsi que les namespaces
 * `App\` (app/src/) et `Tests\` (tests/) déclarés en PSR-4 dans composer.json.
 * On évite volontairement app/bootstrap.php, qui exige config.php et une base
 * de données.
 */
require __DIR__ . '/../vendor/autoload.php';

// Fuseau déterministe pour les tests : l'application stocke et manipule les dates
// en UTC (cf. App\Support\Dates). On fixe donc UTC ici pour ne pas dépendre du
// date.timezone du php.ini de la machine qui exécute la suite.
date_default_timezone_set('UTC');
