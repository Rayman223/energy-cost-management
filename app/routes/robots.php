<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Seo\RobotsTxt;

/**
 * `/robots.txt` (#84) — généré plutôt que statique : son contenu dépend du mode
 * d'authentification (une instance auto-hébergée doit répondre `Disallow: /`)
 * et la ligne `Sitemap:` exige une URL absolue, donc l'origine configurée.
 *
 * Volontairement sans garde d'accès : c'est précisément le fichier qui déclare
 * ce qui ne doit pas être exploré, il doit rester lisible par un robot.
 */

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6).
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service indisponible : configuration manquante.';

    return;
}

SecurityHeaders::send($config);

header('Content-Type: text/plain; charset=utf-8');
echo RobotsTxt::render($config);
