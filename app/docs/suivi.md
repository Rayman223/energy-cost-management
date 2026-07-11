# Document de suivi — Manage Energy v2

## État actuel (v2 — mars 2026)

### Architecture
- Source de données principale : `Data_Dries` (kWh, compteur P1) + `Data_Solaire` (production PV, Wh cumulatifs).

### Flux de données
1. **Ingestion horaire** (`cron_hourly.php`, `:00`) — lit les APIs compteurs locaux → insert `Data_Dries` + `Data_Solaire`.
2. **Envoi quotidien** (`cron_daily_webhook.php`, `01:15`) — provisioning EnergyID V2 `/hello` + push première valeur de chaque journée.
3. **Dashboard** (route `/`, `app/routes/dashboard.php`) — affichage temps réel des index, deltas mensuels, estimation coûts, historique 30/60/90j, encodage gaz.

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

### Gaz
- Encodage manuel des index en m³ via le dashboard.
- Conversion m³ → kWh via coefficient PCS.
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

none

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
│   ├── index.php            ← front controller (route les URLs propres)
│   └── assets/              ← CSS / JS / images
├── routes/                  ← scripts de page requis par le front controller
│   ├── dashboard.php        ← dashboard (route /)
│   ├── tariffs.php          ← gestion des tarifs (route /tariffs)
│   └── api.php              ← API JSON (route /api, GET + POST)
├── scripts/
│   ├── cron_hourly.php      ← ingestion horaire
│   └── cron_daily_webhook.php ← envoi EnergyID
├── sql/
│   └── schema.sql           ← tables
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