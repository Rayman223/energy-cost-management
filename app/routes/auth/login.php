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
 *
 * GitHub (#24) emprunte la même route mais un connecteur OAuth 2.0 distinct
 * ({@see \App\Security\OAuth\GithubOAuthClient}) : il n'expose aucune découverte
 * OIDC ni d'id_token.
 */

use App\Http\SecurityHeaders;
use App\I18n\LocaleResolver;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Repository\UserIdentityRepository;
use App\Security\AccountProvisioner;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\IdentityLinker;
use App\Security\OAuth\GithubOAuthClient;
use App\Security\Oidc\OidcAuthFailure;
use App\Security\Oidc\OidcClientFactory;
use App\Security\Oidc\OidcDiscoveryCache;
use App\Security\Oidc\OidcDisplayName;
use App\Security\Oidc\OidcSessionState;
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

        // Nouvelle demande d'autorisation : oublier le code_verifier PKCE d'un flux
        // antérieur, que la lib ne nettoie jamais. Sans cela il est envoyé au
        // fournisseur suivant, qui n'a pourtant reçu aucun code_challenge, et
        // l'échange du code est refusé (#25).
        OidcSessionState::forgetCodeVerifier();

        // Mode liaison (#137) : un utilisateur déjà connecté rattache un second
        // fournisseur. L'id lié provient de la session authentifiée — jamais du
        // client — et la liaison ne se conclut qu'avec une preuve OIDC complète.
        // On repart d'un état propre à chaque initiation (pas de flag résiduel).
        if (($_GET['link'] ?? '') === '1' && AuthSession::userId() !== null) {
            $_SESSION['auth_link_user_id'] = AuthSession::userId();
        } else {
            unset($_SESSION['auth_link_user_id']);
        }
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

    if (GithubOAuthClient::supports($providerConfig)) {
        // GitHub n'est pas un IdP OpenID Connect : pas de découverte, pas
        // d'id_token — la lib jumbojett ne peut rien en faire. Connecteur OAuth 2.0
        // dédié (#24), qui rend le même couple (sub, nom d'affichage) et laisse tout
        // l'aval — provisionnement, liaison, journalisation d'échec — inchangé.
        $identity = (new GithubOAuthClient())->authenticate(
            $providerConfig,
            $isCallback,
            (string) $providerConfig['redirect_uri'],
        );
        $sub = $identity['sub'];
        $displayName = $identity['name'];
    } else {
        $oidc = OidcClientFactory::fromConfig($providerConfig);
        $oidc->authenticate(); // 1er appel : redirige vers l'IdP. Retour : valide le code.

        $sub = $oidc->getVerifiedClaims('sub');
        $sub = is_string($sub) ? $sub : '';
        if ($sub === '') {
            throw new OpenIDConnectClientException('Claim "sub" manquant.');
        }

        // Nom d'affichage : d'abord l'id_token (Google/Microsoft y mettent « name »),
        // et seulement s'il n'apporte rien, un appel au userinfo — Discord n'expose
        // « preferred_username »/« nickname » que là (cf. app/docs/oidc-discord.md).
        $verifiedClaims = $oidc->getVerifiedClaims();
        $displayName = OidcDisplayName::fromClaims(is_object($verifiedClaims) ? $verifiedClaims : null);
        if ($displayName === '') {
            try {
                $userInfo = $oidc->requestUserInfo();
                $displayName = OidcDisplayName::fromClaims(null, is_object($userInfo) ? $userInfo : null);
            } catch (\Throwable) {
                // Le nom d'affichage est optionnel.
            }
        }
    }

    $issuer = (string) ($providerConfig['issuer'] ?? '');

    if (!is_array($config['database'] ?? null)) {
        throw new \RuntimeException('Database configuration missing.');
    }
    $database = new Database($config['database']);
    $pdo = $database->pdo();

    // Mode liaison (#137). La clé de config (google, microsoft…) est la valeur
    // stockée en base — plus fiable que providerLabel() pour un IdP auto-hébergé.
    $sessionUserId = AuthSession::userId();
    $linkFlag = isset($_SESSION['auth_link_user_id']) && is_int($_SESSION['auth_link_user_id'])
        ? $_SESSION['auth_link_user_id']
        : null;

    // Dès qu'une liaison est en cours, on NE provisionne JAMAIS un nouveau compte
    // dans ce callback : si la session a changé/expiré entre le clic « lier » et
    // le retour de l'IdP (ex. déconnexion dans un autre onglet), retomber sur
    // provision() créerait le doublon que la liaison visait justement à éviter.
    if ($linkFlag !== null) {
        unset($_SESSION['auth_link_user_id'], $_SESSION['auth_oidc_provider'], $_SESSION['auth_next']);
        $account = WebAccessGuard::appRootPath() . '/account';

        if ($linkFlag !== $sessionUserId) {
            // Session incohérente avec l'intention de liaison → abandon propre.
            header('Location: ' . $account . '?link_error=aborted', true, 302);
            exit;
        }

        $status = (new IdentityLinker(new UserIdentityRepository($pdo)))
            ->link($sessionUserId, $issuer, $sub, $key);
        if ($status === IdentityLinker::TAKEN_BY_OTHER) {
            $target = $account . '?link_error=taken';
        } else {
            $target = $account . '?linked=' . rawurlencode($key);
            if ($status === IdentityLinker::ALREADY_LINKED_SELF) {
                $target .= '&already=1';
            }
        }

        header('Location: ' . $target, true, 302);
        exit;
    }

    $provisioner = new AccountProvisioner(new UserRepository($pdo));
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

    // Journaliser la cause : la page ci-dessous reste muette pour le visiteur,
    // mais l'admin doit pouvoir diagnostiquer (#25). La référence relie l'écran
    // à la ligne de log.
    $reference = OidcAuthFailure::reference();
    error_log(OidcAuthFailure::describe(
        $reference,
        $e,
        isset($key) && is_string($key) ? $key : '',
        isset($isCallback) && $isCallback === true,
    ));

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

    $title = htmlspecialchars($translator->t('auth.error'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $hint = htmlspecialchars(
        $translator->t('auth.error_reference', ['reference' => $reference]),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );

    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $title
        . '</title></head><body><p>' . $title
        . '</p><p><small>' . $hint . '</small>'
        . '</p><p><a href="' . htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">&larr;</a></p></body></html>';
    exit;
}
