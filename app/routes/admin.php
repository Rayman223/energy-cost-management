<?php

declare(strict_types=1);

/**
 * Espace administration (réservé aux comptes « admin ») : gestion des membres
 * de la communauté (rôle user/admin, statut actif/bloqué). Le catalogue
 * tarifaire partagé se gère depuis la page Tarifs (grilles « partagées »).
 * Session uniquement.
 */

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Support\DiscordLink;
use App\Support\LocaleContext;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6). bootstrap.php
// charge l'autoloader avant de valider la config, donc SecurityHeaders reste
// disponible dans le catch pour poser les en-têtes de sécurité sur l'erreur.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service indisponible : configuration manquante.';

    return;
}

SecurityHeaders::send();
AuthGuard::protect($config);

$db     = new Database($config['database']);
$pdo    = $db->pdo();
$userId = UserContext::currentWebUserId($pdo, $config);

$users = new UserRepository($pdo);

// Locale (profil, surchargée par ?lang valide) → View configurée.
$profile = $users->getProfile($userId);
$view = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

// ── Garde admin : seuls les administrateurs accèdent à cette page ────────────
$me = $users->findById($userId);
if ($me === null || $me->isAdmin() === false) {
    http_response_code(403);
    echo $view->render('error', ['code' => 403, 'message' => $view->t('admin.forbidden')]);

    return;
}

$error   = null;
$success = null;

// ── Traitement des actions (POST, protégées par CSRF) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        $action = $_POST['action'] ?? '';

        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetId === false) {
            throw new \InvalidArgumentException($view->t('admin.invalid_user'));
        }

        // Garde-fou anti-verrouillage : un admin ne peut ni se rétrograder ni se
        // bloquer lui-même (il perdrait l'accès à cette page).
        if ($targetId === $userId) {
            throw new \RuntimeException($view->t('admin.no_self'));
        }

        if ($action === 'set_role') {
            $role = $_POST['role'] ?? '';
            if ($users->setRole($targetId, $role) === false) {
                throw new \InvalidArgumentException($view->t('admin.invalid_role'));
            }
            $success = $view->t('admin.role_updated');
        } elseif ($action === 'set_status') {
            $status = $_POST['status'] ?? '';
            if ($users->setStatus($targetId, $status) === false) {
                throw new \InvalidArgumentException($view->t('admin.invalid_status'));
            }
            $success = $view->t('admin.status_updated');
        } else {
            throw new \InvalidArgumentException($view->t('common.action_unknown'));
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

echo $view->render('admin', [
    'oidcEnabled' => AuthGuard::isOidcEnabled($config),
    'discordUrl'  => DiscordLink::inviteUrl($config),
    'error'     => $error,
    'success'   => $success,
    'users'     => $users->listAll(),
    'currentId' => $userId,
    'available' => Locale::available($config),
    // Fuseau BRUT du profil pour l'horloge (null ⇒ repli navigateur).
    'timezone'  => $profile->timezone ?? null,
]);
