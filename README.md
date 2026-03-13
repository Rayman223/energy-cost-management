# Manage Energy Costs

Application PHP de suivi et d'estimation des coûts énergétiques (électricité & gaz) pour une habitation en Belgique (réseau Sibelga). Elle intègre la lecture automatique de compteurs locaux via API, le calcul tarifaire complet (bi-horaire, injection, taxes), et la synchronisation vers la plateforme EnergyID.

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Architecture](#architecture)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Base de données](#base-de-données)
- [Crons](#crons)
- [Dashboard & pages](#dashboard--pages)
- [Sécurité](#sécurité)
- [EnergyID](#energyid)
- [À faire](#à-faire)

---

## Fonctionnalités

- **Lecture automatique** des index de compteur P1 (électricité) et du dongle solaire via leur API locale HomeWizard.
- **Ingestion horaire** en base de données MySQL.
- **Calcul tarifaire complet** pour l'électricité (bi-horaire T1/T2, injection, abonnement, distribution Sibelga, transport, taxes, contributions) et le gaz (énergie, distribution, transport, taxes, abonnement).
- **Dashboard web** temps réel : consommation réseau live, production solaire, deltas mensuels, estimation des coûts, historique 30/60/90 jours.
- **Encodage manuel** des index gaz en m³.
- **Synchronisation EnergyID** : envoi quotidien des relevés via le protocole de provisioning V2.
- **Sécurité web** : liste blanche IP (CIDR) et authentification HTTP Basic.

---

## Architecture

```
app/
├── bootstrap.php                      ← chargement config + autoloader
├── config/
│   ├── config.example.php             ← modèle à copier
│   └── config.php                     ← ⚠ ignoré par git (credentials)
├── docs/
│   ├── energyid-v2-model.md           ← doc protocole EnergyID V2
│   └── suivi.md                       ← journal de développement
├── public/
│   ├── index.php                      ← dashboard principal
│   ├── tariffs.php                    ← gestion des grilles tarifaires
│   ├── api.php                        ← API JSON interne (GET + POST)
│   └── login.php                      ← page d'authentification
├── scripts/
│   ├── cron_hourly.php                ← ingestion horaire (compteurs)
│   └── cron_daily_webhook.php         ← envoi quotidien vers EnergyID
├── sql/
│   └── schema.sql                     ← schéma complet de la base
├── src/
│   ├── Domain/
│   │   ├── EnergyReading.php
│   │   └── TariffGrid.php
│   ├── Infrastructure/
│   │   ├── Database.php               ← connexion PDO
│   │   └── HttpClient.php
│   ├── Repository/
│   │   ├── GasRepository.php
│   │   ├── LegacyDailyRepository.php  ← source principale des données
│   │   ├── LegacyIngestionRepository.php
│   │   └── TariffRepository.php
│   ├── Security/
│   │   └── WebAccessGuard.php         ← protection IP + Basic Auth
│   └── Service/
│       ├── CostCalculationService.php
│       ├── DailyLegacyWebhookSyncService.php
│       ├── EnergyIdPayloadFactory.php
│       ├── EnergyIdV2Client.php
│       ├── GasManualEntryService.php
│       ├── MeterApiService.php
│       └── TariffCalculatorService.php
└── tools/
    └── conversion_solaire.php         ← outil de migration (conservé)
```

### Flux de données

```
Compteur P1 (HomeWizard)  ──┐
                             ├─► cron_hourly.php ─► Data_Dries / Data_Solaire (MySQL)
Dongle solaire (HomeWizard) ─┘                            │
                                                          │
                                            cron_daily_webhook.php
                                                          │
                                                     EnergyID V2
```

---

## Prérequis

| Composant   | Version minimale |
|-------------|-----------------|
| PHP         | 8.2             |
| MySQL       | 8.0             |
| Extension   | `pdo_mysql`, `curl` |

---

## Installation

```bash
# 1. Cloner le dépôt
git clone https://github.com/Rayman223/Manage-energy-costs.git
cd Manage-energy-costs

# 2. Copier et adapter la configuration
cp app/config/config.example.php app/config/config.php
# → éditer app/config/config.php avec vos credentials

# 3. Initialiser la base de données
mysql -u <user> -p <database> < app/sql/schema.sql
```

---

## Configuration

Copier `app/config/config.example.php` en `app/config/config.php` (ignoré par git) et renseigner les clés suivantes :

```php
return [
    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'energy',
        'user'     => 'energy_user',
        'password' => 'change_me',
        'charset'  => 'utf8mb4',
    ],

    'energyid' => [
        'provisioning_key'    => 'change_me',
        'provisioning_secret' => 'change_me',
        // ...
    ],

    'meters' => [
        'dries_url' => 'http://<ip-compteur-p1>/api/v1/data',
        'solar_url' => 'http://<ip-dongle-solaire>/api/v1/data',
    ],

    'web_security' => [
        'enabled'    => true,
        'allowed_ips'=> [],           // [] = pas de restriction IP
        'basic_auth' => [
            'enabled'  => true,
            'username' => 'admin',
            'password' => 'change_me_now',
        ],
    ],

    'timezone' => 'Europe/Brussels',
];
```

---

## Base de données

Le schéma complet est dans `app/sql/schema.sql`. Tables principales :

| Table               | Description                                   |
|---------------------|-----------------------------------------------|
| `Data_Dries`        | Index électricité horaires (kWh T1/T2, injection) |
| `Data_Solaire`      | Index production solaire horaires (kWh cumulatifs) |
| `Data_Gaz`          | Relevés gaz manuels (m³)                      |
| `tariff_grid_lines` | Grilles tarifaires électricité & gaz          |

### Grille tarifaire électricité

| Clé                      | Description                           | Unité    |
|--------------------------|---------------------------------------|----------|
| `energy_simple`          | Énergie fournisseur (monohoraire)     | €/kWh    |
| `energy_t1`              | Énergie fournisseur T1 (jour)         | €/kWh    |
| `energy_t2`              | Énergie fournisseur T2 (nuit)         | €/kWh    |
| `subscription`           | Abonnement fournisseur                | €/mois   |
| `distribution_t1`        | Distribution Sibelga T1               | €/kWh    |
| `distribution_t2`        | Distribution Sibelga T2               | €/kWh    |
| `transport`              | Transport                             | €/kWh    |
| `management_annual`      | Gestion (fixe)                        | €/an     |
| `prosumer_annual`        | Taxe prosumer BRUGEL                  | €/an     |
| `excise_duty`            | Droit d'accise spécial                | €/kWh    |
| `energy_contribution`    | Contribution sur l'énergie            | €/kWh    |
| `green_contribution`     | Contribution verte & cogénération     | €/kWh    |
| `public_service_annual`  | Obligations de service public         | €/an     |
| `injection_t1`           | Crédit injection T1                   | €/kWh    |
| `injection_t2`           | Crédit injection T2                   | €/kWh    |

### Grille tarifaire gaz

| Clé                    | Description                   | Unité  |
|------------------------|-------------------------------|--------|
| `energy`               | Énergie fournisseur           | €/kWh  |
| `subscription`         | Abonnement fournisseur        | €/mois |
| `energy_contribution`  | Contribution sur l'énergie    | €/kWh  |
| `federal_excise`       | Accise fédérale               | €/kWh  |
| `distribution`         | Distribution (variable)       | €/kWh  |
| `distribution_fixed`   | Distribution (fixe)           | €/an   |
| `transport`            | Transport                     | €/kWh  |
| `meter_reading_annual` | Relevé de compteur            | €/an   |

---

## Crons

Ajouter dans la crontab (`crontab -e`) :

```cron
# Ingestion horaire des compteurs
0 * * * * /usr/bin/php /workspace/app/scripts/cron_hourly.php >> /var/log/energy-hourly.log 2>&1

# Envoi quotidien vers EnergyID (01:15)
15 1 * * * /usr/bin/php /workspace/app/scripts/cron_daily_webhook.php >> /var/log/energy-daily.log 2>&1
```

---

## Dashboard & pages

| URL              | Description                                                  |
|------------------|--------------------------------------------------------------|
| `/`              | Dashboard : live, deltas mensuels, estimation coûts, historique |
| `/tariffs.php`   | Gestion des grilles tarifaires (ajout, modification)        |
| `/api.php`       | API JSON interne (lecture et écriture des données)          |
| `/login.php`     | Page d'authentification                                     |

---

## Sécurité

La protection est gérée par `WebAccessGuard` et configurée dans `config.php` :

- **Liste blanche IP** (CIDR) : laisser `allowed_ips` vide pour ne pas restreindre.
- **HTTP Basic Auth** : activée par défaut, protège toutes les pages et l'API.
- Les scripts CLI (`cron_*.php`) sont exemptés de la protection web.

---

## EnergyID

L'application envoie chaque nuit les relevés vers [EnergyID](https://app.energyid.eu/) via le protocole de provisioning V2 :

1. `POST /hello` — provisioning de l'appareil.
2. Push de la première valeur de chaque journée pour chaque flux (prélèvement T1/T2, injection T1/T2, production solaire).

Les clés de connexion (`provisioning_key` et `provisioning_secret`) sont à renseigner dans `config.php`.

---

## À faire

Voir le fichier [`app/docs/suivi.md`](app/docs/suivi.md) pour le journal de développement et la liste complète des fonctionnalités à venir.