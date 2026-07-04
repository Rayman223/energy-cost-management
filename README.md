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
- [Tests & qualité](#tests--qualité)
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

## Plateforme communautaire européenne (#47)

Au-delà de l'usage mono-foyer belge historique, l'application évolue en plateforme
**multi-utilisateurs**, **publique** et **européenne** (épopée [#47](https://github.com/Rayman223/Manage-energy-costs/issues/47), phases P0–P7, non-cassantes) :

- **Authentification OpenID Connect** (générique, PKCE) — aucun mot de passe ni
  e-mail stocké. Le mode Basic Auth historique reste actif tant qu'OIDC est
  désactivé (`oidc.enabled = false`).
- **Multi-tenant** : données cloisonnées par utilisateur ; électricité en modèle
  à registres (EU-ready), gaz/eau unifiés.
- **Catalogue tarifaire européen** partagé + surcharge perso, **multi-devises**,
  zones de marché **ENTSO-E**.
- **API d'ingestion** avec **jetons Bearer** par utilisateur (hachés, scopés,
  rate-limités, révocables).
- **Internationalisation complète** `fr/en/nl/de` (extensible) avec sélecteur de
  langue et formatage localisé.
- **Self-service & RGPD** : page compte, EnergyID **opt-in** (BE/NL), export et
  suppression de compte.
- **Administration** (`admin.php`, réservée aux admins) : gestion des membres
  (rôle, statut ; un blocage prend effet immédiatement).

Détails : [`app/docs/architecture.md`](app/docs/architecture.md) et la checklist
[`app/docs/security-review.md`](app/docs/security-review.md).

---

## Architecture

```
app/
├── autoload.php                  ← autoloader App\ → app/src/
├── bootstrap.php                 ← autoload + chargement config
├── config/                       ← config.example.php · config.php (⚠ gitignore)
├── docs/                         ← api-contract.md · sql-audit.md · page-states.md · plan/ · …
├── public/                       ← entrées minces : bootstrap → données → rendu
│   ├── index.php · tariffs.php   ← préparent les données puis View::render(…)
│   ├── login.php
│   ├── api.php                   ← câblage Router + dispatch (couche HTTP)
│   └── assets/                   ← CSS/JS statiques, versionnés (cache-busting)
│       ├── css/  tokens · dashboard · tariffs · login
│       └── js/   dashboard · tariffs
├── scripts/                      ← cron_hourly.php · cron_daily_webhook.php · cron_dynamic_prices.php
├── sql/                          ← schema.sql · migrations/
├── templates/                    ← vues HTML : dashboard · tariffs · login
└── src/
    ├── Domain/                   ← TariffGrid · TariffLineCatalog
    ├── Http/                     ← Request · JsonResponse · Router · ValidationException
    │   └── Controller/           ← Meter · Readings · Cost · Tariff · MeterEntry
    ├── Infrastructure/           ← Database (PDO) · HttpClient
    ├── Repository/
    │   ├── Contract/             ← interfaces (seams de test)
    │   └── Legacy · Gas · Water · Tariff · LegacyIngestion
    ├── Security/                 ← WebAccessGuard (IP + Basic Auth)
    ├── Service/                  ← CostCalculation · TariffCalculator · GasMonthInterpolator ·
    │                                ElectricityReadingMerger · EnergyId* · MeterApi · …
    ├── Support/                  ← Assets (URL d'assets + cache-busting)
    └── View/                     ← View (moteur de rendu + échappement centralisé)

tests/                            ← PHPUnit
├── Unit/         Domain · Http · Service
├── Integration/  BDD (s'auto-skippe sans base)
└── Fake/         doublures des repos (via interfaces)

composer.json · phpunit.xml.dist · phpstan.dist.neon (niveau 6)
```

### Couches & responsabilités

Une requête web entre par une **entrée mince** (`app/public/*.php`) :

- **Pages** (`index.php`, `tariffs.php`, `login.php`) : bootstrap → préparation des
  données → rendu via `View` (templates dans `app/templates/`, échappement
  centralisé `View::e()`).
- **API** (`api.php`) : `Router` → **contrôleur** (`src/Http/Controller/`) →
  `JsonResponse` (validation centralisée, codes HTTP normalisés).

Sous les entrées : **Service** (logique métier) → **Repository** (accès données,
type-hinté sur des interfaces `Repository/Contract/`) → **Infrastructure** (PDO,
HTTP). Le front (`assets/js/`) consomme l'API JSON via `fetch`.

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

### Déploiement Unraid (SWAG)

Sur le serveur Unraid (app servie par le container **SWAG**), le script
[`app/scripts/deploy_unraid.sh`](app/scripts/deploy_unraid.sh) met à jour `energyv2`
en une commande, à partir d'un **tag git** (ou du dernier `main`), de façon idempotente :

```bash
./app/scripts/deploy_unraid.sh            # déploie le dernier commit de main
./app/scripts/deploy_unraid.sh beta-0.3   # déploie le tag git beta-0.3
```

Il enchaîne : `git fetch`/`reset --hard` sur la cible, `composer install --no-dev`
(dans le container SWAG), puis applique `app/sql/schema.sql`. Le `git clean` est lancé
**sans `-x`** : `app/config/config.php` (credentials, non versionné) et `/vendor/` sont
**préservés**.

Les variables de configuration (chemins, noms de containers) sont en tête du script.
Lancement possible en SSH ou via le plugin **User Scripts** d'Unraid (schedule manuel
ou « At Startup of Array »).

> ⚠️ Le script applique uniquement `schema.sql` (`CREATE TABLE IF NOT EXISTS`). Les
> migrations de schéma (`app/sql/migrations/*.sql`, ex. `ALTER TABLE`) restent à appliquer
> **manuellement** — il n'y a pas encore de runner de migration versionné.

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

# Prix dynamiques day-ahead, après publication du marché (~13h30)
30 13 * * * /usr/bin/php /workspace/app/scripts/cron_dynamic_prices.php >> /var/log/energy-dynamic.log 2>&1
```

### Tarif dynamique (prix day-ahead)

Le bloc `dynamic_prices` de `config.php` active la récupération des prix spot
horaires/quart-horaires (par défaut **ENTSO-E**, zone BE) — token gratuit à obtenir
sur [transparency.entsoe.eu](https://transparency.entsoe.eu/) (champ `security_token`).
`cron_dynamic_prices.php` alimente la table `dynamic_prices` ; le dashboard affiche
alors une **comparaison** classique vs dynamique (la part énergie suit le prix de marché,
les autres postes restent ceux du tarif régulé).

> Base existante : appliquer `app/sql/migrations/2026-06-27_dynamic_prices.sql`.

---

## Dashboard & pages

| URL              | Description                                                  |
|------------------|--------------------------------------------------------------|
| `/`              | Dashboard : live, deltas mensuels, estimation coûts, historique |
| `/tariffs.php`   | Gestion des grilles tarifaires (ajout, modification)        |
| `/api.php`       | API JSON interne (lecture et écriture des données)          |
| `/login.php`     | Page d'authentification                                     |

---

## Tests & qualité

Outillage de dev géré par **Composer** (`require-dev` uniquement — `vendor/` n'est
pas déployé). L'application garde son autoloader maison ; Composer ne sert qu'aux
outils.

```bash
composer install          # installe PHPUnit (dev)

vendor/bin/phpunit        # tests (les tests d'intégration BDD s'auto-skippent sans base)
phpstan analyse --configuration=phpstan.dist.neon   # analyse statique (niveau 6)
find app -name '*.php' -print0 | xargs -0 -n1 php -l # lint de syntaxe
```

- **PHPUnit** — `tests/Unit/` (calculs tarifaires, interpolation gaz, couche HTTP,
  catalogue tarifaire…) et `tests/Fake/` (doublures des repos via interfaces).
  `tests/Integration/` teste les requêtes SQL contre une vraie base **si elle est
  joignable**, sinon se **skippe** (CI sans base → vert).
- **PHPStan niveau 6** — typage strict ; baseline résiduelle dans
  `phpstan-baseline.neon`.
- **CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) : à chaque push/PR,
  `php -l` (PHP 8.1/8.2/8.3) + **PHPStan niveau 6** + **PHPUnit** (8.1/8.2/8.3).
- **Assets** : CSS/JS statiques sous `app/public/assets/`, référencés avec
  cache-busting via `App\Support\Assets::url()` (suffixe `?v=<mtime>`).
- **Contrat d'API** : [app/docs/api-contract.md](app/docs/api-contract.md).

---

## Sécurité

La protection est gérée par `AuthGuard`/`WebAccessGuard` et configurée dans `config.php` :

- **Liste blanche IP** (CIDR) : laisser `allowed_ips` vide pour ne pas restreindre.
- **Authentification** : **OpenID Connect** si `oidc.enabled = true` (sessions
  durcies, comptes multi-utilisateurs), sinon **HTTP Basic Auth** historique
  (mono-tenant). Dans les deux cas l'allowlist IP s'applique.
- **Comptes bloqués** : un compte passé en `blocked` (espace admin) perd l'accès
  dès la requête suivante (session révoquée), et ses jetons API sont rejetés.
- Les scripts CLI (`cron_*.php`) sont exemptés de la protection web.

Posture complète et suivi (CSP, jetons, RGPD…) : [`app/docs/security-review.md`](app/docs/security-review.md).

---

## EnergyID

L'application envoie chaque nuit les relevés vers [EnergyID](https://app.energyid.eu/) via le protocole de provisioning V2 :

1. `POST /hello` — provisioning de l'appareil.
2. Push de la première valeur de chaque journée pour chaque flux (prélèvement T1/T2, injection T1/T2, production solaire).

Les clés de connexion (`provisioning_key` et `provisioning_secret`) sont à renseigner dans `config.php`.

---

## À faire

Voir le fichier [`app/docs/suivi.md`](app/docs/suivi.md) pour le journal de développement et la liste complète des fonctionnalités à venir.