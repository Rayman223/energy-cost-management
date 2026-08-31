<?php
declare(strict_types=1);

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\BatteryReadingRepository;
use App\Repository\BatteryRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\BatterySavingsService;
use App\Support\Adsense;
use App\Support\Dates;
use App\Support\DiscordLink;
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

$batteryRepo = new BatteryRepository($pdo, $userId);

/**
 * Nombre décimal saisi. `$required` distingue le champ obligatoire (capacité) du
 * champ facultatif laissé vide (capacité utile, prix) — une chaîne vide n'est pas
 * un zéro, elle est une absence de renseignement.
 *
 * Le plafond vient de la colonne, pas d'une hypothèse sur le marché : sans lui,
 * une valeur démesurée n'est refusée qu'au niveau SQL — message anglais brut, ou
 * troncature silencieuse sur un serveur sans `STRICT_TRANS_TABLES`.
 */
$parseDecimal = static function (mixed $raw, bool $required, float $max, string $errorKey, bool $strictlyPositive = true) use ($view): ?float {
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new \InvalidArgumentException($view->t($errorKey));
        }

        return null;
    }

    // Virgule décimale acceptée : les claviers localisés la produisent, et
    // `FILTER_VALIDATE_FLOAT` la refuse.
    $parsed = filter_var(str_replace(',', '.', $value), FILTER_VALIDATE_FLOAT);
    if ($parsed === false || $parsed < 0.0 || ($strictlyPositive && $parsed <= 0.0) || $parsed > $max) {
        throw new \InvalidArgumentException($view->t($errorKey));
    }

    return (float) $parsed;
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

/** Entier borné, facultatif si `$required` est faux. */
$parseInt = static function (mixed $raw, bool $required, int $min, int $max, string $errorKey) use ($view): ?int {
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new \InvalidArgumentException($view->t($errorKey));
        }

        return null;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($parsed === false) {
        throw new \InvalidArgumentException($view->t($errorKey));
    }

    return $parsed;
};

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        if ($action === 'save') {
            $capacity = $parseDecimal($_POST['capacity_kwh'] ?? null, true, Battery::MAX_CAPACITY_KWH, 'battery.invalid_capacity');
            $usable   = $parseDecimal($_POST['usable_capacity_kwh'] ?? null, false, Battery::MAX_CAPACITY_KWH, 'battery.invalid_usable_capacity');

            // Une capacité utile supérieure à la nominale n'a pas de sens physique :
            // la profondeur de décharge retranche de la capacité annoncée, elle n'y
            // ajoute rien. Non contrôlé, ce cas gonflerait tous les indicateurs de
            // cyclage sans jamais se signaler.
            if ($usable !== null && $capacity !== null && $usable > $capacity) {
                throw new \InvalidArgumentException($view->t('battery.usable_exceeds_capacity'));
            }

            $price = $parseDecimal($_POST['purchase_price'] ?? null, false, Battery::MAX_PURCHASE_PRICE, 'battery.invalid_price', false);

            $commissionedOn   = $parseDate($_POST['commissioned_on'] ?? null, true, 'battery.invalid_commissioned_on');
            $decommissionedOn = $parseDate($_POST['decommissioned_on'] ?? null, false, 'battery.invalid_decommissioned_on');
            $warrantyUntil    = $parseDate($_POST['warranty_until'] ?? null, false, 'battery.invalid_warranty');

            if ($commissionedOn === null || $capacity === null) {
                // Inatteignable : `$required` a déjà levé. Garde de typage pour que
                // le constructeur reçoive bien des valeurs non nulles.
                throw new \InvalidArgumentException($view->t('battery.invalid_commissioned_on'));
            }

            // Borne de fin EXCLUE (#1) : `decommissioned_on == commissioned_on` ne
            // décrit plus une journée de service mais une période VIDE, où la
            // batterie n'aurait jamais rien fait économiser.
            if ($decommissionedOn !== null && $decommissionedOn <= $commissionedOn) {
                throw new \InvalidArgumentException($view->t('battery.invalid_service_range'));
            }

            $ratedCycles = $parseInt($_POST['rated_cycles'] ?? null, false, 1, Battery::MAX_RATED_CYCLES, 'battery.invalid_cycles');

            $pvShare = $parseInt($_POST['pv_charge_share'] ?? null, true, 0, 100, 'battery.invalid_pv_share');

            $profile = BatteryDischargeProfile::tryFrom((string) ($_POST['discharge_profile'] ?? ''));
            if ($profile === null) {
                throw new \InvalidArgumentException($view->t('battery.invalid_discharge_profile'));
            }

            // La part T1 n'est exigée que par le profil qui la consomme ; ailleurs
            // elle est ignorée plutôt que refusée — changer de profil ne doit pas
            // rejeter un formulaire pour un champ devenu hors sujet.
            $t1Share = $profile->requiresT1Share()
                ? $parseInt($_POST['discharge_t1_share'] ?? null, true, 0, 100, 'battery.invalid_t1_share')
                : null;

            $battery = new Battery(
                id:                 0, // attribué par la base à l'insertion, ignoré à la mise à jour
                brand:              mb_substr(trim((string) ($_POST['brand'] ?? '')), 0, Battery::MAX_BRAND),
                model:              mb_substr(trim((string) ($_POST['model'] ?? '')), 0, Battery::MAX_MODEL),
                capacityKwh:        $capacity,
                commissionedOn:     $commissionedOn,
                decommissionedOn:   $decommissionedOn,
                usableCapacityKwh:  $usable,
                purchasePrice:      $price,
                warrantyUntil:      $warrantyUntil,
                ratedCycles:        $ratedCycles,
                pvChargeShare:      $pvShare ?? 100,
                dischargeProfile:   $profile,
                dischargeT1Share:   $t1Share,
                note:               mb_substr(trim((string) ($_POST['note'] ?? '')), 0, Battery::MAX_NOTE),
            );

            $editId = filter_var($_POST['battery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editId = $editId === false ? null : $editId;

            if ($editId !== null) {
                // Sans ce contrôle, une édition ne visant aucune ligne — batterie
                // supprimée depuis un autre onglet, identifiant appartenant à
                // quelqu'un d'autre — afficherait « ✓ enregistré » sans rien écrire.
                if (!$batteryRepo->owns($editId)) {
                    throw new \InvalidArgumentException($view->t('battery.invalid_battery'));
                }

                $batteryRepo->update($editId, $battery);
            } else {
                $batteryRepo->insert($battery);
            }

            $success = $view->t('battery.saved');
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['battery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !$batteryRepo->owns($id)) {
                // Même garde qu'à l'édition : une suppression sans cible ne doit pas
                // se féliciter d'un travail qu'elle n'a pas fait.
                throw new \InvalidArgumentException($view->t('battery.invalid_battery'));
            }

            // La cascade FK emporte les relevés de la batterie : c'est voulu et
            // annoncé dans la confirmation — des index orphelins ne se rattacheraient
            // à aucun matériel et ne seraient plus valorisables.
            $batteryRepo->delete($id);
            $success = $view->t('battery.deleted');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Batterie en cours d'édition ────────────────────────────────────────────
// Un enregistrement réussi referme le formulaire : le POST reposte sur l'URL
// courante, `?edit=` compris, et rouvrirait sinon indéfiniment la même édition
// (même comportement que /advances).
$editId  = $success !== null
    ? null
    : filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = ($editId !== false && $editId !== null) ? $batteryRepo->find($editId) : null;

// Jour civil de l'utilisateur : c'est lui qui décide si une batterie est « en
// service » à l'écran. Le fuseau de stockage (UTC) donnerait un verdict décalé
// pour qui vit à l'est de Greenwich le soir d'une mise en service.
$today = Dates::todayIn($profile->timezone ?? 'UTC');

$fleet = $batteryRepo->listAll();

// ── Bilan d'économie (#26) ────────────────────────────────────────────────
// Calculé ici plutôt que dans le template : la composition du parc, la résolution
// des grilles et la mensualisation sont de la logique métier. Un parc vide ne
// déclenche aucune requête — ni tarifs, ni relevés élec.
$balance = null;
if ($fleet !== []) {
    $savings = new BatterySavingsService(
        new TariffRepository($pdo, $userId, $isAdmin),
        new ElectricityReadingRepository($pdo, $userId, $profile->timezone ?? 'UTC'),
    );

    $balance = $savings->balance(array_map(
        static fn (Battery $battery): array => [
            'battery'  => $battery,
            'readings' => new BatteryReadingRepository($pdo, $userId, $battery->id),
        ],
        $fleet,
    ));
}

echo $view->render('batteries', [
    'oidcEnabled'    => AuthGuard::isOidcEnabled($config),
    'discordUrl'     => DiscordLink::inviteUrl($config),
    'adsenseClient'  => Adsense::clientId($config),
    'error'          => $error,
    'success'        => $success,
    'isAdmin'        => $isAdmin,
    'batteries'      => $fleet,
    'balance'        => $balance,
    'editing'        => $editing,
    'profiles'       => BatteryDischargeProfile::cases(),
    'today'          => $today,
    'maxCapacity'    => Battery::MAX_CAPACITY_KWH,
    'maxPrice'       => Battery::MAX_PURCHASE_PRICE,
    'maxCycles'      => Battery::MAX_RATED_CYCLES,
    'currency'       => $profile->currency ?? 'EUR',
    'available'      => Locale::available($config),
    'timezone'       => $profile->timezone ?? null,
]);
