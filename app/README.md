# Nouveau projet `app/` (PHP orienté objet simple)

Ce dossier contient une base propre pour remplacer l'ancien projet `old/`.

## Objectif
1. Enregistrer les données énergie **horaire** en DB.
2. Envoyer **1 fois par jour** un JSON vers EnergyID Incoming Webhooks **V2**.
3. Publier les données des tables:
   - `Data_Dries` (import/export jour/nuit) en kWh,
   - `Data_Solaire.production` en Wh (converti en kWh pour la clé `pv`),
   - fallback `Data_Brusol` tant que migration non terminée.
4. À l'envoi quotidien, pousser **la première valeur de chaque journée**.

## Structure
- `bootstrap.php`: autoload + chargement config.
- `config/config.example.php`: exemple de configuration.
- `scripts/cron_hourly.php`: ingestion horaire DB depuis les APIs compteurs locales.
- `scripts/cron_daily_webhook.php`: provisioning `/hello` + envoi quotidien vers EnergyID.
- `docs/energyid-v2-model.md`: contrat V2 (endpoint, headers, payload, erreurs, retry).


## Mapping API compteurs
- Les endpoints sont configurés dans `config.php` (`meters.dries_url`, `meters.solar_url`).
- Les chemins JSON des index sont configurés dans `meters.paths` et testés dans l'ordre jusqu'à trouver une valeur numérique.
- Les index `Data_Dries` sont attendus en kWh.
- `Data_Solaire.production` est stocké en Wh.

## Installation rapide
```bash
cp app/config/config.example.php app/config/config.php
# adapter DB + energyid provisioning credentials + device metadata
mysql -u <user> -p <database> < app/sql/schema.sql
```

## Cron
Exemple:
```cron
# Ingestion horaire en base
0 * * * * /usr/bin/php /workspace/Manage-energy-costs/app/scripts/cron_hourly.php >> /var/log/manage-energy-costs-hourly.log 2>&1

# Envoi quotidien webhook EnergyID
15 1 * * * /usr/bin/php /workspace/Manage-energy-costs/app/scripts/cron_daily_webhook.php >> /var/log/manage-energy-costs-daily.log 2>&1
```

## Remarques
- Aucun flux CSV n'est utilisé dans la nouvelle architecture.
- Retry webhook: 1 retry max par métrique; si échec, la métrique est skip et rejouée au prochain run.
