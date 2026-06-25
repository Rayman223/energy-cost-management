<?php

declare(strict_types=1);

/**
 * Autoloader maison du projet : namespace `App\` → `app/src/`.
 *
 * Isolé de bootstrap.php pour pouvoir être chargé seul (ex. suite de tests
 * PHPUnit) sans déclencher le chargement de la configuration ni de la base.
 */
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
