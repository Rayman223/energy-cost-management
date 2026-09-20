<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Repository\StatisticsRepository;
use App\Security\AuthGuard;
use App\Security\WebAccessGuard;
use App\Seo\Sitemap;
use App\Service\StatisticsService;

/**
 * `/sitemap.xml` (#84).
 *
 * Généré : les URLs sont absolues (spécification sitemaps.org), déclinées par
 * locale, et la liste des variantes `/stats?country=…` dépend des pays dont les
 * agrégats franchissent réellement le seuil d'anonymat.
 *
 * Deux garde-fous repris de `/stats` :
 *  - **404 si OIDC est désactivé** : l'instance est alors entièrement privée,
 *    publier sa carte n'aurait aucun sens (et `robots.txt` y répond déjà
 *    `Disallow: /`).
 *  - **allowlist IP** appliquée même en mode public, comme sur toute page.
 *
 * La base injoignable ne fait pas échouer la réponse : le sitemap se réduit
 * alors aux pages permanentes. Un moteur préfère une carte partielle à une 500.
 */

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

if (!AuthGuard::isOidcEnabled($config)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';

    return;
}

WebAccessGuard::enforceIp($config['web_security'] ?? []);

/** @var list<string> $countries Pays dont les agrégats sont publiés. */
$countries = [];
try {
    $pdo      = (new Database($config['database']))->pdo();
    $overview = (new StatisticsService(new StatisticsRepository($pdo)))->publicOverview();

    foreach ($overview['prices'] as $row) {
        // Le bucket résiduel « autres pays » n'est pas un pays : pas d'URL.
        if ($row['is_other'] === false) {
            $countries[] = $row['country'];
        }
    }
} catch (\Throwable $e) {
    $countries = [];
}

header('Content-Type: application/xml; charset=utf-8');
echo Sitemap::render($config, $countries);
