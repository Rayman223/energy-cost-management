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

    'meters' => [
        'timeout'   => 10,
        'dries_url' => 'http://192.168.1.5/api/v1/data',
        'solar_url' => 'http://192.168.1.7/api/v1/data',
        // Paths tested in order: first numeric value found is used.
        'paths' => [
            'prelev_jour'         => ['total_power_import_t1_kwh'],
            'prelev_nuit'         => ['total_power_import_t2_kwh'],
            'injec_jour'          => ['total_power_export_t1_kwh'],
            'injec_nuit'          => ['total_power_export_t2_kwh'],
            // Solar dongle: same structure as P1 meter, cumulative kWh export index.
            'solar_production_wh' => ['total_power_export_kwh'],
        ],
    ],

    // Tarif dynamique (prix day-ahead du marché spot, ex. ENTSO-E).
    // price stocké en €/kWh HTVA ; marge fournisseur + TVA appliquées au calcul.
    'dynamic_prices' => [
        'enabled'                 => true,
        'provider'                => 'entsoe',
        'api_url'                 => 'https://web-api.tp.entsoe.eu/api',
        'security_token'          => 'change_me',       // token ENTSO-E (inscription gratuite)
        'bidding_zone'            => '10YBE----------2', // zone de marché Belgique
        'timeout'                 => 30,
        'supplier_markup_per_kwh' => 0.0,               // marge fournisseur €/kWh TTC (contrats dynamiques BE)
        'vat_rate'                => 0.21,              // le spot ENTSO-E est HTVA
    ],

    'web_security' => [
        // Master switch for web protection.
        'enabled' => true,

        // Optional allowlist (IPv4/CIDR). Empty array = no IP restriction.
        // Examples: ['192.168.1.0/24', '10.0.0.42']
        'allowed_ips' => [],

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

    // Agent client (app/scripts/agent_push.php) : pousse les index des
    // compteurs locaux vers le serveur communautaire. Membre uniquement —
    // inutile sur le serveur central.
    'agent' => [
        'api_url'   => '',           // ex. https://energie.example.eu/api (l'ancien /api.php reste redirigé)
        'api_token' => 'change_me',  // créé via l'action api_token_create
        'timeout'   => 15,
    ],

    'timezone' => 'Europe/Brussels',
];
