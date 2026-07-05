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
use App\Service\Import\ImportRunner;
use App\Service\Import\ImportTarget;
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

$error        = null;
$success      = null;
$importReport = null;

// ── Traitement des actions (POST, protégées par CSRF) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
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

            $type = strtolower(trim((string) ($_POST['energy_type'] ?? '')));
            $overrides = [];
            $tsCol = trim((string) ($_POST['ts_col'] ?? ''));
            if ($tsCol !== '') {
                $overrides['ts_col'] = $tsCol;
            }
            $valueCol = trim((string) ($_POST['value_col'] ?? ''));
            if ($valueCol !== '') {
                $overrides['value_col'] = $valueCol;
            }
            $dryRun = ($_POST['dry_run'] ?? '') === '1';

            $importReport = (new ImportRunner())->runUploaded(
                $pdo,
                $targetId,
                $type,
                $overrides,
                is_array($_FILES['import_file'] ?? null) ? $_FILES['import_file'] : [],
                $dryRun,
            );
            $success = $view->t($dryRun ? 'import.done_dryrun' : 'import.done');
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
