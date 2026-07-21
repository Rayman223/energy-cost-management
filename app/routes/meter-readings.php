<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\AuthGuard;
use App\Security\UserContext;
use App\Support\DiscordLink;
use App\Support\LocaleContext;
use App\View\ViewFactory;

$config  = [];
$view    = null;
$dbError = null;
$gasLatest = null;
$waterLatest = null;
$timezone = 'Europe/Brussels';

require_once __DIR__ . '/../../vendor/autoload.php';

SecurityHeaders::send();

try {
    $config = require __DIR__ . '/../bootstrap.php';

    AuthGuard::protect($config);

    $db     = new Database($config['database']);
    $pdo    = $db->pdo();
    $userId = UserContext::currentWebUserId($pdo, $config);
    $users  = new UserRepository($pdo);
    $profile = $users->getProfile($userId);
    $timezone = $profile->timezone ?? 'Europe/Brussels';
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
    'dbError' => $dbError,
    'gasLatest' => $gasLatest,
    'waterLatest' => $waterLatest,
    'available' => Locale::available($config),
    'timezone' => $timezone,
]);
