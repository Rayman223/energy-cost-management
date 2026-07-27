<?php
declare(strict_types=1);

use App\Domain\EnergyBill;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\EnergyBillRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\BillReconciliationService;
use App\Service\CostCalculationService;
use App\Service\SpotFormulaResolver;
use App\Service\TariffCalculatorService;
use App\Support\Adsense;
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

// Le rapprochement n'a de sens qu'en tarif dynamique : sans prix de marché, la part
// énergie ne dépend d'aucun coefficient à retrouver. Même prédicat que le grisage des
// lignes fournisseur dans /tariffs.
$isDynamic = DynamicPricing::isEnabled($config)
    && ($profile->pricingMode ?? 'fixed') !== 'fixed';

$billRepo = new EnergyBillRepository($pdo, $userId);

/**
 * Montant de facture saisi : `null` si le champ est vide (l'utilisateur n'a pas cette
 * valeur sur sa facture, ce n'est pas une erreur), sinon un flottant validé.
 *
 * Le plafond vient de la colonne, pas d'une hypothèse sur les dépenses : sans lui, un
 * montant démesuré partirait en base et n'y serait refusé qu'au niveau SQL — message
 * anglais brut affiché à l'utilisateur, ou troncature silencieuse sur un serveur sans
 * `STRICT_TRANS_TABLES` (cf. EnergyBill::MAX_AMOUNT).
 */
$parseAmount = static function (mixed $raw) use ($view): ?float {
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        return null;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($parsed === false || $parsed < 0.0) {
        throw new \InvalidArgumentException($view->t('reconciliation.invalid_amount'));
    }

    if ($parsed > EnergyBill::MAX_AMOUNT) {
        // floor() et non round() : arrondi au supérieur, le message annoncerait
        // « 100 000 000 » comme maximum alors que cette valeur est précisément refusée.
        throw new \InvalidArgumentException($view->t('reconciliation.amount_too_large', [
            'max' => number_format(floor(EnergyBill::MAX_AMOUNT), 0, '.', ' '),
        ]));
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

        // La page masque ses formulaires hors tarif dynamique : l'action est refusée
        // côté serveur aussi, pour qu'un POST forgé n'écrive pas dans une table dont
        // l'utilisateur ne voit pas l'écran (règle posée par #233).
        if (!$isDynamic) {
            throw new \RuntimeException($view->t('reconciliation.dynamic_required'));
        }

        if ($action === 'save') {
            // `<input type="month">` poste un 'YYYY-MM'. Le format est vérifié ici plutôt
            // que délégué au navigateur : le champ HTML5 n'est qu'une aide de saisie, un
            // POST direct peut envoyer n'importe quoi.
            $rawPeriod = trim((string) ($_POST['period'] ?? ''));
            if (preg_match('/^(\d{4})-(\d{2})$/', $rawPeriod, $m) !== 1) {
                throw new \InvalidArgumentException($view->t('reconciliation.invalid_period'));
            }

            $year  = (int) $m[1];
            $month = (int) $m[2];
            if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                throw new \InvalidArgumentException($view->t('reconciliation.invalid_period'));
            }

            // Montants optionnels et non exclusifs : un champ vide n'est pas une erreur,
            // c'est « je n'ai pas cette valeur sur ma facture ». Les deux vides, en
            // revanche, ne créent rien d'exploitable.
            $amountHtva = $parseAmount($_POST['amount_htva'] ?? null);
            $amountTtc  = $parseAmount($_POST['amount_ttc'] ?? null);

            if ($amountHtva === null && $amountTtc === null) {
                throw new \InvalidArgumentException($view->t('reconciliation.amount_required'));
            }

            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

            $billRepo->upsert('electricity', $year, $month, $amountHtva, $amountTtc, $note);
            $success = $view->t('reconciliation.saved', ['period' => sprintf('%04d-%02d', $year, $month)]);
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['bill_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException($view->t('reconciliation.invalid_bill'));
            }

            $billRepo->delete($id);
            $success = $view->t('reconciliation.deleted');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Rapprochement ──────────────────────────────────────────────────────────
$rows     = [];
$fit      = null;
$current  = null;
$currency = null;
$page     = 1;
$pages    = 1;
$total    = 0;

if ($isDynamic) {
    $timezone = $profile->timezone ?? 'UTC';

    $zone = $profile?->biddingZone;
    $zone = ($zone !== null && $zone !== '')
        ? $zone
        : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

    $costSvc = new CostCalculationService(
        legacyRepo: new ElectricityReadingRepository($pdo, $userId, $timezone),
        tariffRepo: new TariffRepository($pdo, $userId, $isAdmin),
        gasRepo: new UtilityReadingRepository($pdo, $userId, 'gas'),
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: new DynamicPriceRepository($pdo, $zone),
        dynamicEnabled: true,
        pricingMode: $profile->pricingMode ?? 'fixed',
        supplierMarkupPerKwh: $profile->supplierMarkupPerKwh ?? 0.0,
        tariffTimezone: $timezone,
    );

    // Page hors bornes (URL bricolée, facture supprimée depuis) : le service la ramène
    // dans l'intervalle disponible plutôt que de rendre un tableau vide sans explication.
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

    $result   = (new BillReconciliationService($billRepo, $costSvc))->reconcile('electricity', $requestedPage);
    $rows     = $result['rows'];
    $fit      = $result['fit'];
    $current  = $result['current'];
    $currency = $result['currency'];
    $page     = $result['page'];
    $pages    = $result['pages'];
    $total    = $result['total'];
}

// Mois par défaut du formulaire : le mois précédent, celui que l'utilisateur vient de
// recevoir sur sa facture.
$defaultPeriod = (new DateTimeImmutable('first day of last month'))->format('Y-m');

echo $view->render('reconciliation', [
    'oidcEnabled'    => AuthGuard::isOidcEnabled($config),
    'discordUrl'     => DiscordLink::inviteUrl($config),
    'adsenseClient'  => Adsense::clientId($config),
    'error'          => $error,
    'success'        => $success,
    'isAdmin'        => $isAdmin,
    'isDynamic'      => $isDynamic,
    'rows'           => $rows,
    'page'           => $page,
    'pages'          => $pages,
    'total'          => $total,
    'pageSize'       => BillReconciliationService::PAGE_SIZE,
    'fit'            => $fit,
    'current'        => $current,
    'currency'       => $currency ?? 'EUR',
    'coefficientMax' => SpotFormulaResolver::COEFFICIENT_MAX,
    'maxAmount'      => EnergyBill::MAX_AMOUNT,
    'defaultPeriod'  => $defaultPeriod,
    'available'      => Locale::available($config),
    'timezone'       => $profile->timezone ?? null,
]);
