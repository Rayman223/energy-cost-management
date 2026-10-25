<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\SupportRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\WebAccessGuard;
use App\Seo\PageMeta;
use App\Support\SupportConfig;
use App\Support\SupportLink;
use App\Support\SupportReference;
use App\Support\DiscordLink;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';
SecurityHeaders::send($config);
WebAccessGuard::enforceIp($config['web_security'] ?? []);
if (!SupportConfig::enabled($config) || !AuthGuard::isOidcEnabled($config)) {
    http_response_code(404);
    echo 'Not Found';
    return;
}

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));
$year = (int) gmdate('Y');
$mode = SupportConfig::stripeMode($config);
$goal = max(0, (int) ($config['support']['annual_goal_cents'] ?? 0));
$githubYear = (int) ($config['support']['github_sponsors_year'] ?? 0);
$githubAmount = $githubYear === $year ? max(0, (int) ($config['support']['github_sponsors_annual_cents'] ?? 0)) : 0;
$summary = ['stripe_cents' => 0, 'supporters' => []];
$dbAvailable = true;
$member = ['badge' => false, 'public' => false];
$isAdmin = false;
$loggedIn = false;
$userId = AuthSession::userId();
try {
    $pdo = (new Database($config['database']))->pdo();
    $repo = new SupportRepository($pdo);
    $summary = $repo->publicSummary($year, $mode);
    if ($userId !== null) {
        $user = (new UserRepository($pdo))->findById($userId);
        if ($user?->isActive()) { $member = $repo->member($userId, $mode); $isAdmin = $user->isAdmin(); $loggedIn = true; }
    }
} catch (Throwable) {
    $dbAvailable = false;
    $summary = ['stripe_cents' => 0, 'supporters' => []];
}
$raised = $summary['stripe_cents'] + $githubAmount;
$link = SupportConfig::paymentLink($config);
if ($link !== null && $loggedIn && $userId !== null && $dbAvailable) {
    $reference = SupportReference::forUser($userId, (string) ($config['support']['reference_signing_key'] ?? ''));
    if ($reference !== null) {
        $link = SupportConfig::paymentLinkForAccount($link, $reference);
    }
}
$meta = PageMeta::indexable($config, 'support', $locale, $view->t('support.meta'));
echo $view->render('support', [
    'title' => $view->t('support.title'), 'available' => Locale::available($config),
    'discordUrl' => DiscordLink::inviteUrl($config), 'donateUrl' => SupportLink::url($config), 'meta' => $meta,
    'raised' => $raised, 'goal' => $goal, 'stripeCents' => $summary['stripe_cents'],
    'githubCents' => $githubAmount, 'year' => $year, 'supporters' => $summary['supporters'],
    'dbAvailable' => $dbAvailable, 'member' => $member, 'loggedIn' => $loggedIn, 'isAdmin' => $isAdmin,
    'stripeUrl' => $link, 'githubUrl' => SupportConfig::githubUrl($config),
    'csrfField' => App\Security\Csrf::field(),
]);
