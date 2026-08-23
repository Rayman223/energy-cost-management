<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\UserContext;
use App\Service\CostCalculationService;
use App\Service\DashboardCardsService;
use App\Service\TariffCalculatorService;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\WebAccessGuard;
use App\Support\Adsense;
use App\Support\Dates;
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
$timezone     = 'UTC';
$dbError      = null;
$deltas       = null;
$cards        = [];
$cost         = null;
$gasCostData  = null;
$gasLatest    = null;
$waterLatest  = null;
$waterCostData = null;
$gasInitYear  = (int) date('Y');
$gasInitMonth = (int) date('n');
$waterInitYear  = (int) date('Y');
$waterInitMonth = (int) date('n');

require_once __DIR__ . '/../../vendor/autoload.php';

// Bootstrap isolé : une configuration injoignable dégrade vers le dashboard en
// erreur (bloc try ci-dessous) plutôt que de provoquer un fatal.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// Après le bootstrap : la CSP dépend de la configuration (publicité #185 ⇒
// origines Google autorisées). Config injoignable ⇒ $config vide ⇒ CSP stricte.
// Toujours avant toute sortie, y compris le rendu de la page d'accueil publique.
SecurityHeaders::send($config);

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
            'available'     => Locale::available($config),
            'discordUrl'    => DiscordLink::inviteUrl($config),
            'adsenseClient' => Adsense::clientId($config),
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
    $currency   = $profile->currency ?? 'EUR';
    // Fuseau d'affichage propre à l'utilisateur : les dates (stockées en UTC)
    // sont reconverties vers ce fuseau côté client (window.APP_TIMEZONE). Repli
    // UTC (neutre, cohérent avec le stockage) si l'utilisateur n'a pas de profil.
    $timezone   = $profile->timezone ?? 'UTC';

    // Locale (profil, surchargée par ?lang persisté) → View configurée.
    $view = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

    // Zone de marché ENTSO-E de l'utilisateur (profil), sinon celle de la config.
    $zone = $profile?->biddingZone;
    $zone = ($zone !== null && $zone !== '')
        ? $zone
        : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

    $elecRepo   = new ElectricityReadingRepository($pdo, $userId, $timezone);
    $gasRepo    = new UtilityReadingRepository($pdo, $userId, 'gas');
    $waterRepo  = new UtilityReadingRepository($pdo, $userId, 'water');
    $tariffRepo = new TariffRepository($pdo, $userId, $isAdmin);
    $dynPriceRepo = new DynamicPriceRepository($pdo, $zone);
    $costSvc    = new CostCalculationService(
        legacyRepo: $elecRepo,
        tariffRepo: $tariffRepo,
        gasRepo: $gasRepo,
        calculator: new TariffCalculatorService(),
        dynamicPriceRepo: $dynPriceRepo,
        dynamicEnabled: DynamicPricing::isEnabled($config),
        waterRepo: $waterRepo,
        supplierMarkupPerKwh: $profile->supplierMarkupPerKwh ?? 0.0,
        tariffTimezone: $timezone,
    );

    $deltas      = $elecRepo->getMonthlyDeltas();
    // Cards du haut (élec / solaire / gaz / eau) : $deltas est repassé au
    // service pour ne pas refaire l'interpolation du mois en cours (#215). Le
    // dernier paramètre n'est qu'un repli : il ne sert que si aucun relevé élec
    // du mois ne fournit de borne de fin à la fenêtre de comparaison (#5).
    $cards       = (new DashboardCardsService($elecRepo, $costSvc))
        ->build($deltas, (int) date('Y'), (int) date('n'), new DateTimeImmutable('now', Dates::utc()));
    // Coût du mois : chaque sous-période dans le mode de sa grille (#245).
    $cost        = $costSvc->estimateCurrentMonthElectricity();
    // Tarif dynamique désactivé côté serveur ⇒ on n'expose pas la section (le JS
    // ne rend « ⚡ Tarif dynamique » que si data.dynamic est présent). Sinon la
    // projection tout-dynamique est toujours calculée : elle détaille le mois d'un
    // utilisateur réellement en dynamique, et sert de comparatif « et si ? » aux
    // autres — que `is_simulation` fait alors replier côté JS.
    if (DynamicPricing::isEnabled($config)) {
        $cost['dynamic'] = $costSvc->estimateCurrentMonthElectricityDynamic();
    }
    // Le dernier intervalle de relevés ne sert qu'à choisir le mois affiché par
    // défaut : son mois de début est le dernier dont on ait une mesure des deux
    // bornes. Le COÛT amorcé doit, lui, être celui de ce mois calendaire (#255) —
    // le bloc gaz s'intitule « janvier 2026 », et le JS réutilise cette valeur
    // aussi bien pour le panneau détail que pour la card de la vue année, où elle
    // est sommée avec onze mois calendaires.
    $gasLastPeriod = $costSvc->estimateLastGasPeriod();
    if (!empty($gasLastPeriod['period_from'])) {
        $gasPeriodFrom = new DateTimeImmutable($gasLastPeriod['period_from']);
        $gasInitYear   = (int) $gasPeriodFrom->format('Y');
        $gasInitMonth  = (int) $gasPeriodFrom->format('n');
    }
    $gasCostData = $costSvc->estimateMonthGas($gasInitYear, $gasInitMonth);
    $gasLatest   = $gasRepo->getLatest();
    $waterLatest = $waterRepo->getLatest();
    if ($waterLatest !== null && !empty($waterLatest['reading_at'])) {
        $waterPeriod    = new DateTimeImmutable((string) $waterLatest['reading_at']);
        $waterInitYear  = (int) $waterPeriod->format('Y');
        $waterInitMonth = (int) $waterPeriod->format('n');
    }
    $waterCostData = $costSvc->estimateMonthWater($waterInitYear, $waterInitMonth);
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

$view ??= ViewFactory::create(__DIR__ . '/../templates', Locale::resolve($config, null), (string) ($config['i18n']['default_locale'] ?? 'fr'));

echo $view->render('dashboard', [
    'dbError'      => $dbError,
    'deltas'       => $deltas,
    'cards'        => $cards,
    'cost'         => $cost,
    'gasCostData'  => $gasCostData,
    'gasLatest'    => $gasLatest,
    'waterLatest'  => $waterLatest,
    'waterCostData' => $waterCostData,
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
    'adsenseClient' => Adsense::clientId($config),
    'currency'     => $currency,
    'timezone'     => $timezone,
    // Fuseau BRUT du profil pour l'horloge (null si aucun profil) : l'horloge
    // retombe alors sur le navigateur, tandis que $timezone (défaut UTC) reste
    // concret pour le calcul des dates côté client/serveur.
    'clockTimezone' => $profile->timezone ?? null,
]);
