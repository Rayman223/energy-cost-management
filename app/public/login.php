<?php

declare(strict_types=1);

use App\Security\WebAccessGuard;

$config = require __DIR__ . '/../bootstrap.php';


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
    ],
    'en' => [
        'title' => 'Secure sign in',
        'heading' => 'Authentication required',
        'username' => 'Username',
        'password' => 'Password',
        'submit' => 'Sign in',
        'error' => 'Invalid credentials.',
    ],
];

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? $appRoot);
if ($next === '' || str_starts_with($next, '/') === false || str_starts_with($next, '//') || str_contains($next, "\r") || str_contains($next, "\n")) {
    $next = $appRoot;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (WebAccessGuard::authenticateForm($security, $username, $password)) {
        header('Location: ' . $next, true, 302);
        exit;
    }

    $error = $messages[$lang]['error'];
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($messages[$lang]['title']) ?></title>
  <link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/login.css') ?>">
</head>
<body>
  <form class="box" method="post" action="<?= htmlspecialchars($basePath . '/login.php') ?>">
    <h1><?= htmlspecialchars($messages[$lang]['heading']) ?></h1>

    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">

    <label for="username"><?= htmlspecialchars($messages[$lang]['username']) ?></label>
    <input id="username" name="username" autocomplete="username" required>

    <label for="password"><?= htmlspecialchars($messages[$lang]['password']) ?></label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit"><?= htmlspecialchars($messages[$lang]['submit']) ?></button>

    <?php if ($error !== ''): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
  </form>
</body>
</html>
