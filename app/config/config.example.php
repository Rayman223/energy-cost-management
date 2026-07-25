<?php

declare(strict_types=1);

return [
    'database' => [
        'host'    => '127.0.0.1',
        'port'    => 3307,
        'name'    => 'energy',
        'user'    => 'energy_user',
        'password'=> 'change_me',
        'charset' => 'utf8mb4',
    ],

    'energyid' => [
        // Kill-switch global du push EnergyID (lu par cron_daily_webhook.php).
        // Absent ⇒ activé par défaut. Mettre à false pour couper l'export.
        'enabled'             => true,
        'provisioning_key'    => 'change_me',
        'provisioning_secret' => 'change_me',
        'timeout'             => 15,
        'device'              => [
            'deviceId'       => 'manage-energy-costs-1',
            'deviceName'     => 'Manage Energy Costs Device',
            'firmwareVersion'=> '2.0.0',
            'ipAddress'      => '127.0.0.1',
            'macAddress'     => '00:00:00:00:00:00',
            'localDeviceUrl' => 'http://localhost',
        ],
    ],

    // Tarif dynamique (prix day-ahead du marché spot, ex. ENTSO-E).
    // Le marché (prix spot, token, zone) est global au site ; l'économique
    // (TVA, marge fournisseur) est passé PAR UTILISATEUR dans /account (#153).
    'dynamic_prices' => [
        'enabled'                 => true,
        'provider'                => 'entsoe',
        'api_url'                 => 'https://web-api.tp.entsoe.eu/api',
        'security_token'          => 'change_me',       // token ENTSO-E (inscription gratuite)
        'bidding_zone'            => '10YBE----------2', // zone de marché Belgique (fallback)
        'timeout'                 => 30,
    ],

    'web_security' => [
        // Master switch for web protection.
        'enabled' => true,

        // Optional allowlist (IPv4/CIDR). Empty array = no IP restriction.
        // Examples: ['192.168.1.0/24', '10.0.0.42']
        'allowed_ips' => [],

        // Optional allowlist of expected Host headers (anti-spoofing). When set,
        // any request whose Host is not listed falls back to the first entry when
        // building absolute URLs shown to users (e.g. the copy-paste curl command
        // on /api-guide), so a forged Host cannot redirect a pasted token.
        // Empty array = accept the current Host if well-formed. Examples:
        // ['energie.example.eu', 'energie.example.eu:8443']
        'trusted_hosts' => [],

        // HTTP Basic authentication for all web endpoints (dashboard + API).
        'basic_auth' => [
            'enabled'  => true,
            'username' => 'admin',
            'password' => 'change_me_now',
        ],
    ],

    // Authentification OpenID Connect (multi-fournisseurs).
    // enabled=false → comportement historique (Basic Auth ci-dessus) inchangé.
    // enabled=true  → connexion déléguée aux IdP OIDC, comptes multi-utilisateurs.
    //
    // Rétro-compat : l'ancienne forme plate (issuer/client_id/… directement sous
    // 'oidc', sans 'providers') reste acceptée et vaut un fournisseur unique.
    'oidc' => [
        'enabled'   => false,
        'providers' => [
            // La clé (google, microsoft, keycloak…) choisit l'icône du bouton et
            // la valeur stockée en base (users.provider). Une même personne via
            // deux IdP différents = deux comptes distincts (identité = iss+sub).
            'google' => [
                'issuer'        => 'https://accounts.google.com',
                'client_id'     => 'change_me',
                'client_secret' => 'change_me',
                'redirect_uri'  => '',                  // vide = dérivé (…/auth/login)
                'scopes'        => ['openid', 'profile'], // pas d'e-mail : openid + profile
                // 'label'      => 'Google',            // libellé bouton (défaut : clé capitalisée)
            ],
            // 'microsoft' => [ 'issuer' => 'https://login.microsoftonline.com/<tenant-id>/v2.0', … ], // cf. app/docs/oidc-microsoft.md
            // 'keycloak'  => [ 'issuer' => 'https://auth.example.com/realms/mon-realm', …, 'label' => 'Keycloak' ], // cf. app/docs/oidc-generic.md
        ],
    ],

    // Internationalisation.
    'i18n' => [
        'default_locale' => 'fr',
        'available'      => ['fr', 'en', 'nl', 'de'],
    ],

    // API publique d'ingestion.
    'api' => [
        'rate_limit_per_hour' => 600, // requêtes/heure par jeton
    ],

    // Lien vers le serveur Discord, affiché dans l'en-tête des pages.
    'discord' => [
        'invite_url' => '', // ex. https://discord.gg/xxxxxxx (vide = lien masqué)
    ],

    // Publicité Google AdSense (#185), au format « Auto ads » : un seul script
    // dans le <head>, Google place les emplacements. Désactivée par défaut —
    // sans identifiant valide, aucun script tiers n'est chargé et la CSP reste
    // stricte (les origines Google ne sont autorisées que si enabled=true).
    //
    // AVANT D'ACTIVER en Europe : le consentement aux cookies publicitaires est
    // délégué au CMP certifié IAB TCF de Google, à activer dans la console
    // AdSense → « Confidentialité et messages ». Copier également
    // app/public/ads.txt.example en app/public/ads.txt avec votre identifiant.
    'adsense' => [
        'enabled'   => false,
        'client_id' => '', // ex. ca-pub-1234567890123456
    ],

    // Identité de l'éditeur : mentions légales (directive e-commerce) et
    // responsable du traitement RGPD. Affichée sur /legal-notice et /privacy ;
    // toute clé laissée vide est signalée comme manquante sur la page.
    'legal' => [
        'publisher'     => '', // raison sociale ou nom de l'éditeur
        'address'       => '', // adresse postale de l'éditeur
        'contact_email' => '', // contact RGPD (exercice des droits)
        'host'          => '', // hébergeur : nom + pays
        'jurisdiction'  => '', // droit applicable, ex. Belgique
    ],

    // Fuseau applicatif technique : stockage des dates en UTC. L'affichage est
    // reconverti vers le fuseau de chaque utilisateur (user_profiles.timezone).
    'timezone' => 'UTC',
];
