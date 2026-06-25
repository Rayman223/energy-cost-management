<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Le fichier app/config/config.php est manquant. Copiez config.example.php puis adaptez vos valeurs.');
}

$config = require $configPath;

date_default_timezone_set($config['timezone'] ?? 'UTC');

return $config;
