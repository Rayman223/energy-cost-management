# Nouveau projet `app/` (PHP orienté objet simple)

Ce dossier contient une base propre pour remplacer l'ancien projet `old/`.

## Objectif
1. Enregistrer chaque heure les données énergie en DB.
2. Envoyer un JSON à EnergyID via webhook après enregistrement.
3. Préparer la suite pour:
   - gestion de grille tarifaire détaillée,
   - calcul des coûts électricité + gaz,
   - encodage manuel des index gaz.

## Structure
- `bootstrap.php`: autoload + chargement config.
- `config/config.example.php`: exemple de configuration.
- `scripts/cron_hourly.php`: script à déclencher par cron toutes les heures.
- `src/`
  - `Infrastructure/`: DB + HTTP.
  - `Repository/`: accès données.
  - `Service/`: ingestion, webhook, tarif, gaz manuel.
  - `Domain/`: objets métiers simples.
- `sql/schema.sql`: tables minimales.
- `docs/suivi.md`: plan de suivi + questions ouvertes.

## Installation rapide
```bash
cp app/config/config.example.php app/config/config.php
# adapter les paramètres DB + webhook
mysql -u <user> -p <database> < app/sql/schema.sql
```

## Cron
Exemple (toutes les heures):
```cron
0 * * * * /usr/bin/php /workspace/Manage-energy-costs/app/scripts/cron_hourly.php >> /var/log/manage-energy-costs.log 2>&1
```

## Remarques
- Aucun flux CSV n'est utilisé dans la nouvelle architecture.
- Le script de cron contient un `TODO` pour brancher la vraie source de mesures (compteur/API locale).
