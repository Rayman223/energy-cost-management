<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Security\Csrf;
use App\Security\WebAccessGuard;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/login.php'));
$basePath = str_replace('\\', '/', dirname($scriptName));
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}

$appRoot = $basePath === '' ? '/' : ($basePath . '/');
$security = $config['web_security'] ?? [];
WebAccessGuard::protect($security);

$securityEnabled = (bool) ($security['enabled'] ?? false);
$basicEnabled = (bool) ($security['basic_auth']['enabled'] ?? false);
if ($securityEnabled === false || $basicEnabled === false) {
    header('Location: ' . $appRoot, true, 302);
    exit;
}

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? $appRoot);
if ($next === '' || str_starts_with($next, '/') === false || str_starts_with($next, '//') || str_contains($next, "\r") || str_contains($next, "\n")) {
    $next = $appRoot;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
        $error = $view->t('auth.csrf');
    } else {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (WebAccessGuard::authenticateForm($security, $username, $password)) {
            header('Location: ' . $next, true, 302);
            exit;
        }

        $error = $view->t('auth.invalid');
    }
}

echo $view->render('login', [
    'error'     => $error,
    'next'      => $next,
    'basePath'  => $basePath,
    'available' => Locale::available($config),
]);
