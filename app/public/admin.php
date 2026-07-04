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
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();
AuthGuard::protect($config);

$db     = new Database($config['database']);
$pdo    = $db->pdo();
$userId = UserContext::currentWebUserId($pdo, $config);

$users = new UserRepository($pdo);

// Locale (profil, surchargée par ?lang valide) → View configurée.
$profile = $users->getProfile($userId);
$locale  = Locale::resolve($config, $profile['locale'] ?? null);
$choice  = Locale::explicitChoice($config);
if ($choice !== null && $choice !== ($profile['locale'] ?? null)) {
    $users->setLocale($userId, $choice);
}
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

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

        $action  = $_POST['action'] ?? '';
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
    'error'     => $error,
    'success'   => $success,
    'users'     => $users->listAll(),
    'currentId' => $userId,
    'available' => Locale::available($config),
]);
