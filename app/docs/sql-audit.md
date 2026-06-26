# Audit SQL & optimisation (Phase 5, épopée #25)

Audit des requêtes des repositories vs index du schéma. Réalisé **sans base
accessible** : les constats reposent sur l'analyse statique des requêtes et du
schéma ; les réécritures non vérifiables par exécution sont **reportées**.

## Couverture des index (schéma)

| Table | Colonnes filtrées / triées / jointes | Index | Verdict |
|---|---|---|---|
| `Data_Dries` | `timestamp` (ranges, ORDER BY, JOIN) | `UNIQUE(timestamp)` | OK pour les ranges/tri ; **KO** pour les filtres `YEAR()/MONTH()/DATE()` (non-sargables) |
| `Data_Solaire` / `Data_Brusol` | `timestamp` | `UNIQUE(timestamp)` | idem |
| `Data_gaz` / `Data_eau` | `reading_at` (ranges, ORDER BY) | `UNIQUE(reading_at)` **+ `INDEX(reading_at)` redondant** | sargable OK ; index simple **doublon** |
| `tariff_grids` | `energy_type`, `valid_from` | `INDEX(energy_type, valid_from)` | OK |
| `tariff_grid_lines` | `tariff_grid_id` (JOIN / IN) | index implicite de la FK | OK (N+1 déjà résolu par `fetchLinesForIds`) |
| `webhook_sync_state` | `source_name` | `PRIMARY KEY` | OK |

## Corrigé dans cette PR (gains sûrs, sans risque non vérifiable)

1. **Doublon de requête éliminé** — `LegacyDailyRepository::getMonthlyDeltas()` est
   appelé deux fois au rendu du dashboard (directement + via
   `estimateCurrentMonthElectricity()`), soit ~8 requêtes au lieu de 4. Désormais
   **mémoïsé par instance** (sûr : les relevés ne changent pas au sein d'une
   requête web ; l'écriture se fait par cron).
2. **Index redondants supprimés** — `idx_data_gaz_date` / `idx_data_eau_date`
   dupliquaient `UNIQUE(reading_at)`. Migration
   [2026-06-26_drop_redundant_reading_indexes.sql](app/sql/migrations/2026-06-26_drop_redundant_reading_indexes.sql)
   + `schema.sql` mis à jour. Gain : un index de moins à maintenir par INSERT.

## Reporté (nécessite une base pour valider)

3. **Filtres non-sargables → prédicats de plage.** Dans `LegacyDailyRepository`,
   les requêtes mensuelles/du-jour utilisent `WHERE YEAR(timestamp)=… AND
   MONTH(timestamp)=…` et `WHERE DATE(timestamp)=CURDATE()`. La fonction sur la
   colonne empêche l'usage de l'index `timestamp` (scan). La réécriture sémantique
   équivalente :

   ```sql
   -- au lieu de : WHERE YEAR(timestamp)=:y AND MONTH(timestamp)=:m
   WHERE timestamp >= :monthStart
     AND timestamp <  :monthStart + INTERVAL 1 MONTH
   ```

   rendrait l'index utilisable (gain réel sur des tables qui grossissent). À faire
   **avec une base de validation** (les requêtes legacy n'ont pas de test ; pas de
   BDD en CI), de préférence avec un jeu de données de contrôle avant/après.
