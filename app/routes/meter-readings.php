<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\AuthGuard;
use App\Security\UserContext;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\LocaleContext;
use App\View\ViewFactory;

$config  = [];
$view    = null;
$dbError = null;
$gasLatest = null;
$waterLatest = null;
$timezone = 'UTC';
$isAdmin = false;

require_once __DIR__ . '/../../vendor/autoload.php';

// Bootstrap isolé : la CSP dépend de la configuration (publicité #185 ⇒ origines
// Google autorisées), elle est donc posée dès que celle-ci est connue et avant
// toute redirection d'AuthGuard. Config injoignable ⇒ $config vide ⇒ CSP stricte.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

SecurityHeaders::send($config);

try {
    if ($dbError !== null) {
        // Configuration injoignable : on n'écrase pas le message d'origine par
        // l'erreur en cascade qu'aurait produite l'accès à une config vide.
        throw new \RuntimeException($dbError);
    }

    AuthGuard::protect($config);

    $db     = new Database($config['database']);
    $pdo    = $db->pdo();
    $userId = UserContext::currentWebUserId($pdo, $config);
    $users  = new UserRepository($pdo);
    $profile = $users->getProfile($userId);
    $isAdmin = $users->findById($userId)?->isAdmin() ?? false;
    $timezone = $profile->timezone ?? 'UTC'; // repli UTC neutre si pas de profil
    $view   = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

    $gasRepo = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepo = new UtilityReadingRepository($pdo, $userId, 'water');
    $gasLatest = $gasRepo->getLatest();
    $waterLatest = $waterRepo->getLatest();
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

$view ??= ViewFactory::create(__DIR__ . '/../templates', Locale::resolve($config, null), (string) ($config['i18n']['default_locale'] ?? 'fr'));

echo $view->render('meter_readings', [
    'oidcEnabled' => AuthGuard::isOidcEnabled($config),
    'discordUrl'  => DiscordLink::inviteUrl($config),
    'adsenseClient' => Adsense::clientId($config),
    'isAdmin' => $isAdmin,
    'dbError' => $dbError,
    'gasLatest' => $gasLatest,
    'waterLatest' => $waterLatest,
    'available' => Locale::available($config),
    'timezone' => $timezone,
    // Fuseau BRUT du profil pour l'horloge (null ⇒ repli navigateur) ; $timezone
    // (défaut UTC) reste concret pour les dates du bloc meter-data.
    'clockTimezone' => $profile->timezone ?? null,
]);
