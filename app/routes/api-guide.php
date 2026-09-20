<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\UserContext;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\DonateLink;
use App\Support\SiteUrl;
use App\View\ViewFactory;

/**
 * Guide d'envoi API (#132) : mode d'emploi expliquant comment pousser ses relevés
 * (électricité, gaz, eau) sur l'API d'ingestion avec un jeton Bearer.
 *
 * Réservé aux utilisateurs authentifiés (seuls eux peuvent créer un jeton) : évite
 * d'exposer la surface de l'API aux visiteurs anonymes. Le seul dynamisme est l'URL
 * absolue de l'API, dérivée de l'hôte courant pour un exemple curl copiable tel quel.
 */

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

SecurityHeaders::send($config);
AuthGuard::protect($config);

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

// URL absolue de l'API pour l'exemple curl, afin que la commande soit copiable
// telle quelle. La dérivation (schéma via X-Forwarded-Proto, anti-spoof du Host
// sur `web_security.trusted_hosts`, préfixe de sous-répertoire) vit dans
// {@see SiteUrl} depuis #84, où la génèrent aussi le canonical et le sitemap.
$apiUrl = SiteUrl::absolute($config, 'api');

// Statut admin pour l'en-tête commun (#193). Lecture DB défensive : cette page est
// conçue pour rester lisible même sans base — un échec dégrade en `false` (icône
// admin masquée) sans casser le guide.
// Fuseau du profil pour l'horloge du header (#200) : lu au passage, même repli
// défensif que $isAdmin. Reste null si le profil est absent ou la base
// injoignable → l'horloge retombe alors sur le fuseau du navigateur.
$isAdmin  = false;
$timezone = null;
try {
    $pdo      = (new Database($config['database']))->pdo();
    $users    = new UserRepository($pdo);
    $userId   = UserContext::currentWebUserId($pdo, $config);
    $isAdmin  = $users->findById($userId)?->isAdmin() ?? false;
    $timezone = $users->getProfile($userId)?->timezone;
} catch (\Throwable) {
    $isAdmin = false;
}

echo $view->render('api-guide', [
    'apiUrl'      => $apiUrl,
    'rateLimit'   => (int) ($config['api']['rate_limit_per_hour'] ?? 600),
    'available'   => Locale::available($config),
    'isAdmin'     => $isAdmin,
    'discordUrl'  => DiscordLink::inviteUrl($config),
    'donateUrl'   => DonateLink::url($config),
    'adsenseClient' => Adsense::clientId($config),
    'timezone'    => $timezone,
]);
