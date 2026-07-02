<?php

declare(strict_types=1);

/**
 * Connexion OpenID Connect — sert à la fois d'initiateur (redirige vers l'IdP)
 * et de callback (redirect_uri pointe sur ce fichier). À la 1re connexion, le
 * compte est auto-créé (inscription ouverte).
 */

use App\Http\SecurityHeaders;
use App\I18n\LocaleResolver;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\AccountProvisioner;
use App\Security\AuthSession;
use App\Security\Oidc\OidcClientFactory;
use App\Security\Session;
use App\Security\WebAccessGuard;
use Jumbojett\OpenIDConnectClientException;

$config = require __DIR__ . '/../../bootstrap.php';

SecurityHeaders::send();

$security = $config['web_security'] ?? [];
if (is_array($security)) {
    WebAccessGuard::enforceIp($security);
}

$home = WebAccessGuard::appRootPath() . '/';

$oidcConfig = $config['oidc'] ?? [];
if (!is_array($oidcConfig) || ($oidcConfig['enabled'] ?? false) !== true) {
    header('Location: ' . $home, true, 302);
    exit;
}

Session::start();

// Cible de redirection post-connexion (protégée contre l'open redirect).
$next = (string) ($_GET['next'] ?? $_SESSION['auth_next'] ?? $home);
if (
    $next === ''
    || str_starts_with($next, '/') === false
    || str_starts_with($next, '//')
    || str_contains($next, "\r")
    || str_contains($next, "\n")
) {
    $next = $home;
}
$_SESSION['auth_next'] = $next;

// redirect_uri : si non configuré, le dériver en honorant X-Forwarded-Proto
// (SWAG termine le TLS) pour éviter un callback http:// rejeté par l'IdP.
if (($oidcConfig['redirect_uri'] ?? '') === '') {
    $scheme = Session::isHttps() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/login.php');
    $oidcConfig['redirect_uri'] = $scheme . '://' . $host . $script;
}

try {
    $oidc = OidcClientFactory::fromConfig($oidcConfig);
    $oidc->authenticate(); // 1er appel : redirige vers l'IdP. Retour : valide le code.

    $sub = $oidc->getVerifiedClaims('sub');
    $sub = is_string($sub) ? $sub : '';
    if ($sub === '') {
        throw new OpenIDConnectClientException('Claim "sub" manquant.');
    }

    $displayName = '';
    $claimName = $oidc->getVerifiedClaims('name');
    if (is_string($claimName) && $claimName !== '') {
        $displayName = $claimName;
    } else {
        try {
            $infoName = $oidc->requestUserInfo('name');
            if (is_string($infoName)) {
                $displayName = $infoName;
            }
        } catch (\Throwable) {
            // Le nom d'affichage est optionnel.
        }
    }

    $issuer = (string) ($oidcConfig['issuer'] ?? '');
    $provider = OidcClientFactory::providerLabel($issuer);

    $database = new Database($config['database']);
    $provisioner = new AccountProvisioner(new UserRepository($database->pdo()));
    $user = $provisioner->provision($issuer, $sub, $provider, $displayName);

    if ($user->isActive() === false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Compte bloqué.';
        exit;
    }

    AuthSession::login($user->id);
    unset($_SESSION['auth_next']);

    header('Location: ' . $next, true, 302);
    exit;
} catch (\Throwable $e) {
    $i18n = $config['i18n'] ?? [];
    $available = ['fr', 'en'];
    $default = 'fr';
    if (is_array($i18n)) {
        if (isset($i18n['available']) && is_array($i18n['available'])) {
            $tmp = [];
            foreach ($i18n['available'] as $loc) {
                if (is_string($loc)) {
                    $tmp[] = $loc;
                }
            }
            if ($tmp !== []) {
                $available = $tmp;
            }
        }
        if (isset($i18n['default_locale']) && is_string($i18n['default_locale'])) {
            $default = $i18n['default_locale'];
        }
    }

    $queryLang = is_string($_GET['lang'] ?? null) ? (string) $_GET['lang'] : null;
    $accept = is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    $locale = LocaleResolver::resolve(null, $queryLang, $accept, $available, $default);

    $translator = new Translator(__DIR__ . '/../../translations', $locale, $default);

    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars($translator->t('auth.error'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</title></head><body><p>'
        . htmlspecialchars($translator->t('auth.error'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><p><a href="' . htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">&larr;</a></p></body></html>';
    exit;
}
