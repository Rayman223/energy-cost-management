<?php

declare(strict_types=1);

/**
 * Déconnexion : vide la session locale et redirige vers l'accueil.
 * (La déconnexion côté IdP — RP-initiated logout — pourra être ajoutée plus tard.)
 */

use App\Http\SecurityHeaders;
use App\Security\AuthSession;
use App\Security\Csrf;
use App\Security\WebAccessGuard;

$config = require __DIR__ . '/../../bootstrap.php';

SecurityHeaders::send();

$security = $config['web_security'] ?? [];
if (is_array($security)) {
    WebAccessGuard::enforceIp($security);
}

$home = WebAccessGuard::appRootPath() . '/';

// La déconnexion mute l'état de session : exiger POST + CSRF. SameSite=Lax
// n'empêche pas une navigation top-level cross-site (un simple lien / <img>
// ne suffit plus : seul un formulaire de l'app portant le jeton passe).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Csrf::validate($_POST[Csrf::FIELD] ?? null) === false) {
    header('Location: ' . $home, true, 303);
    exit;
}

AuthSession::logout();
// Mode Basic Auth (form-based, sans OIDC) : la déconnexion doit aussi retirer le
// jeton de session `web_auth`, sinon AuthGuard::protect maintiendrait la session.
WebAccessGuard::forgetSession();

header('Location: ' . $home, true, 303);
exit;
