<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit.
 *
 * On charge l'autoloader Composer (PHPUnit + namespace `Tests\`) puis
 * l'autoloader maison du projet (`App\` → app/src/). On évite volontairement
 * app/bootstrap.php, qui exige config.php et une base de données.
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';
