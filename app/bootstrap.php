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

date_default_timezone_set($config['timezone'] ?? 'UTC');

return $config;
