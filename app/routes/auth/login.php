<?php

declare(strict_types=1);

/**
 * Connexion OpenID Connect — sert à la fois d'initiateur (redirige vers l'IdP)
 * et de callback (redirect_uri pointe sur ce fichier). À la 1re connexion, le
 * compte est auto-créé (inscription ouverte).
 *
 * Multi-fournisseurs : le fournisseur est choisi à l'initiation via `?provider=`
 * puis mémorisé en session (`auth_oidc_provider`) et relu au callback — le
 * redirect_uri reste unique et sans query (contrainte Microsoft/Google + routeur
 * à correspondance exacte).
 */

use App\Http\SecurityHeaders;
use App\I18n\LocaleResolver;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\AccountProvisioner;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\Oidc\OidcClientFactory;
use App\Security\Oidc\OidcDiscoveryCache;
use App\Security\Session;
use App\Security\WebAccessGuard;
use App\Support\SafeRedirect;
use App\Support\Url;
use Jumbojett\OpenIDConnectClientException;

$config = require __DIR__ . '/../../bootstrap.php';

SecurityHeaders::send();

$security = $config['web_security'] ?? [];
if (is_array($security)) {
    WebAccessGuard::enforceIp($security);
}

$home = WebAccessGuard::appRootPath() . '/';

$oidcConfig = $config['oidc'] ?? [];
if (!is_array($oidcConfig) || AuthGuard::isOidcEnabled($config) === false) {
    header('Location: ' . $home, true, 302);
    exit;
}

Session::start();

// Cible de redirection post-connexion (protégée contre l'open redirect).
$next = SafeRedirect::sanitize((string) ($_GET['next'] ?? $_SESSION['auth_next'] ?? $home), $home);
$_SESSION['auth_next'] = $next;

try {
    $providers = OidcClientFactory::providersFromConfig($oidcConfig);
    if ($providers === []) {
        header('Location: ' . $home, true, 302);
        exit;
    }

    // Résolution du fournisseur. Au callback, l'IdP ne renvoie ni ?provider ni
    // query personnalisée : on relit la clé mémorisée à l'initiation.
    $isCallback = isset($_GET['code']) || isset($_GET['error']) || isset($_GET['state']);
    if ($isCallback) {
        $key = (string) ($_SESSION['auth_oidc_provider'] ?? '');
        if (!isset($providers[$key])) {
            throw new \RuntimeException('OIDC callback without provider context');
        }
    } else {
        $key = is_string($_GET['provider'] ?? null) ? (string) $_GET['provider'] : '';
        if (!isset($providers[$key])) {
            if (count($providers) === 1) {
                $key = (string) array_key_first($providers); // rétro-compat bookmarks
            } else {
                // Choix ambigu : renvoyer vers la page de sélection brandée.
                header('Location: ' . Url::to('login') . '?next=' . rawurlencode($next), true, 302);
                exit;
            }
        }
        $_SESSION['auth_oidc_provider'] = $key;
    }
    $providerConfig = $providers[$key];

    // redirect_uri : si non configuré, le dériver en honorant X-Forwarded-Proto
    // (SWAG termine le TLS) pour éviter un callback http:// rejeté par l'IdP.
    if ((string) ($providerConfig['redirect_uri'] ?? '') === '') {
        $scheme = Session::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Callback = la route « /auth/login » (le front controller #106 fait pointer
        // SCRIPT_NAME sur /index.php, inexploitable ici).
        $providerConfig['redirect_uri'] = $scheme . '://' . $host . WebAccessGuard::appRootPath() . '/auth/login';
    }

    $oidc = OidcClientFactory::fromConfig($providerConfig);
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

    $issuer = (string) ($providerConfig['issuer'] ?? '');

    if (!is_array($config['database'] ?? null)) {
        throw new \RuntimeException('Database configuration missing.');
    }
    $database = new Database($config['database']);
    $provisioner = new AccountProvisioner(new UserRepository($database->pdo()));
    // La clé de config (google, microsoft, keycloak…) est la valeur stockée en
    // base — plus fiable que providerLabel() pour un IdP auto-hébergé.
    $user = $provisioner->provision($issuer, $sub, $key, $displayName);

    if ($user->isActive() === false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Compte bloqué.';
        exit;
    }

    AuthSession::login($user->id);
    unset($_SESSION['auth_next'], $_SESSION['auth_oidc_provider']);

    header('Location: ' . $next, true, 302);
    exit;
} catch (\Throwable $e) {
    // Un endpoint déplacé côté IdP peut faire échouer l'auth avec une découverte
    // mise en cache périmée : on invalide l'entrée pour re-découvrir au prochain
    // essai plutôt que de rester bloqué jusqu'au TTL.
    if (isset($providerConfig) && is_array($providerConfig)) {
        $failedIssuer = (string) ($providerConfig['issuer'] ?? '');
        if ($failedIssuer !== '') {
            (new OidcDiscoveryCache())->invalidate($failedIssuer);
        }
    }

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
