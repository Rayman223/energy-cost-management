<?php

declare(strict_types=1);

/**
 * Front controller — point d'entrée unique de l'application web.
 *
 * Depuis l'issue #106, l'app expose des URLs propres sans extension `.php`
 * (`/account`, `/tariffs`, `/api?action=…`). Le serveur route toute requête
 * non-fichier vers ce script (`try_files $uri $uri/ /index.php` côté Nginx/SWAG,
 * ou `php -S … app/public/index.php` en dev), qui dispatche vers le script de page
 * correspondant dans `app/routes/`.
 *
 * Volontairement sans dépendance au namespace `App\` : le routage doit fonctionner
 * même si le bootstrap applicatif échoue. Chaque page requise s'auto-amorce.
 */

// ── Serveur intégré PHP (`php -S`) : servir les fichiers existants tels quels ──
// (assets CSS/JS/images) sans passer par le routeur.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    // On ne délègue au serveur intégré que les fichiers statiques existants
    // (assets) ; les `.php` restent routés (parité avec Nginx/FPM en prod).
    if (
        is_string($requested)
        && $requested !== '/'
        && !str_ends_with($requested, '.php')
        && is_file(__DIR__ . $requested)
    ) {
        return false;
    }
}

// ── Préfixe de déploiement (sous-répertoire éventuel) ─────────────────────────
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath   = str_ends_with($scriptName, '/index.php')
    ? substr($scriptName, 0, -\strlen('/index.php'))
    : '';
$basePath = rtrim($basePath, '/');

// ── Chemin de route = REQUEST_URI, sans query ni préfixe de déploiement ───────
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
// Retrait du préfixe de déploiement sur une frontière de segment seulement.
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, \strlen($basePath));
}
$path = '/' . trim($path, '/'); // « /account/ » → « /account » ; « / » → « / »
if ($path === '/') {
    $path = '';                 // racine → tableau de bord
}

/** @var array<string, string> $routes route propre → script dans app/routes/ */
$routes = [
    ''                => 'dashboard.php',
    '/account'        => 'account.php',
    '/admin'          => 'admin.php',
    '/tariffs'        => 'tariffs.php',
    '/meter-readings' => 'meter-readings.php',
    '/api'            => 'api.php',
    '/login'          => 'login.php',
    '/privacy'        => 'privacy.php',
    '/terms'          => 'terms.php',
    '/auth/login'     => 'auth/login.php',
    '/auth/logout'    => 'auth/logout.php',
];

// En-têtes de sécurité « sûrs » pour les réponses gérées directement par le
// routeur (redirection 308, 404) — les pages routées posent leur CSP complète
// via App\Http\SecurityHeaders. Inline pour préserver l'indépendance à `App\`.
$sendSafeHeaders = static function (): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
};

// ── Compat : anciennes URLs « /xxx.php » → redirection vers « /xxx ». ──────────
// 308 (et non 301) pour **préserver la méthode et le corps** : les clients machine
// de l'API d'ingestion peuvent encore poster sur « /api.php » le temps de migrer
// leur `api_url` vers « /api ».
if (str_ends_with($path, '.php')) {
    $clean = $path === '/index.php' ? '' : substr($path, 0, -4);
    if (isset($routes[$clean])) {
        $sendSafeHeaders();
        header('Location: ' . $basePath . ($clean === '' ? '/' : $clean), true, 308);
        exit;
    }
}

$script = $routes[$path] ?? null;
if ($script === null) {
    $sendSafeHeaders();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

require __DIR__ . '/../routes/' . $script;
