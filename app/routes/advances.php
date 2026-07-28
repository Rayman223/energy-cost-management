<?php
declare(strict_types=1);

use App\Domain\AdvanceSchedule;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\AdvanceScheduleRepository;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\AdvanceBalanceService;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Support\Adsense;
use App\Support\Dates;
use App\Support\DiscordLink;
use App\Support\DynamicPricing;
use App\Support\LocaleContext;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6).
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

$db      = new Database($config['database']);
$pdo     = $db->pdo();
$userId  = UserContext::currentWebUserId($pdo, $config);
$users   = new UserRepository($pdo);
$isAdmin = ($users->findById($userId)?->isAdmin()) ?? false;

$profile = $users->getProfile($userId);
$view    = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

$error   = null;
$success = null;

$advanceRepo = new AdvanceScheduleRepository($pdo, $userId);

/**
 * Montant d'acompte saisi : obligatoire et strictement positif — un barème à 0 €
 * ne décrit aucun prélèvement.
 *
 * Le plafond vient de la colonne, pas d'une hypothèse sur les dépenses : sans lui,
 * un montant démesuré n'est refusé qu'au niveau SQL — message anglais brut, ou
 * troncature silencieuse sur un serveur sans `STRICT_TRANS_TABLES`.
 */
$parseAmount = static function (mixed $raw) use ($view): float {
    $parsed = filter_var(trim((string) ($raw ?? '')), FILTER_VALIDATE_FLOAT);
    if ($parsed === false || $parsed <= 0.0) {
        throw new \InvalidArgumentException($view->t('advances.invalid_amount'));
    }

    if ($parsed > AdvanceSchedule::MAX_AMOUNT) {
        // floor() et non round() : arrondi au supérieur, le message annoncerait
        // comme maximum une valeur précisément refusée.
        throw new \InvalidArgumentException($view->t('advances.amount_too_large', [
            'max' => number_format(floor(AdvanceSchedule::MAX_AMOUNT), 0, '.', ' '),
        ]));
    }

    return $parsed;
};

/**
 * Date 'YYYY-MM-DD' postée par un `<input type="date">`. Le format est revérifié
 * ici : le champ HTML5 n'est qu'une aide de saisie, un POST direct peut envoyer
 * n'importe quoi.
 *
 * Parsée en UTC, fuseau de stockage du projet, pour que la comparaison avec les
 * bornes de période porte sur le même référentiel.
 */
$parseDate = static function (mixed $raw, bool $required, string $errorKey) use ($view): ?DateTimeImmutable {
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new \InvalidArgumentException($view->t($errorKey));
        }

        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, Dates::utc());
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException($view->t($errorKey));
    }

    return $date;
};

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        if ($action === 'save') {
            $energyType = (string) ($_POST['energy_type'] ?? '');
            if (!in_array($energyType, AdvanceSchedule::ENERGY_TYPES, true)) {
                throw new \InvalidArgumentException($view->t('advances.invalid_energy'));
            }

            $amount    = $parseAmount($_POST['amount_monthly'] ?? null);
            $validFrom = $parseDate($_POST['valid_from'] ?? null, true, 'advances.invalid_valid_from');
            $validTo   = $parseDate($_POST['valid_to'] ?? null, false, 'advances.invalid_valid_to');

            if ($validFrom === null) {
                throw new \InvalidArgumentException($view->t('advances.invalid_valid_from'));
            }

            if ($validTo !== null && $validTo < $validFrom) {
                throw new \InvalidArgumentException($view->t('advances.invalid_range'));
            }

            $dueDay = filter_var($_POST['due_day'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
            if ($dueDay === false) {
                throw new \InvalidArgumentException($view->t('advances.invalid_due_day'));
            }

            // Édition d'un barème existant : l'identifiant est exclu du contrôle de
            // chevauchement, sinon un barème se déclarerait en conflit avec lui-même.
            $editId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editId = $editId === false ? null : $editId;

            // Deux barèmes actifs le même mois compteraient deux prélèvements pour
            // un seul débit réel : le solde annoncé serait faux d'un acompte entier.
            if ($advanceRepo->findOverlapping($energyType, $validFrom, $validTo, $editId) !== []) {
                throw new \InvalidArgumentException($view->t('advances.overlap'));
            }

            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

            if ($editId !== null) {
                $advanceRepo->update($editId, $energyType, $amount, $validFrom, $validTo, $dueDay, $note);
            } else {
                $advanceRepo->insert($energyType, $amount, $validFrom, $validTo, $dueDay, $note);
            }

            $success = $view->t('advances.saved');
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException($view->t('advances.invalid_schedule'));
            }

            $advanceRepo->delete($id);
            $success = $view->t('advances.deleted');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Barème en cours d'édition ──────────────────────────────────────────────
// Sans édition, réviser un acompte serait impossible : un barème ouvert
// (`valid_to` vide) chevauche toute nouvelle plage, et la seule issue serait de
// supprimer l'historique. On rouvre donc le formulaire pré-rempli, où l'utilisateur
// pose la date de fin du barème sortant avant d'en créer un nouveau — même geste
// que la clôture d'une grille tarifaire sur /tariffs.
// Un enregistrement réussi referme le formulaire : le POST reposte sur l'URL
// courante, `?edit=` compris, et rouvrirait sinon indéfiniment la même édition.
$editId   = $success !== null
    ? null
    : filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing  = null;
if ($editId !== false && $editId !== null) {
    foreach ($advanceRepo->listFor() as $candidate) {
        if ($candidate->id === $editId) {
            $editing = $candidate;
            break;
        }
    }
}

// ── Bilan de la période ────────────────────────────────────────────────────
$timezone = $profile->timezone ?? 'UTC';

// Période par défaut : l'année écoulée jusqu'à aujourd'hui, fenêtre d'un cycle de
// facturation complet — celle sur laquelle porte la régularisation annuelle.
$today          = new DateTimeImmutable('today', Dates::utc());
$defaultTo      = $today->format('Y-m-d');
$defaultFrom    = $today->modify('-1 year')->format('Y-m-d');

$periodFrom = trim((string) ($_GET['from'] ?? $defaultFrom));
$periodTo   = trim((string) ($_GET['to'] ?? $defaultTo));

$balance      = null;
$periodError  = null;
$futureClamped = false;

try {
    $from = $parseDate($periodFrom, true, 'advances.invalid_period');
    $to   = $parseDate($periodTo, true, 'advances.invalid_period');

    if ($from === null || $to === null || $to <= $from) {
        throw new \InvalidArgumentException($view->t('advances.invalid_period'));
    }

    // Bornes calendaires : le message reste compréhensible sur une année mal tapée,
    // là où la garde de longueur du service parlerait de milliers de jours.
    $year = (int) $from->format('Y');
    if ($year < 2000 || $year > 2100 || (int) $to->format('Y') > 2100) {
        throw new \InvalidArgumentException($view->t('advances.invalid_period'));
    }

    if (($to->getTimestamp() - $from->getTimestamp()) / 86400 > CostCalculationService::MAX_PERIOD_DAYS) {
        throw new \InvalidArgumentException($view->t('advances.period_too_long', [
            'max' => (string) CostCalculationService::MAX_PERIOD_DAYS,
        ]));
    }

    // Un acompte à échoir n'a pas été débité : compter les prélèvements du futur
    // gonflerait le « payé » et annoncerait un remboursement qui n'a pas lieu d'être.
    // La borne étant exclue, « aujourd'hui inclus » se dit « demain ».
    $tomorrow = $today->modify('+1 day');
    if ($to > $tomorrow) {
        $to            = $tomorrow;
        $futureClamped = true;
    }

    $zone = $profile?->biddingZone;
    $zone = ($zone !== null && $zone !== '')
        ? $zone
        : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

    // Même prédicat que /reconciliation : le kill-switch global ET le mode choisi
    // par l'utilisateur doivent tous deux désigner le tarif dynamique.
    $isDynamic = DynamicPricing::isEnabled($config)
        && ($profile->pricingMode ?? 'fixed') !== 'fixed';

    $costSvc = new CostCalculationService(
        legacyRepo: new ElectricityReadingRepository($pdo, $userId, $timezone),
        tariffRepo: new TariffRepository($pdo, $userId, $isAdmin),
        gasRepo: new UtilityReadingRepository($pdo, $userId, 'gas'),
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: new DynamicPriceRepository($pdo, $zone),
        dynamicEnabled: $isDynamic,
        waterRepo: new UtilityReadingRepository($pdo, $userId, 'water'),
        pricingMode: $profile->pricingMode ?? 'fixed',
        supplierMarkupPerKwh: $profile->supplierMarkupPerKwh ?? 0.0,
        tariffTimezone: $timezone,
    );

    $balance = (new AdvanceBalanceService($advanceRepo, $costSvc, $isDynamic))->balanceFor($from, $to);
} catch (\Throwable $e) {
    $periodError = $e->getMessage();
}

echo $view->render('advances', [
    'oidcEnabled'    => AuthGuard::isOidcEnabled($config),
    'discordUrl'     => DiscordLink::inviteUrl($config),
    'adsenseClient'  => Adsense::clientId($config),
    'error'          => $error,
    'success'        => $success,
    'isAdmin'        => $isAdmin,
    'schedules'      => $advanceRepo->listFor(),
    'editing'        => $editing,
    'balance'        => $balance,
    'periodError'    => $periodError,
    'futureClamped'  => $futureClamped,
    'periodFrom'     => $periodFrom,
    'periodTo'       => $periodTo,
    'energyTypes'    => AdvanceSchedule::ENERGY_TYPES,
    'maxAmount'      => AdvanceSchedule::MAX_AMOUNT,
    'currency'       => $profile->currency ?? 'EUR',
    'available'      => Locale::available($config),
    'timezone'       => $profile->timezone ?? null,
]);
