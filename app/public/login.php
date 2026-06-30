<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Security\Csrf;
use App\Security\WebAccessGuard;
use App\View\View;

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

$lang = strtolower((string) ($_GET['lang'] ?? $_POST['lang'] ?? ''));
if ($lang !== 'fr' && $lang !== 'en') {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $lang = (str_starts_with($accept, 'fr') || str_contains($accept, ',fr')) ? 'fr' : 'en';
}

$messages = [
    'fr' => [
        'title' => 'Connexion sécurisée',
        'heading' => 'Authentification requise',
        'username' => 'Nom d\'utilisateur',
        'password' => 'Mot de passe',
        'submit' => 'Se connecter',
        'error' => 'Identifiants invalides.',
        'csrf' => 'Session expirée, veuillez réessayer.',
    ],
    'en' => [
        'title' => 'Secure sign in',
        'heading' => 'Authentication required',
        'username' => 'Username',
        'password' => 'Password',
        'submit' => 'Sign in',
        'error' => 'Invalid credentials.',
        'csrf' => 'Session expired, please try again.',
    ],
];

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? $appRoot);
if ($next === '' || str_starts_with($next, '/') === false || str_starts_with($next, '//') || str_contains($next, "\r") || str_contains($next, "\n")) {
    $next = $appRoot;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
        $error = $messages[$lang]['csrf'];
    } else {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (WebAccessGuard::authenticateForm($security, $username, $password)) {
            header('Location: ' . $next, true, 302);
            exit;
        }

        $error = $messages[$lang]['error'];
    }
}

$view = new View(__DIR__ . '/../templates');

echo $view->render('login', [
    'lang'     => $lang,
    't'        => $messages[$lang],
    'error'    => $error,
    'next'     => $next,
    'basePath' => $basePath,
]);
