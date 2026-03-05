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
Calcul complet dans `TariffCalculatorService` :
- Énergie fournisseur T1/T2 (€/kWh)
- Distribution Sibelga T1/T2 + abonnement fixe (€/jour)
- Cotisation fédérale énergie (défaut 0,0054 €/kWh)
- Taxe prosumer BRUGEL (€/an, proratisée par jour)
- Crédit injection T1/T2 (0 pour les prosumers typiques Bruxelles)
- TVA 21%

Les tarifs sont stockés en DB (`tariff_grids` + `tariff_grid_lines`). Voir `schema_v2.sql` pour les seeds commentés.

### Gaz
- Encodage manuel des index en m³ via le dashboard.
- Conversion m³ → kWh via coefficient PCS (défaut 10,55 kWh/m³, configurable dans `config.php`).
- Historique des relevés + delta entre deux relevés consécutifs.

### Migration Data_Solaire
- `app/tools/conversion_solaire.php` : migre `Data_Brusol` → `Data_Solaire` (reconstitution index cumulatif kWh).
- **Conservé** car le dongle physique Brusol reste instable ; la migration peut être relancée à tout moment en dry-run.
- Accessible depuis le dashboard (lien direct).
- Paramètre `?DRY_RUN=0` pour l'exécution réelle ; par défaut dry-run.

---

## Points ouverts

1. **Suppression code mort** : retirer `app/src/Repository/EnergyRepository.php`, `EnergyIngestionService.php`, `EnergyWebhookService.php` (jamais appelés).
2. **Claim device EnergyID** : confirmer que l'appareil est claimé côté portail EnergyID (sinon `/hello` renvoie `claimCode`/`claimUrl`).
3. **Mapping métier** : `el.t1` / `el.t2` / `el-i.t1` / `el-i.t2` / `pv` — confirmer avec EnergyID.
4. **Fiabilité dongle solaire** : une fois le dongle stable, supprimer le fallback `Data_Brusol` dans `LegacyDailyRepository::solarTable()` et fixer `Data_Solaire` en dur.
5. **Tarifs en DB** : exécuter les seeds commentés dans `schema_v2.sql` après avoir adapté les valeurs au contrat réel.
6. **PCS coefficient gaz** : vérifier si le coefficient fourni par le fournisseur est fixe ou variable par période ; si variable, ajouter une table de coefficients périodiques.

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
│   │   └── TariffRepository.php
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

## Installation

```bash
cp app/config/config.example.php app/config/config.php
# Adapter DB, EnergyID credentials, IPs compteurs

mysql -u <user> -p <database> < app/sql/schema.sql
mysql -u <user> -p <database> < app/sql/schema_v2.sql
# Décommenter et adapter les seeds tarifaires dans schema_v2.sql
```




# À faire
- Pour la gestion des tariff, voici la liste des valeurs à prendre en compte pour le mois de février 2026. Modifie pour tenir compte de toutes ces valeurs.
Lorsque je clique pour ajouter un nouveau tariff, il faut préremplir le formulaire en reprenant les dernires valeurs en date car certaines valeurs ne change pas tous les mois.
- Met à jour le fichier de suivi

## Electricité
- Cout simple = 11,60 c€/kWh
- Cout Jour = 13,25 c€/kWh
- Cout Nuit = 10,04 c€/kWh
- Abonnement = 2,99 €/mois
- Distribution jour = 9,96 c€/kWh
- Transport = 2,27 c€/kWh
- Gestion = 14,73 €/an
- Prosumer = 0 €/kW/an
- Droit d’accise spécial = 5,0329 c€/kWh
- Contribution sur l'énergie = 0,20417 c€/kWh
- Contribution énergie verte et cogénération = 2,69 c€/kWh
- Obligations de service publique = 39,94 €/an

## Gaz
- Cout = 4,17 c€/kWh
- Abonnement = 2,99 €/mois
- Contribution sur l'énergie = 0,1058 c€/kWh
- Accise fédérale = 0,8724 c€/kWh
- Distribution
    - Cout variable = 1,447 c€/kWh
    - Cout fixe = 43,07 c€/kWh
- Transport = 0,165 c€/kWh
- Relevé de compteur = 24,95 €/an

Autre point : modifie le format des heures pour ne pas être états-unis soit :
- Format heure : HH:mm (24h)
- Format date : dd/mm/yyyy
