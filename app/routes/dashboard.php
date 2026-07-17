<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Security\UserContext;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\WebAccessGuard;
use App\Support\DiscordLink;
use App\Support\DynamicPricing;
use App\Support\LocaleContext;
use App\View\ViewFactory;

// Bootstrap — dégradation gracieuse si la base est indisponible.
$config       = [];
$view         = null;
$isAdmin      = false;
$oidcEnabled  = false;
$currency     = 'EUR';
$dbError      = null;
$deltas       = null;
$cost         = null;
$gasCostData  = null;
$syncStatus   = null;
$gasLatest    = null;
$waterLatest  = null;
$waterCostData = null;
$gasInitYear  = (int) date('Y');
$gasInitMonth = (int) date('n');
$waterInitYear  = (int) date('Y');
$waterInitMonth = (int) date('n');

require_once __DIR__ . '/../autoload.php';

SecurityHeaders::send();

// Bootstrap isolé : une configuration injoignable dégrade vers le dashboard en
// erreur (bloc try ci-dessous) plutôt que de provoquer un fatal.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// Visiteur anonyme en mode OIDC → page d'accueil publique (présentation +
// invitation à se connecter) plutôt que de rebondir vers l'IdP. Délibérément
// HORS du try de chargement des données : une erreur de rendu ici doit échouer
// en 500 (fail-closed), jamais retomber sur le rendu du dashboard authentifié
// (qui contournerait AuthGuard). En Basic Auth / OIDC désactivé, on n'y entre pas.
if ($dbError === null && AuthGuard::isOidcEnabled($config)) {
    WebAccessGuard::enforceIp($config['web_security'] ?? []); // allowlist IP avant tout démarrage de session
    if (AuthSession::userId() === null) {
        $locale  = Locale::resolve($config, null);
        $landing = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));
        echo $landing->render('welcome', [
            'available'  => Locale::available($config),
            'discordUrl' => DiscordLink::inviteUrl($config),
        ]);

        return;
    }
}

try {
    AuthGuard::protect($config);
    $oidcEnabled = AuthGuard::isOidcEnabled($config);

    $db         = new Database($config['database']);
    $pdo        = $db->pdo();

    // Tenant courant : session OIDC, ou tenant unique en mode Basic Auth.
    $userId     = UserContext::currentWebUserId($pdo, $config);

    $users      = new UserRepository($pdo);
    $isAdmin    = ($users->findById($userId)?->isAdmin()) ?? false;
    $profile    = $users->getProfile($userId);
    $currency   = (string) ($profile['currency'] ?? 'EUR');

    // Locale (profil, surchargée par ?lang persisté) → View configurée.
    $view = LocaleContext::viewFor($config, $users, $userId, $profile['locale'] ?? null, __DIR__ . '/../templates');

    // Zone de marché ENTSO-E de l'utilisateur (profil), sinon celle de la config.
    $zone = $profile['bidding_zone'] ?? null;
    $zone = ($zone !== null && $zone !== '')
        ? $zone
        : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

    $elecRepo   = new ElectricityReadingRepository($pdo, $userId);
    $gasRepo    = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepo  = new UtilityReadingRepository($pdo, $userId, 'water');
    $syncState  = new WebhookSyncStateRepository($pdo, $userId);
    $tariffRepo = new TariffRepository($pdo, $userId, $isAdmin);
    $dynPriceRepo = new DynamicPriceRepository($pdo, $zone);
    $pricingMode  = (string) ($profile['pricing_mode'] ?? 'fixed');
    $costSvc    = new CostCalculationService(
        legacyRepo: $elecRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: $dynPriceRepo,
        dynamicConfig: $config['dynamic_prices'] ?? [],
        waterRepo: $waterRepo,
        pricingMode: $pricingMode,
    );

    $deltas      = $elecRepo->getMonthlyDeltas();
    $cost        = $costSvc->estimateCurrentMonthElectricity();
    // Tarif dynamique désactivé côté serveur ⇒ on n'expose pas la section (le JS
    // ne rend « ⚡ Tarif dynamique » que si data.dynamic est présent).
    if (DynamicPricing::isEnabled($config)) {
        $cost['dynamic'] = $costSvc->estimateCurrentMonthElectricityDynamic();
    }
    $gasCostData = $costSvc->estimateLastGasPeriod();
    if (!empty($gasCostData['period_from'])) {
        $gasPeriodFrom = new DateTimeImmutable($gasCostData['period_from']);
        $gasInitYear   = (int) $gasPeriodFrom->format('Y');
        $gasInitMonth  = (int) $gasPeriodFrom->format('n');
    }
    $gasLatest   = $gasRepo->getLatest();
    $waterLatest = $waterRepo->getLatest();
    if ($waterLatest !== null && !empty($waterLatest['reading_at'])) {
        $waterPeriod    = new DateTimeImmutable((string) $waterLatest['reading_at']);
        $waterInitYear  = (int) $waterPeriod->format('Y');
        $waterInitMonth = (int) $waterPeriod->format('n');
    }
    $waterCostData = $costSvc->estimateMonthWater($waterInitYear, $waterInitMonth);

    // Le template ne consomme qu'un booléen « ce flux a-t-il déjà été synchronisé »
    // (pastille stale si un flux ne l'a jamais été) : on ne formate plus les 7
    // horodatages, jamais affichés (#130 C7).
    $syncStatus = [
        'prelevement_jour'   => $syncState->getLastSentAt('prelevement-jour') !== null,
        'prelevement_nuit'   => $syncState->getLastSentAt('prelevement-nuit') !== null,
        'injection_jour'     => $syncState->getLastSentAt('injection-jour') !== null,
        'injection_nuit'     => $syncState->getLastSentAt('injection-nuit') !== null,
        'production_solaire' => $syncState->getLastSentAt('production-solaire') !== null,
        'gaz_index'          => $syncState->getLastSentAt('gas-index') !== null,
        'water_index'        => $syncState->getLastSentAt('water-index') !== null,
    ];
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

$view ??= ViewFactory::create(__DIR__ . '/../templates', Locale::resolve($config, null), (string) ($config['i18n']['default_locale'] ?? 'fr'));

echo $view->render('dashboard', [
    'dbError'      => $dbError,
    'deltas'       => $deltas,
    'cost'         => $cost,
    'gasCostData'  => $gasCostData,
    'gasLatest'    => $gasLatest,
    'waterLatest'  => $waterLatest,
    'waterCostData' => $waterCostData,
    'syncStatus'   => $syncStatus,
    'initYear'     => (int) date('Y'),
    'initMonth'    => (int) date('n'),
    'gasInitYear'  => $gasInitYear,
    'gasInitMonth' => $gasInitMonth,
    'waterInitYear'  => $waterInitYear,
    'waterInitMonth' => $waterInitMonth,
    'available'    => Locale::available($config),
    'isAdmin'      => $isAdmin,
    'oidcEnabled'  => $oidcEnabled,
    'discordUrl'   => DiscordLink::inviteUrl($config),
    'currency'     => $currency,
]);
