<?php

declare(strict_types=1);

/**
 * Page « Mon compte » (self-service) : profil, jetons API, connecteurs d'export
 * (opt-in par module, ex. EnergyID), et RGPD (export + suppression). Session uniquement.
 */

use App\Domain\Timezones;
use App\Domain\UserProfile;
use App\Http\SecurityHeaders;
use App\Http\UploadLimits;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Integration\ModuleRegistry;
use App\Repository\ApiTokenRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserIntegrationRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\IdentityUnlinker;
use App\Security\Oidc\OidcClientFactory;
use App\Security\UserContext;
use App\Service\AccountDataExporter;
use App\Service\AccountEraser;
use App\Service\Import\ImportRunner;
use App\Support\DiscordLink;
use App\Support\DynamicPricing;
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

$users           = new UserRepository($pdo);
$tokensRepo      = new ApiTokenRepository($pdo);
$integrationRepo = new UserIntegrationRepository($pdo);
$identityRepo    = new UserIdentityRepository($pdo);

// Locale = celle du profil (surchargée par ?lang) → View configurée.
$profileForLocale = $users->getProfile($userId);
$view   = LocaleContext::viewFor($config, $users, $userId, $profileForLocale?->locale, __DIR__ . '/../templates');

$error        = null;
$success      = null;
$freshToken   = null; // secret affiché une seule fois
$importReport = null; // bilan du dernier import (self-service)

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
        // Upload > post_max_size : PHP vide $_POST/$_FILES → sans ce garde, le
        // rejet CSRF masquerait la vraie cause (message trompeur). À tester AVANT.
        if (UploadLimits::postExceededLimit($_SERVER, $_POST, $_FILES)) {
            throw new \RuntimeException($view->t('import.file_too_large'));
        }
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

            // updateProfile valide et normalise pricing_mode (liste blanche unique côté repository).
            // Fallback sur les défauts (profil absent) pour reconduire des valeurs sûres.
            $storedProfile = $profileForLocale ?? UserProfile::defaults();
            if (DynamicPricing::isEnabled($config)) {
                $zone        = trim((string) ($_POST['bidding_zone'] ?? '')) ?: null;
                $pricingMode = (string) ($_POST['pricing_mode'] ?? 'fixed');

                // TVA (%) et marge fournisseur (€/kWh) par utilisateur — vivent dans
                // le garde $dynamicEnabled comme les champs zone/mode (#153). Un champ
                // absent ou vide RECONDUIT la valeur stockée (pas de reset silencieux
                // à 21.0/0.0) ; une valeur effectivement fournie hors bornes est rejetée.
                $vatRaw  = $_POST['vat_rate'] ?? null;
                $vatRate = is_numeric($vatRaw) ? (float) $vatRaw : $storedProfile->vatRate;
                if ($vatRate < 0.0 || $vatRate > 100.0) {
                    throw new \InvalidArgumentException($view->t('account.invalid_vat_rate'));
                }

                $markupRaw = $_POST['supplier_markup_per_kwh'] ?? null;
                $markup    = is_numeric($markupRaw) ? (float) $markupRaw : $storedProfile->supplierMarkupPerKwh;
                if ($markup < -1.0 || $markup > 1.0) {
                    throw new \InvalidArgumentException($view->t('account.invalid_markup'));
                }
            } else {
                // Tarif dynamique désactivé : zone de marché et mode tarifaire sont masqués
                // et non éditables. On reconduit les valeurs déjà en base plutôt que de les
                // lire du POST : relire les effacerait (champs absents du formulaire), alors
                // qu'un pricing_mode/bidding_zone dynamique doit être préservé le temps que le
                // flag revienne. Neutralise aussi tout POST forgé (ni modif, ni activation).
                $storedZone    = $storedProfile->biddingZone;
                $zone          = (is_string($storedZone) && $storedZone !== '') ? $storedZone : null;
                $pricingMode   = $storedProfile->pricingMode;
                $vatRate       = $storedProfile->vatRate;
                $markup        = $storedProfile->supplierMarkupPerKwh;
            }

            $users->updateProfile($userId, new UserProfile(
                country: $country,
                timezone: $timezone,
                currency: $currency,
                biddingZone: $zone,
                pricingMode: $pricingMode,
                vatRate: $vatRate,
                supplierMarkupPerKwh: $markup,
                locale: $chosenLocale,
            ));
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
        } elseif ($action === 'unlink_provider') {
            // Déliaison d'une identité OIDC (#137), atomique : refus de la dernière
            // + promotion de la primaire gérées dans une transaction verrouillée.
            $id = filter_var($_POST['identity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \RuntimeException($view->t('account.identity_not_found'));
            }
            $status = (new IdentityUnlinker($pdo, $identityRepo, $users))->unlink($userId, $id);
            if ($status === IdentityUnlinker::LAST) {
                throw new \RuntimeException($view->t('account.identity_last'));
            }
            if ($status === IdentityUnlinker::NOT_FOUND) {
                throw new \RuntimeException($view->t('account.identity_not_found'));
            }
            $success = $view->t('account.identity_unlinked');
        } elseif ($action === 'integration_enable' || $action === 'integration_disable') {
            $moduleKey = (string) ($_POST['module_key'] ?? '');
            $module    = ModuleRegistry::find($moduleKey, $config);
            if ($module === null) {
                throw new \RuntimeException($view->t('account.integration_unknown'));
            }
            if ($action === 'integration_enable') {
                $integrationRepo->enable($userId, $moduleKey, $module->defaultSettings($userId));
                $success = $view->t('integration.' . $moduleKey . '.enabled_msg');
            } else {
                $integrationRepo->disable($userId, $moduleKey);
                $success = $view->t('integration.' . $moduleKey . '.disabled_msg');
            }
        } elseif ($action === 'import') {
            // Import self-service : la cible est TOUJOURS l'utilisateur courant
            // (aucun champ « utilisateur cible » — l'import ne concerne que soi).
            $importReport = (new ImportRunner())->runFromRequest($pdo, $userId, $_POST, $_FILES);
            // Import tronqué (plafond atteint, données perdues) : pas de bannière
            // « terminé » trompeuse — l'avertissement du rapport tient lieu de signal.
            if ($importReport->truncated() === false) {
                $success = $view->t(($_POST['dry_run'] ?? '') === '1' ? 'import.done_dryrun' : 'import.done');
            }
        } elseif ($action === 'delete_account') {
            // Mot-clé de confirmation localisé (SUPPRIMER/DELETE/LÖSCHEN/…),
            // comparé sans tenir compte de la casse ni des espaces.
            $keyword = $view->t('account.delete_keyword');
            if (mb_strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== mb_strtoupper($keyword)) {
                throw new \InvalidArgumentException($view->t('account.delete_need_confirm', ['keyword' => $keyword]));
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

// Retour du flux de liaison OIDC (redirection depuis /auth/login, #137).
if ($success === null && $error === null) {
    if (isset($_GET['linked'])) {
        $success = ($_GET['already'] ?? '') === '1'
            ? $view->t('account.identity_already_linked')
            : $view->t('account.identity_linked');
    } elseif (($_GET['link_error'] ?? '') === 'taken') {
        $error = $view->t('account.identity_taken');
    } elseif (($_GET['link_error'] ?? '') === 'aborted') {
        $error = $view->t('account.identity_link_aborted');
    }
}

// ── Données pour l'affichage ────────────────────────────────────────────────
$user     = $users->findById($userId);

// Identités OIDC liées + fournisseurs encore liables (#137). Libellés issus de
// la config (source unique OidcClientFactory). Carte gardée par oidcEnabled côté vue.
$providerLabels = OidcClientFactory::labelsFromConfig(is_array($config['oidc'] ?? null) ? $config['oidc'] : []);

$identities = [];
$linkedKeys = [];
foreach ($identityRepo->listForUser($userId) as $identity) {
    $linkedKeys[$identity['provider']] = true;
    $identities[] = [
        'id'         => $identity['id'],
        'provider'   => $identity['provider'],
        'label'      => $providerLabels[$identity['provider']] ?? ucfirst($identity['provider']),
        'is_primary' => $user !== null && $user->oidcIss === $identity['oidc_iss'] && $user->oidcSub === $identity['oidc_sub'],
        'created_at' => $identity['created_at'],
    ];
}

$linkableProviders = [];
foreach ($providerLabels as $pKey => $label) {
    if (isset($linkedKeys[$pKey])) {
        continue;
    }
    $linkableProviders[] = ['key' => $pKey, 'label' => $label];
}

$profile  = $users->getProfile($userId) ?? UserProfile::defaults();
$tokens   = $tokensRepo->listForUser($userId);

// Connecteurs d'export (opt-in) : une carte par module du registre.
$integrations = [];
foreach (ModuleRegistry::all($config) as $module) {
    $row = $integrationRepo->get($userId, $module->key());
    $integrations[] = [
        'key'     => $module->key(),
        'enabled' => $row !== null && $row['enabled'],
        'status'  => $module->statusFor($userId, $row),
    ];
}

// Garantit que le fuseau courant du profil reste sélectionnable même s'il n'est
// plus listé par la timezone database installée (sinon le <select> retomberait
// silencieusement sur la 1re option au prochain enregistrement).
$timezoneOptions = Timezones::options($profile->timezone);

echo $view->render('account', [
    'oidcEnabled' => AuthGuard::isOidcEnabled($config),
    'dynamicEnabled' => DynamicPricing::isEnabled($config),
    'discordUrl'  => DiscordLink::inviteUrl($config),
    'error'      => $error,
    'success'    => $success,
    'freshToken' => $freshToken,
    'user'       => $user,
    'identities' => $identities,
    'linkableProviders' => $linkableProviders,
    'profile'    => $profile,
    'tokens'     => $tokens,
    'integrations' => $integrations,
    'available'  => Locale::available($config),
    'importReport' => $importReport,
    'timezoneOptions' => $timezoneOptions,
]);
