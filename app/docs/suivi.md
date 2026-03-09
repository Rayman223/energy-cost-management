# Document de suivi — Manage Energy v2

## État actuel (v2 — mars 2026)

### Architecture
- Source de données principale : `Data_Dries` (kWh, compteur P1) + `Data_Solaire` (production PV, Wh cumulatifs).
- Fallback automatique `Data_Solaire` → `Data_Brusol` tant que `Data_Solaire` n'existe pas.
- Les tables `energy_readings` / `EnergyRepository` / `EnergyIngestionService` / `EnergyWebhookService` sont **code mort** : à supprimer lors d'un prochain nettoyage.

### Flux de données
1. **Ingestion horaire** (`cron_hourly.php`, `:00`) — lit les APIs compteurs locaux → insert `Data_Dries` + `Data_Solaire`.
2. **Envoi quotidien** (`cron_daily_webhook.php`, `01:15`) — provisioning EnergyID V2 `/hello` + push première valeur de chaque journée.
3. **Dashboard** (`public/index.php`) — affichage temps réel des index, deltas mensuels, estimation coûts, historique 30/60/90j, encodage gaz.

### Tarifaire électricité (Belgique)
Calcul complet dans `TariffCalculatorService`. Composantes stockées dans `tariff_grid_lines` :

| Clé                    | Description                             | Unité   |
|------------------------|-----------------------------------------|---------|
| `energy_simple`        | Énergie fournisseur (monohoraire)       | €/kWh   |
| `energy_t1`            | Énergie fournisseur T1 (jour)           | €/kWh   |
| `energy_t2`            | Énergie fournisseur T2 (nuit)           | €/kWh   |
| `subscription`         | Abonnement fournisseur                  | €/mois  |
| `distribution_t1`      | Distribution Sibelga T1 (jour)          | €/kWh   |
| `distribution_t2`      | Distribution Sibelga T2 (nuit)          | €/kWh   |
| `transport`            | Transport                               | €/kWh   |
| `management_annual`    | Gestion (fixe annuel)                   | €/an    |
| `prosumer_annual`      | Taxe prosumer BRUGEL                    | €/an    |
| `excise_duty`          | Droit d'accise spécial                  | €/kWh   |
| `energy_contribution`  | Contribution sur l'énergie              | €/kWh   |
| `green_contribution`   | Contribution verte & cogénération       | €/kWh   |
| `public_service_annual`| Obligations de service public           | €/an    |
| `injection_t1`         | Crédit injection T1                     | €/kWh   |
| `injection_t2`         | Crédit injection T2                     | €/kWh   |

### Tarifaire gaz (Belgique)
| Clé                    | Description                             | Unité   |
|------------------------|-----------------------------------------|---------|
| `energy`               | Énergie fournisseur                     | €/kWh   |
| `subscription`         | Abonnement fournisseur                  | €/mois  |
| `energy_contribution`  | Contribution sur l'énergie              | €/kWh   |
| `federal_excise`       | Accise fédérale                         | €/kWh   |
| `distribution`         | Distribution (variable)                 | €/kWh   |
| `distribution_fixed`   | Distribution (fixe)                     | €/an    |
| `transport`            | Transport                               | €/kWh   |
| `meter_reading_annual` | Relevé de compteur                      | €/an    |

### Valeurs tarifaires — Février 2026

#### Électricité
- Coût simple (monohoraire) : 11,60 c€/kWh → `energy_simple` = 0,116000 €/kWh
- Coût jour T1 : 13,25 c€/kWh → `energy_t1` = 0,132500 €/kWh
- Coût nuit T2 : 10,04 c€/kWh → `energy_t2` = 0,100400 €/kWh
- Abonnement : 2,99 €/mois → `subscription` = 2,990000
- Distribution jour : 9,96 c€/kWh → `distribution_t1` = 0,099600 €/kWh
- Transport : 2,27 c€/kWh → `transport` = 0,022700 €/kWh
- Gestion : 14,73 €/an → `management_annual` = 14,730000
- Taxe prosumer : 0 €/kW/an → `prosumer_annual` = 0,000000
- Droit d'accise spécial : 5,0329 c€/kWh → `excise_duty` = 0,050329 €/kWh
- Contribution énergie : 0,20417 c€/kWh → `energy_contribution` = 0,002042 €/kWh
- Contribution verte & cogénération : 2,69 c€/kWh → `green_contribution` = 0,026900 €/kWh
- Obligations service public : 39,94 €/an → `public_service_annual` = 39,940000

#### Gaz
- Coût fournisseur : 4,17 c€/kWh → `energy` = 0,041700 €/kWh
- Abonnement : 2,99 €/mois → `subscription` = 2,990000
- Contribution énergie : 0,1058 c€/kWh → `energy_contribution` = 0,001058 €/kWh
- Accise fédérale : 0,8724 c€/kWh → `federal_excise` = 0,008724 €/kWh
- Distribution variable : 1,447 c€/kWh → `distribution` = 0,014470 €/kWh
- Distribution fixe : 43,07 €/an → `distribution_fixed` = 43,070000
- Transport : 0,165 c€/kWh → `transport` = 0,001650 €/kWh
- Relevé de compteur : 24,95 €/an → `meter_reading_annual` = 24,950000

### Gaz
- Encodage manuel des index en m³ via le dashboard.
- Conversion m³ → kWh via coefficient PCS (défaut 10,55 kWh/m³, configurable dans `config.php`).
- Historique des relevés + delta entre deux relevés consécutifs.

### Migration Data_Solaire
- `app/tools/conversion_solaire.php` : migre `Data_Brusol` → `Data_Solaire` (reconstitution index cumulatif kWh).
- **Conservé** car le dongle physique Brusol reste instable ; la migration peut être relancée à tout moment en dry-run.
- Accessible depuis le dashboard (lien direct).
- Paramètre `?DRY_RUN=0` pour l'exécution réelle ; par défaut dry-run.

### Formats d'affichage
- **Dates** : dd/mm/yyyy
- **Heures** : HH:mm (24h)

---

## Points ouverts

1. ~~**Suppression code mort** : retirer `app/src/Repository/EnergyRepository.php`, `EnergyIngestionService.php`, `EnergyWebhookService.php` (jamais appelés).~~
2. **Claim device EnergyID** : confirmer que l'appareil est claimé côté portail EnergyID (sinon `/hello` renvoie `claimCode`/`claimUrl`).
3. **Mapping métier** : `el.t1` / `el.t2` / `el-i.t1` / `el-i.t2` / `pv` — confirmer avec EnergyID.
4. ~~**Fiabilité dongle solaire** : une fois le dongle stable, supprimer le fallback `Data_Brusol` dans `LegacyDailyRepository::solarTable()` et fixer `Data_Solaire` en dur.~~
6. ~~**PCS coefficient gaz** : vérifier si le coefficient fourni par le fournisseur est fixe ou variable par période ; si variable, ajouter une table de coefficients périodiques.~~
7. ~~**Distribution fixe gaz** : unité confirmée **€/an** — calcul corrigé (`$days / 365`) dans `TariffCalculatorService`, label mis à jour dans `tariffs.php`.~~
8. ~~**TariffCalculatorService** : mettre à jour le calcul pour intégrer les nouvelles clés (`excise_duty`, `energy_contribution`, `green_contribution`, `public_service_annual`, `subscription`, `management_annual`, `transport`, `federal_excise`, `meter_reading_annual`).~~

---

## Structure `app/`

```
app/
├── bootstrap.php
├── config/
│   ├── config.example.php   ← copier en config.php et adapter
│   └── config.php           ← ignoré par git
├── docs/
│   ├── energyid-v2-model.md
│   └── suivi.md             ← ce fichier
├── public/
│   ├── index.php            ← dashboard
│   ├── tariffs.php          ← gestion des tarifs
│   └── api.php              ← API JSON (GET + POST)
├── scripts/
│   ├── cron_hourly.php      ← ingestion horaire
│   └── cron_daily_webhook.php ← envoi EnergyID
├── sql/
│   ├── schema.sql           ← tables de base
│   └── schema_v2.sql        ← delta v2 + seeds tarifaires commentés
├── src/
│   ├── Domain/
│   │   ├── EnergyReading.php
│   │   └── TariffGrid.php
│   ├── Infrastructure/
│   │   ├── Database.php
│   │   └── HttpClient.php
│   ├── Repository/
│   │   ├── GasRepository.php
│   │   ├── LegacyDailyRepository.php  ← source principale
│   │   ├── LegacyIngestionRepository.php
│   │   └── TariffRepository.php       ← version complète (findById, closeGrid, deleteGrid, pcsCoefficient)
│   └── Service/
│       ├── CostCalculationService.php
│       ├── DailyLegacyWebhookSyncService.php
│       ├── EnergyIdPayloadFactory.php
│       ├── EnergyIdV2Client.php
│       ├── GasManualEntryService.php
│       ├── MeterApiService.php
│       └── TariffCalculatorService.php
└── tools/
    └── conversion_solaire.php  ← migration Brusol→Solaire (conservé)
```

## Crons

```cron
# Ingestion horaire
0 * * * * /usr/bin/php /workspace/app/scripts/cron_hourly.php >> /var/log/energy-hourly.log 2>&1

# Envoi quotidien EnergyID (01:15)
15 1 * * * /usr/bin/php /workspace/app/scripts/cron_daily_webhook.php >> /var/log/energy-daily.log 2>&1
```