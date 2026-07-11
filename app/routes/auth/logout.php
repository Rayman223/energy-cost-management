<?php

declare(strict_types=1);

/**
 * Déconnexion : vide la session locale et redirige vers l'accueil.
 * (La déconnexion côté IdP — RP-initiated logout — pourra être ajoutée plus tard.)
 */

use App\Http\SecurityHeaders;
use App\Security\AuthSession;
use App\Security\WebAccessGuard;

$config = require __DIR__ . '/../../bootstrap.php';

SecurityHeaders::send();

$security = $config['web_security'] ?? [];
if (is_array($security)) {
    WebAccessGuard::enforceIp($security);
}

AuthSession::logout();

header('Location: ' . WebAccessGuard::appRootPath() . '/', true, 302);
exit;
