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
