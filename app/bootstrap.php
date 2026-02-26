<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (str_starts_with($class, $prefix) === false) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Le fichier app/config/config.php est manquant. Copiez config.example.php puis adaptez vos valeurs.');
}

$config = require $configPath;

date_default_timezone_set($config['timezone'] ?? 'UTC');

return $config;
