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
  <style>
    body { font-family: Arial, sans-serif; background: #101826; color: #e9eef8; margin: 0; display: grid; place-items: center; min-height: 100vh; }
    .box { width: min(420px, 90vw); background: #182337; border-radius: 12px; padding: 24px; box-shadow: 0 8px 26px rgba(0,0,0,.35); }
    h1 { margin-top: 0; font-size: 1.3rem; }
    label { display: block; margin-top: 12px; margin-bottom: 6px; }
    input { width: 100%; box-sizing: border-box; border: 1px solid #2f3f5f; border-radius: 8px; background: #0f1726; color: #e9eef8; padding: 10px; }
    button { margin-top: 16px; width: 100%; border: none; border-radius: 8px; background: #2f87ff; color: #fff; padding: 10px; cursor: pointer; font-weight: 700; }
    .error { margin-top: 10px; color: #ff9da6; }
  </style>
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
