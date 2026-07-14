<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\Csrf;
use App\Security\Oidc\OidcClientFactory;
use App\Security\WebAccessGuard;
use App\Support\SafeRedirect;
use App\Support\Url;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

$appRoot = WebAccessGuard::appRootPath() . '/';
$security = $config['web_security'] ?? [];
if (!is_array($security)) {
    $security = [];
}

$next = SafeRedirect::sanitize((string) ($_GET['next'] ?? $_POST['next'] ?? $appRoot), $appRoot);

$oidc = $config['oidc'] ?? [];
$oidcEnabled = AuthGuard::isOidcEnabled($config);

// ── Mode OIDC : page d'atterrissage avec bouton fournisseur ──────────────────
if ($oidcEnabled) {
    // L'authentification est portée par la session (comme AuthGuard) : ici on
    // n'applique que l'allowlist IP, jamais le Basic Auth.
    WebAccessGuard::enforceIp($security);

    // Déjà connecté : inutile d'afficher la page, on repart vers la cible.
    if (AuthSession::userId() !== null) {
        header('Location: ' . $next, true, 302);
        exit;
    }

    // Un bouton par fournisseur activé (l'utilisateur choisit son IdP).
    $providers = [];
    foreach (OidcClientFactory::labelsFromConfig(is_array($oidc) ? $oidc : []) as $key => $label) {
        $providers[] = [
            'key'   => $key,
            'label' => $label,
            'href'  => Url::to('auth/login') . '?provider=' . rawurlencode($key) . '&next=' . rawurlencode($next),
        ];
    }

    $locale = Locale::resolve($config, null);
    $view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

    echo $view->render('login-oidc', [
        'next'      => $next,
        'providers' => $providers,
        'available' => Locale::available($config),
    ]);

    return;
}

// ── Mode historique : formulaire HTTP Basic Auth ─────────────────────────────
WebAccessGuard::protect($security);

$securityEnabled = (bool) ($security['enabled'] ?? false);
$basicEnabled = (bool) ($security['basic_auth']['enabled'] ?? false);
if ($securityEnabled === false || $basicEnabled === false) {
    header('Location: ' . $appRoot, true, 302);
    exit;
}

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

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
    'available' => Locale::available($config),
]);
