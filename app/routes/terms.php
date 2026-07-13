<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\View\ViewFactory;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6). bootstrap.php
// charge l'autoloader avant de valider la config, donc SecurityHeaders reste
// disponible dans le catch pour poser les en-têtes de sécurité sur l'erreur.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service indisponible : configuration manquante.';

    return;
}

SecurityHeaders::send();

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

echo $view->render('legal', [
    'page'      => 'terms',
    'available' => Locale::available($config),
]);
