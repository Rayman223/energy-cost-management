<?php

declare(strict_types=1);

/**
 * Page « Mon compte » (self-service) : profil, jetons API, intégration EnergyID
 * (opt-in BE/NL), et RGPD (export + suppression). Session uniquement.
 */

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\ApiTokenRepository;
use App\Repository\EnergyIdIntegrationRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\AccountDataExporter;
use App\Service\AccountEraser;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();
AuthGuard::protect($config);

$db     = new Database($config['database']);
$pdo    = $db->pdo();
$userId = UserContext::currentWebUserId($pdo, $config);

$users        = new UserRepository($pdo);
$tokensRepo   = new ApiTokenRepository($pdo);
$energyIdRepo = new EnergyIdIntegrationRepository($pdo);

// Locale = celle du profil (surchargée par ?lang) → View configurée.
$profileForLocale = $users->getProfile($userId);
$locale = Locale::resolve($config, $profileForLocale['locale'] ?? null);
// Persiste un switch explicite (?lang) valide dans le profil, seulement s'il diffère.
$choice = Locale::explicitChoice($config);
if ($choice !== null && $choice !== ($profileForLocale['locale'] ?? null)) {
    $users->setLocale($userId, $choice);
}
$view   = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

$error      = null;
$success    = null;
$freshToken = null; // secret affiché une seule fois

// deviceId EnergyID dérivé de l'utilisateur (non secret).
$deviceBase = (string) ($config['energyid']['device']['deviceId'] ?? 'manage-energy');
$deviceId   = $deviceBase . '-u' . $userId;

// ── Export RGPD (téléchargement JSON) — court-circuite le rendu HTML ─────────
if (($_GET['export'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mes-donnees-energie.json"');
    (new AccountDataExporter($pdo))->stream($userId);
    exit;
}

// ── Traitement des actions (POST) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_profile') {
            $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
            $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new \InvalidArgumentException($view->t('account.invalid_currency'));
            }

            $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Brussels'));
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException($view->t('account.invalid_timezone'));
            }

            $chosenLocale = strtolower(substr(trim((string) ($_POST['locale'] ?? 'fr')), 0, 5));
            $available = Locale::available($config);
            if (!in_array($chosenLocale, $available, true)) {
                $chosenLocale = (string) ($config['i18n']['default_locale'] ?? 'fr');
            }

            $zone = trim((string) ($_POST['bidding_zone'] ?? '')) ?: null;

            $users->updateProfile($userId, $country, $timezone, $currency, $zone, $chosenLocale);
            $success = $view->t('account.profile_saved');
        } elseif ($action === 'token_create') {
            $name = trim((string) ($_POST['token_name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException($view->t('account.token_name_required'));
            }
            $created    = $tokensRepo->create($userId, $name);
            $freshToken = $created['token'];
            $success    = $view->t('account.token_created');
        } elseif ($action === 'token_revoke') {
            $id = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $tokensRepo->revoke($id, $userId) === false) {
                throw new \RuntimeException($view->t('account.token_not_found'));
            }
            $success = $view->t('account.token_revoked_ok');
        } elseif ($action === 'energyid_enable') {
            $energyIdRepo->enable($userId, $deviceId);
            $success = $view->t('account.energyid_enabled_msg');
        } elseif ($action === 'energyid_disable') {
            $energyIdRepo->disable($userId);
            $success = $view->t('account.energyid_disabled_msg');
        } elseif ($action === 'delete_account') {
            if (($_POST['confirm'] ?? '') !== 'SUPPRIMER') {
                throw new \InvalidArgumentException($view->t('account.delete_need_confirm'));
            }
            (new AccountEraser($pdo))->erase($userId);
            \App\Security\AuthSession::logout();
            header('Location: ' . \App\Security\WebAccessGuard::appRootPath() . '/', true, 302);
            exit;
        } else {
            throw new \InvalidArgumentException($view->t('common.action_unknown'));
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Données pour l'affichage ────────────────────────────────────────────────
$user     = $users->findById($userId);
$profile  = $users->getProfile($userId) ?? [
    'country' => null, 'timezone' => 'Europe/Brussels', 'currency' => 'EUR', 'bidding_zone' => null, 'locale' => 'fr',
];
$tokens   = $tokensRepo->listForUser($userId);
$energyId = $energyIdRepo->get($userId);

echo $view->render('account', [
    'error'      => $error,
    'success'    => $success,
    'freshToken' => $freshToken,
    'user'       => $user,
    'profile'    => $profile,
    'tokens'     => $tokens,
    'energyId'   => $energyId,
    'deviceId'   => $deviceId,
    'available'  => Locale::available($config),
]);
