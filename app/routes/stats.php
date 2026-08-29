<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\StatisticsRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\AuthGuard;
use App\Security\AuthSession;
use App\Security\UserContext;
use App\Security\WebAccessGuard;
use App\Service\CostCalculationService;
use App\Service\StatisticsService;
use App\Service\TariffCalculatorService;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\DynamicPricing;
use App\Support\LocaleContext;
use App\View\ViewFactory;

// Valeurs par défaut : la page doit se rendre même base tombée, avec un bandeau
// d'erreur et des sections vides, jamais une trace d'exception.
$config        = [];
$view          = null;
$dbError       = null;
$overview      = null;
$private       = null;
$profile       = null;
$userId        = null;
$isAdmin       = false;
$currency      = 'EUR';

require_once __DIR__ . '/../../vendor/autoload.php';

try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// CSP et en-têtes posés avant toute sortie, et avant toute redirection d'AuthGuard.
SecurityHeaders::send($config);

// « Page publique » ne veut pas dire « publique en toutes circonstances ».
//
// En mode OIDC désactivé (installation mono-tenant historique), l'instance
// ENTIÈRE est privée : allowlist IP + Basic Auth. Une page qui n'appellerait pas
// AuthGuard::protect() y percerait l'allowlist d'un auto-hébergé — une régression
// de sécurité silencieuse, dans le sens le plus grave. On ne devient réellement
// public qu'en mode OIDC, où l'inscription est ouverte par construction ; et même
// là, l'allowlist IP éventuelle continue de s'appliquer (cf. dashboard.php).
if ($dbError === null) {
    if (AuthGuard::isOidcEnabled($config)) {
        WebAccessGuard::enforceIp($config['web_security'] ?? []);
    } else {
        AuthGuard::protect($config);
    }
}

try {
    if ($dbError !== null) {
        throw new \RuntimeException($dbError);
    }

    $pdo   = (new Database($config['database']))->pdo();
    $stats = new StatisticsService(new StatisticsRepository($pdo));

    // Bloc public : calculé dans tous les cas, connecté ou non.
    $overview = $stats->publicOverview();

    // Détection de session SANS l'exiger.
    //
    // On ne sonde la session que si un cookie de session existe déjà :
    // AuthSession::userId() appelle Session::start(), qui poserait un PHPSESSID à
    // un visiteur anonyme qui n'en a aucun besoin — la page de confidentialité
    // décrit ce cookie comme strictement nécessaire, une page publique ne doit pas
    // le contredire.
    if (AuthGuard::isOidcEnabled($config)) {
        $userId = isset($_COOKIE[session_name()]) ? AuthSession::userId() : null;
    } else {
        // Mono-tenant : l'utilisateur est déjà authentifié par AuthGuard ci-dessus.
        $userId = UserContext::currentWebUserId($pdo, $config);
    }

    if ($userId !== null) {
        $users = new UserRepository($pdo);
        $user  = $users->findById($userId);

        // AuthGuard::protect() n'ayant pas tourné en mode OIDC, son contrôle
        // « compte encore actif » non plus : un compte bloqué dont la session est
        // restée ouverte ne doit pas recevoir de données personnelles.
        if ($user !== null && $user->isActive()) {
            $isAdmin  = $user->isAdmin();
            $profile  = $users->getProfile($userId);
            $currency = $profile->currency ?? 'EUR';
            $timezone = $profile->timezone ?? 'UTC';
            $view     = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

            $zone = $profile?->biddingZone;
            $zone = ($zone !== null && $zone !== '')
                ? $zone
                : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

            // Instancié pour CE foyer seulement, jamais en boucle : c'est
            // précisément pourquoi les agrégats par pays sont du SQL pur.
            $elecRepo   = new ElectricityReadingRepository($pdo, $userId, $timezone);
            $tariffRepo = new TariffRepository($pdo, $userId, $isAdmin);
            $costSvc    = new CostCalculationService(
                legacyRepo: $elecRepo,
                tariffRepo: $tariffRepo,
                gasRepo: new UtilityReadingRepository($pdo, $userId, 'gas'),
                calculator: new TariffCalculatorService(),
                dynamicPriceRepo: new DynamicPriceRepository($pdo, $zone),
                dynamicEnabled: DynamicPricing::isEnabled($config),
                waterRepo: new UtilityReadingRepository($pdo, $userId, 'water'),
                supplierMarkupPerKwh: $profile->supplierMarkupPerKwh ?? 0.0,
                tariffTimezone: $timezone,
            );

            [$from, $to] = StatisticsRepository::defaultWindow();

            // Le décompte sert au seul « coût réel du kWh », affiché isolément :
            // il inclut abonnements et taxes fixes, et n'est donc PAS comparable
            // à la moyenne des tarifs variables du pays. Une période sans données
            // exploitables renvoie un tableau d'indisponibilité, pas une erreur.
            $breakdown = $costSvc->estimatePeriodElectricity($from, $to);

            $private = $stats->privateComparison(
                userId: $userId,
                country: $profile?->country,
                currency: $currency,
                optedOut: $profile->statsOptOut ?? false,
                activeGrid: $tariffRepo->findActiveGrid('electricity'),
                breakdown: $breakdown,
                from: $from,
                to: $to,
            );
        } else {
            // Compte inactif : on retombe sur le rendu anonyme plutôt que de
            // laisser une session périmée ouvrir un bloc personnel.
            $userId = null;
        }
    }
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// Repli : View non localisée si le chargement a échoué avant LocaleContext.
$view ??= ViewFactory::create(
    __DIR__ . '/../templates',
    Locale::resolve($config, null),
    (string) ($config['i18n']['default_locale'] ?? 'fr'),
);

echo $view->render('stats', [
    'dbError'       => $dbError,
    'overview'      => $overview,
    'private'       => $private,
    'authenticated' => $userId !== null,
    'isAdmin'       => $isAdmin,
    'currency'      => $currency,
    'clockTimezone' => $profile->timezone ?? null,
    'available'     => Locale::available($config),
    'discordUrl'    => DiscordLink::inviteUrl($config),
    'adsenseClient' => Adsense::clientId($config),
]);
