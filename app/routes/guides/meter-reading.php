<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\View\ErrorPage;
use App\View\GuidePage;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6).
try {
    $config = require __DIR__ . '/../../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service indisponible : configuration manquante.';

    return;
}

SecurityHeaders::send($config);

// Contenu absent dans toutes les locales : 404 plutôt qu'une page vide.
$html = GuidePage::render($config, 'meter-reading');
if ($html === null) {
    http_response_code(404);
    echo ErrorPage::render($config, 404);

    return;
}

echo $html;
