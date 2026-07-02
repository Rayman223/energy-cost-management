<?php

declare(strict_types=1);

/**
 * Page « Mon compte » (self-service) : profil, jetons API, intégration EnergyID
 * (opt-in BE/NL), et RGPD (export + suppression). Session uniquement.
 */

use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Repository\ApiTokenRepository;
use App\Repository\EnergyIdIntegrationRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\AccountDataExporter;
use App\Service\AccountEraser;
use App\View\View;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();
AuthGuard::protect($config);

$db     = new Database($config['database']);
$pdo    = $db->pdo();
$userId = UserContext::currentWebUserId($pdo, $config);

$users        = new UserRepository($pdo);
$tokensRepo   = new ApiTokenRepository($pdo);
$energyIdRepo = new EnergyIdIntegrationRepository($pdo);

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
            throw new \RuntimeException('Requête invalide (jeton CSRF manquant ou expiré). Veuillez réessayer.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_profile') {
            $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
            $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new \InvalidArgumentException('Devise invalide (code ISO 4217, ex. EUR).');
            }

            $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Brussels'));
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException('Fuseau horaire invalide.');
            }

            $locale = strtolower(substr(trim((string) ($_POST['locale'] ?? 'fr')), 0, 5));
            $available = is_array($config['i18n']['available'] ?? null) ? $config['i18n']['available'] : ['fr', 'en'];
            if (!in_array($locale, $available, true)) {
                $locale = (string) ($config['i18n']['default_locale'] ?? 'fr');
            }

            $zone = trim((string) ($_POST['bidding_zone'] ?? '')) ?: null;

            $users->updateProfile($userId, $country, $timezone, $currency, $zone, $locale);
            $success = 'Profil enregistré.';
        } elseif ($action === 'token_create') {
            $name = trim((string) ($_POST['token_name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Nom du jeton requis.');
            }
            $created    = $tokensRepo->create($userId, $name);
            $freshToken = $created['token'];
            $success    = 'Jeton créé. Copiez-le maintenant : il ne sera plus affiché.';
        } elseif ($action === 'token_revoke') {
            $id = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $tokensRepo->revoke($id, $userId) === false) {
                throw new \RuntimeException('Jeton introuvable ou déjà révoqué.');
            }
            $success = 'Jeton révoqué.';
        } elseif ($action === 'energyid_enable') {
            $energyIdRepo->enable($userId, $deviceId);
            $success = 'EnergyID activé. La synchronisation quotidienne fournira un code de claim à saisir dans votre compte EnergyID.';
        } elseif ($action === 'energyid_disable') {
            $energyIdRepo->disable($userId);
            $success = 'EnergyID désactivé.';
        } elseif ($action === 'delete_account') {
            if (($_POST['confirm'] ?? '') !== 'SUPPRIMER') {
                throw new \InvalidArgumentException('Tapez SUPPRIMER pour confirmer la suppression.');
            }
            (new AccountEraser($pdo))->erase($userId);
            \App\Security\AuthSession::logout();
            header('Location: ' . \App\Security\WebAccessGuard::appRootPath() . '/', true, 302);
            exit;
        } else {
            throw new \InvalidArgumentException('Action inconnue.');
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

echo (new View(__DIR__ . '/../templates'))->render('account', [
    'error'      => $error,
    'success'    => $success,
    'freshToken' => $freshToken,
    'user'       => $user,
    'profile'    => $profile,
    'tokens'     => $tokens,
    'energyId'   => $energyId,
    'deviceId'   => $deviceId,
]);
