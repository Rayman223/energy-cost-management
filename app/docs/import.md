# Import en masse de relevés (P8, #59)

Import fiable de grandes quantités d'index (CSV/JSON), **idempotent** (réimport
sans doublon) avec **rapport ligne à ligne** (importés / doublons / erreurs).
C'est le **seul** mécanisme d'import : tout historique (y compris l'ancien
« Brusol » solaire) se ré-injecte via cet import générique.

## Modèle & idempotence

L'écriture passe par les mêmes seams que l'API d'ingestion, en `INSERT IGNORE`
sur les contraintes `UNIQUE` composites :
- **Électricité** → modèle à registres (`meter_readings`, unique `(register_id,
  reading_at)`). Registres : `import_t1`, `import_t2`, `export_t1`, `export_t2`,
  `production` (index cumulés en kWh, ≥ 0).
- **Gaz / eau** → `utility_readings` (unique `(user_id, energy_type, reading_at)`),
  index compteur m³ ≥ 0.

Un réimport du même fichier ne crée donc **aucun doublon** ; le rapport distingue
`imported` (nouveaux) de `duplicates` (déjà présents).

## Formats & mapping

- **CSV** : 1ʳᵉ ligne = en-tête (normalisé en minuscules). Lu **en flux**
  (gros fichiers sans surcharge mémoire).
- **JSON** : `{"readings":[ {…}, … ]}` ou un tableau d'objets en tête.

Le **mapping** est basé sur des **presets** par type d'énergie, avec surcharges :
| Type | Colonne horodatage (défaut) | Colonnes valeur (défaut) |
|---|---|---|
| electricity | `timestamp` | une colonne par registre, nommée comme la clé (`import_t1`, …) |
| gas / water | `timestamp` | `counter_m3` |

Surcharges : nom de la colonne d'horodatage (`--ts-col` / champ UI), de la
colonne de valeur gaz/eau (`--value-col`), ou mapping colonne→registre pour
l'électricité (`--map=col:registre,…`, CLI).

## Voies d'import

### 1. Self-service (tout membre) — page « Mon compte »
Carte « Importer mes données » : fichier + type + surcharges optionnelles + case
**Simulation** (dry-run). La cible est **toujours** l'utilisateur connecté.

### 2. Admin « pour le compte d'un autre » — page « Administration »
Réservée aux administrateurs. Ajoute un sélecteur d'**utilisateur cible**. La
règle est centralisée dans `App\Service\Import\ImportTarget` : importer pour
autrui exige le rôle admin ; un non-admin ne peut cibler que lui-même.

### 3. CLI
```bash
# Générique
php app/scripts/import_readings.php --type=electricity --file=elec.csv --user=2 --execute
php app/scripts/import_readings.php --type=gas --file=gaz.csv --value-col="Gaz naturel"

# Enveloppes historiques (chemins/colonnes figés) — délèguent au même service
php app/scripts/import_gaz.php --execute
php app/scripts/import_eau.php --execute
```
Dry-run par défaut ; `--execute` pour écrire ; `--user=<id>` pour la cible
(défaut : 1er compte). Le dry-run exécute l'import dans une transaction annulée
→ compteurs fidèles sans écriture.

### 4. API batch (programmatique, P4 #55)
Pour l'ingestion automatisée volumineuse : `POST ?action=ingest_electricity|
ingest_gas|ingest_water`, ≤ 1000 lectures/requête, jeton Bearer. Voir
[plan/api-ingestion.md](plan/api-ingestion.md).

## Sécurité & limites (upload web)

`multipart/form-data`, `is_uploaded_file()`, contrôle du code d'erreur, plafonds
taille (~8 Mo) et nombre de lignes (~200 000), extensions `csv`/`json`, lecture
en flux, jamais d'exécution ; formulaires protégés par CSRF. Pour de très gros
fichiers, préférer la CLI (pas de limite d'upload HTTP) ; un traitement
asynchrone/job reste un axe de suivi.

## Architecture (code)

- `App\Service\BulkImportService` — cœur (streaming, validation par ligne,
  collecte des erreurs, upsert idempotent). Testable via les fakes d'ingestion.
- `App\Service\Import\RowSource` — lecteurs CSV (flux) / JSON.
- `App\Service\Import\ImportMapping` — presets + surcharges (registres tirés de
  `MeterTopology::ELECTRICITY_REGISTERS`).
- `App\Service\Import\ImportReport` — bilan (compteurs + échantillon d'erreurs).
- `App\Service\Import\ImportTarget` — règle d'autorisation self/admin.
- `App\Service\Import\ImportRunner` — orchestration d'un fichier téléversé (UI).
