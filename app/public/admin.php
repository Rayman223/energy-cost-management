<?php

declare(strict_types=1);

/**
 * Espace administration (réservé aux comptes « admin ») : gestion des membres
 * de la communauté (rôle user/admin, statut actif/bloqué). Le catalogue
 * tarifaire partagé se gère depuis la page Tarifs (grilles « partagées »).
 * Session uniquement.
 */

use App\Http\SecurityHeaders;
use App\Http\UploadLimits;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\Import\ImportRunner;
use App\Service\Import\ImportTarget;
use App\Support\LocaleContext;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();
AuthGuard::protect($config);

$db     = new Database($config['database']);
$pdo    = $db->pdo();
$userId = UserContext::currentWebUserId($pdo, $config);

$users = new UserRepository($pdo);

// Locale (profil, surchargée par ?lang valide) → View configurée.
$profile = $users->getProfile($userId);
$view = LocaleContext::viewFor($config, $users, $userId, $profile['locale'] ?? null, __DIR__ . '/../templates');

// ── Garde admin : seuls les administrateurs accèdent à cette page ────────────
$me = $users->findById($userId);
if ($me === null || $me->isAdmin() === false) {
    http_response_code(403);
    echo $view->render('error', ['code' => 403, 'message' => $view->t('admin.forbidden')]);

    return;
}

$error        = null;
$success      = null;
$importReport = null;

// ── Traitement des actions (POST, protégées par CSRF) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Upload > post_max_size : PHP vide $_POST/$_FILES → sans ce garde, le
        // rejet CSRF masquerait la vraie cause (message trompeur). À tester AVANT.
        if (UploadLimits::postExceededLimit($_SERVER, $_POST, $_FILES)) {
            throw new \RuntimeException($view->t('import.file_too_large'));
        }
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'import') {
            // Import « pour le compte d'un autre » : réservé admin (toute cette
            // page l'est déjà) ; ImportTarget verrouille la règle. La cible peut
            // aussi être soi-même. La cible choisie doit exister.
            // Cible explicite : si le champ est fourni, il DOIT être un id valide
            // (sinon on rejette, plutôt que de retomber silencieusement sur soi).
            $rawTarget = $_POST['target_user_id'] ?? '';
            $requested = null;
            if (is_string($rawTarget) && $rawTarget !== '') {
                $requested = filter_var($rawTarget, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($requested === false) {
                    throw new \InvalidArgumentException($view->t('admin.invalid_user'));
                }
            }
            $targetId = ImportTarget::resolve($userId, $me->isAdmin(), $requested);
            if ($users->findById($targetId) === null) {
                throw new \InvalidArgumentException($view->t('admin.invalid_user'));
            }

            $importReport = (new ImportRunner())->runFromRequest($pdo, $targetId, $_POST, $_FILES);
            // Import tronqué (plafond atteint, données perdues) : pas de bannière
            // « terminé » trompeuse — l'avertissement du rapport tient lieu de signal.
            if ($importReport->truncated() === false) {
                $success = $view->t(($_POST['dry_run'] ?? '') === '1' ? 'import.done_dryrun' : 'import.done');
            }
        } else {
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
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

echo $view->render('admin', [
    'error'        => $error,
    'success'      => $success,
    'users'        => $users->listAll(),
    'currentId'    => $userId,
    'available'    => Locale::available($config),
    'importReport' => $importReport,
]);
