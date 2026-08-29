<?php

declare(strict_types=1);

/**
 * Page « Mon compte » (self-service) : profil, jetons API, connecteurs d'export
 * (opt-in par module, ex. EnergyID), et RGPD (export + suppression). Session uniquement.
 */

use App\Domain\EuropeanCountries;
use App\Domain\ReadingGranularity;
use App\Domain\Timezones;
use App\Domain\UserProfile;
use App\Http\SecurityHeaders;
use App\Http\UploadLimits;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Integration\ModuleRegistry;
use App\Repository\ApiTokenRepository;
use App\Repository\TariffRepository;
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
use App\Support\Adsense;
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

SecurityHeaders::send($config);
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

// Lu avant le POST parce que le garde tarifaire ci-dessous en a besoin. Une seule
// action de cette page réécrit la table `users` — `unlink_provider`, qui promeut une
// autre identité primaire — et celle-là relit `$user` sur place : sinon le badge
// « primaire » serait calculé contre une identité qui vient d'être supprimée, et
// n'apparaîtrait plus sur aucune ligne. (`delete_account` sort par exit.)
$user = $users->findById($userId);

// Zone de marché et marge fournisseur ne pilotent que le prix spot : sans grille
// électricité indexée au marché, elles n'ont aucun effet sur le calcul et n'ajoutent
// que de la confusion sur la page compte (#253). Depuis #245 le mode fixe/dynamique
// vit sur la grille, d'où l'interrogation du dépôt tarifaire plutôt que du profil.
// Calculé avant le POST : le garde conditionne aussi la validation de la saisie.
//
// `hasDynamicGrid()` est volontairement large — toute grille visible, y compris une
// grille partagée du catalogue et une grille dont la période est révolue. Le critère
// exact (« la grille effective d'au moins une période est dynamique ») demanderait de
// rejouer la résolution période par période ; à défaut, on préfère le faux positif
// (deux champs inutiles affichés) au faux négatif, qui rendrait la marge inaccessible
// à qui en a besoin. Même sémantique que le garde de /reconciliation.
$tariffRepo     = new TariffRepository($pdo, $userId, $user?->isAdmin() ?? false);
$dynamicServer  = DynamicPricing::isEnabled($config);
$dynamicVisible = $dynamicServer && $tariffRepo->hasDynamicGrid();

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
            $currentProfile = $profileForLocale ?? UserProfile::defaults();

            $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
            // Liste blanche pays. Non-cassant : on tolère le pays déjà stocké
            // même s'il n'est pas (ou plus) dans le référentiel (ancien code
            // libre) ; le <select> le conserve comme option via la même garde
            // côté template. Sinon → null (« Aucun »).
            $storedCountry = $country !== '' ? strtoupper((string) ($currentProfile->country ?? '')) : '';
            $countryOk     = EuropeanCountries::isValid($country) || $country === $storedCountry;
            $country       = ($country !== '' && $countryOk) ? $country : null;

            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
            // Liste blanche des devises du référentiel. Non-cassant : on tolère la
            // devise déjà stockée même si elle n'y figure plus (le <select> la
            // conserve comme option via la même garde côté template).
            $allowedCurrencies = EuropeanCountries::currencies();
            $storedCurrency    = $currentProfile->currency;
            if ($storedCurrency !== '' && !in_array($storedCurrency, $allowedCurrencies, true)) {
                $allowedCurrencies[] = $storedCurrency;
            }
            if (!in_array($currency, $allowedCurrencies, true)) {
                throw new \InvalidArgumentException($view->t('account.invalid_currency'));
            }

            $timezone = trim((string) ($_POST['timezone'] ?? 'UTC'));
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException($view->t('account.invalid_timezone'));
            }

            $chosenLocale = strtolower(substr(trim((string) ($_POST['locale'] ?? 'fr')), 0, 5));
            $available = Locale::available($config);
            if (!in_array($chosenLocale, $available, true)) {
                $chosenLocale = (string) ($config['i18n']['default_locale'] ?? 'fr');
            }

            // Fallback sur les défauts (profil absent) pour reconduire des valeurs sûres.
            $storedProfile = $profileForLocale ?? UserProfile::defaults();
            if ($dynamicVisible) {
                $zone = trim((string) ($_POST['bidding_zone'] ?? '')) ?: null;

                // Marge fournisseur (€/kWh) par utilisateur — vit dans le garde
                // $dynamicVisible comme le champ zone (#153, #253). Un champ absent ou
                // vide RECONDUIT la valeur stockée (pas de reset silencieux à 0.0) ; une
                // valeur effectivement fournie hors bornes est rejetée. La TVA, elle, a
                // quitté le profil : sa source unique est la grille tarifaire (#232).
                $markupRaw = $_POST['supplier_markup_per_kwh'] ?? null;
                $markup    = is_numeric($markupRaw) ? (float) $markupRaw : $storedProfile->supplierMarkupPerKwh;
                if ($markup < -1.0 || $markup > 1.0) {
                    throw new \InvalidArgumentException($view->t('account.invalid_markup'));
                }
            } else {
                // Tarif dynamique hors service (coupé serveur) ou sans usage (aucune grille
                // électricité indexée au marché, #253) : zone de marché et marge fournisseur
                // sont masquées et non éditables. On reconduit les valeurs déjà en base
                // plutôt que de les lire du POST : relire les effacerait (champs absents du
                // formulaire), alors qu'une marge saisie doit survivre à un repassage en
                // tarif fixe et se retrouver intacte au retour au dynamique. Neutralise
                // aussi tout POST forgé (ni modif, ni activation). Le mode tarifaire, lui,
                // a quitté le profil pour la grille (#245) — cf. /tariffs.
                $storedZone = $storedProfile->biddingZone;
                $zone       = (is_string($storedZone) && $storedZone !== '') ? $storedZone : null;
                $markup     = $storedProfile->supplierMarkupPerKwh;

                // Depuis #253 ce garde dépend d'un état que l'utilisateur change
                // ailleurs : un formulaire ouvert alors qu'une grille dynamique
                // existait reposte ces deux champs après un retour au tarif fixe sur
                // /tariffs. Reconduire en silence annoncerait « Profil enregistré »
                // sur une saisie jetée. On refuse donc la demande, sans rien écrire.
                //
                // Comparé aux valeurs en place, et non à la simple présence des
                // champs : un POST forgé qui reposte l'existant ne change rien et n'a
                // pas à déclencher d'erreur, et le formulaire d'un onglet resté
                // ouvert sans modification passe sans bruit.
                $postedZone = $_POST['bidding_zone'] ?? null;
                if (is_string($postedZone) && (trim($postedZone) ?: null) !== $zone) {
                    throw new \InvalidArgumentException($view->t('account.dynamic_fields_readonly'));
                }

                $postedMarkup = $_POST['supplier_markup_per_kwh'] ?? null;
                if (is_numeric($postedMarkup) && abs((float) $postedMarkup - $markup) > 1e-9) {
                    throw new \InvalidArgumentException($view->t('account.dynamic_fields_readonly'));
                }
            }

            // Contribution aux statistiques agrégées de /stats (#8). Le champ est
            // nommé « contribuer » et non « se retirer » : la case est cochée par
            // défaut, donc un champ ABSENT (case décochée, POST partiel, formulaire
            // forgé) vaut RETRAIT. Le défaut d'un champ manquant penche ainsi du
            // côté protecteur — l'inverse aurait réinscrit un foyer aux statistiques
            // sur un POST tronqué. Contrepartie assumée : un formulaire d'onglet
            // resté ouvert avant ce déploiement reposte sans le champ et retire son
            // auteur ; c'est bénin (un retrait ne divulgue rien) et réversible.
            $statsOptOut = !isset($_POST['stats_contribute']);

            $users->updateProfile($userId, new UserProfile(
                country: $country,
                timezone: $timezone,
                currency: $currency,
                biddingZone: $zone,
                supplierMarkupPerKwh: $markup,
                locale: $chosenLocale,
                statsOptOut: $statsOptOut,
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
            // Retirer la primaire en promeut une autre dans `users` : la valeur lue
            // avant le POST ne désigne plus une identité existante, et la liste
            // rendue plus bas n'afficherait le badge « primaire » sur aucune ligne.
            $user    = $users->findById($userId);
            $success = $view->t('account.identity_unlinked');
        } elseif ($action === 'integration_enable' || $action === 'integration_disable') {
            $moduleKey = (string) ($_POST['module_key'] ?? '');
            $module    = ModuleRegistry::find($moduleKey, $config);
            // Un module coupé en config n'a pas de carte affichée (#233) : son
            // action est refusée aussi côté serveur, pour qu'un POST forgé ne
            // puisse pas basculer l'opt-in d'un connecteur inactif.
            if ($module === null || !$module->isGloballyEnabled()) {
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
            // Plafonnement des index élec par registre et par créneau aligné
            // (issue #165) : un par jour en tarif fixe, un par tranche de 15 min en
            // tarif dynamique. Délimité dans le fuseau de l'utilisateur.
            $importReport = (new ImportRunner())->runFromRequest(
                $pdo,
                $userId,
                $_POST,
                $_FILES,
                DynamicPricing::isEnabled($config) ? ReadingGranularity::QuarterHour : ReadingGranularity::Day,
                $profileForLocale->timezone ?? 'UTC',
            );
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
// `$user` est lu plus haut (avant le POST) : il alimente aussi le garde tarifaire.

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

// Connecteurs d'export (opt-in) : une carte par module actif en config. Un
// module coupé (`<key>.enabled` absent ou false) ne rend aucune carte (#233) ;
// l'opt-in déjà enregistré en base est conservé pour sa réactivation.
$integrations = [];
foreach (ModuleRegistry::enabled($config) as $module) {
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
    // Deux drapeaux distincts : `dynamicEnabled` ouvre la saisie (garde combiné),
    // `dynamicServerEnabled` sert au seul choix du message d'explication — kill-switch
    // serveur ou simple absence de grille dynamique (#253).
    'dynamicEnabled' => $dynamicVisible,
    'dynamicServerEnabled' => $dynamicServer,
    'discordUrl'  => DiscordLink::inviteUrl($config),
    'adsenseClient' => Adsense::clientId($config),
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
    'countries'  => EuropeanCountries::sortedForLocale($view->locale()),
    'currencies' => EuropeanCountries::currencies(),
]);
