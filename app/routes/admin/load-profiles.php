<?php

declare(strict_types=1);

/**
 * Administration — profils de charge (#101).
 *
 * Le RLP est un profil MESURÉ : Synergrid publie les coefficients d'un mois après sa
 * clôture, sans API. Cette page montre quels mois manquent pour les profils que des
 * grilles indexées désignent, et permet d'importer le CSV converti sans accès au
 * serveur. Le cron `cron_load_profile_unraid.sh` notifie l'admin et pointe ici.
 *
 * Réservée aux comptes « admin » : `load_profiles` est une table PARTAGÉE, qu'un
 * import modifie pour toute la communauté. Session uniquement.
 */

use App\Http\SecurityHeaders;
use App\Http\UploadLimits;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\LoadProfileRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\Import\LoadProfileCsvParser;
use App\Service\LoadProfileFreshness;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\DonateLink;
use App\Support\LocaleContext;

// Bootstrap isolé : une configuration injoignable dégrade en 503 propre (#130 C6).
try {
    $config = require __DIR__ . '/../../bootstrap.php';
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

$users   = new UserRepository($pdo);
$profile = $users->getProfile($userId);
$view    = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../../templates');

// ── Garde admin : même règle que /admin ──────────────────────────────────────
$me = $users->findById($userId);
if ($me === null || $me->isAdmin() === false) {
    http_response_code(403);
    echo $view->render('error', ['code' => 403, 'message' => $view->t('admin.forbidden')]);

    return;
}

$profiles = new LoadProfileRepository($pdo);
$error    = null;
$result   = null;

// Valeurs du formulaire, réaffichées après envoi (le fichier, lui, ne peut pas l'être).
$form = [
    'code'       => LoadProfileRepository::DEFAULT_CODE,
    'country'    => LoadProfileRepository::DEFAULT_COUNTRY,
    'resolution' => 15,
    'timezone'   => LoadProfileCsvParser::DEFAULT_TIMEZONE,
    'ts_col'     => LoadProfileCsvParser::DEFAULT_TS_COL,
    'value_col'  => LoadProfileCsvParser::DEFAULT_VALUE_COL,
    'dry_run'    => true,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Fichier > post_max_size : PHP vide $_POST et $_FILES, CSRF compris.
        if (UploadLimits::postExceededLimit($_SERVER, $_POST, $_FILES)) {
            throw new \RuntimeException($view->t('load_profiles.too_large'));
        }
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        $form = [
            'code'       => (string) ($_POST['code'] ?? ''),
            'country'    => (string) ($_POST['country'] ?? ''),
            'resolution' => (int) ($_POST['resolution'] ?? 15),
            'timezone'   => (string) ($_POST['timezone'] ?? ''),
            'ts_col'     => (string) ($_POST['ts_col'] ?? ''),
            'value_col'  => (string) ($_POST['value_col'] ?? ''),
            'dry_run'    => ($_POST['dry_run'] ?? '') === '1',
        ];

        $code       = LoadProfileCsvParser::normalizeCode($form['code']);
        $country    = LoadProfileCsvParser::normalizeCountry($form['country']);
        $resolution = LoadProfileCsvParser::checkResolution($form['resolution']);
        $tz         = LoadProfileCsvParser::timezone($form['timezone']);

        $file   = is_array($_FILES['profile_file'] ?? null) ? $_FILES['profile_file'] : [];
        $handle = LoadProfileCsvParser::openUploaded($file);
        try {
            $parsed = (new LoadProfileCsvParser())->parse(
                $handle,
                $form['ts_col'] !== '' ? $form['ts_col'] : LoadProfileCsvParser::DEFAULT_TS_COL,
                $form['value_col'] !== '' ? $form['value_col'] : LoadProfileCsvParser::DEFAULT_VALUE_COL,
                $resolution,
                $tz,
            );
        } finally {
            fclose($handle);
        }

        $written = 0;
        $dryRun  = $form['dry_run'];
        if (!$dryRun) {
            $written = $profiles->upsertWeights($code, $country, $resolution, $parsed->weights, 'synergrid');
            // Après une écriture, la simulation redevient le défaut : le fichier
            // suivant (un autre mois) mérite d'être vérifié avant d'être écrit.
            $form['dry_run'] = true;
        }

        $result = [
            'code'       => $code,
            'country'    => $country,
            'resolution' => $resolution,
            'points'     => $parsed->count(),
            'first'      => $parsed->firstSlot(),
            'last'       => $parsed->lastSlot(),
            'months'     => $parsed->pointsByMonth(),
            'rejected'   => $parsed->rejected,
            'merged'     => $parsed->merged,
            'dry_run'    => $dryRun,
            'written'    => $written,
        ];
    } catch (\PDOException $e) {
        // AVANT le cas suivant : PDOException étend RuntimeException, et son message
        // (SQL, nom de table) n'est pas un message de validation.
        error_log('[load-profiles] ' . $e->getMessage());
        $error = $view->t('load_profiles.failed');
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        // Messages de validation, sûrs à afficher tels quels.
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[load-profiles] ' . $e->getMessage());
        $error = $view->t('load_profiles.failed');
    }
}

// État APRÈS un éventuel import : la page montre l'effet de l'envoi.
$freshness = new LoadProfileFreshness();
$report    = $freshness->report(
    $profiles->profilesInUse(),
    static fn (string $code, string $country, \DateTimeImmutable $from, \DateTimeImmutable $to): array
        => $profiles->pointsByMonth($code, $country, $from, $to),
    new \DateTimeImmutable('now'),
);

echo $view->render('admin-load-profiles', [
    'oidcEnabled'   => AuthGuard::isOidcEnabled($config),
    'discordUrl'    => DiscordLink::inviteUrl($config),
    'donateUrl'     => DonateLink::url($config),
    'adsenseClient' => Adsense::clientId($config),
    'available'     => Locale::available($config),
    'timezone'      => $profile->timezone ?? null,
    'error'         => $error,
    'result'        => $result,
    'form'          => $form,
    'report'        => $report,
    'missing'       => LoadProfileFreshness::missing($report),
    'codes'         => $profiles->availableCodes(LoadProfileRepository::DEFAULT_COUNTRY),
    'downloadUrl'   => LoadProfileFreshness::DOWNLOAD_URL,
    'graceDays'     => LoadProfileFreshness::DEFAULT_GRACE_DAYS,
    'minCoverage'   => LoadProfileFreshness::MIN_COVERAGE_PCT,
]);
