# Audit SQL & optimisation (Phase 5, épopée #25)

Audit des requêtes des repositories vs index du schéma. Réalisé **sans base
accessible** : les constats reposent sur l'analyse statique des requêtes et du
schéma ; les réécritures non vérifiables par exécution sont **reportées**.

## Couverture des index (schéma)

> **Instantané daté.** Ce tableau reflète le schéma au moment de l'audit : les
> tables `Data_*` sont l'ancien modèle mono-tenant, remplacé depuis par
> `meter_readings` / `utility_readings`. Il n'est pas tenu à jour au fil des
> migrations — seule la ligne `webhook_sync_state` en a été retirée, la table
> ayant été supprimée avec l'intégration EnergyID (#53).

| Table | Colonnes filtrées / triées / jointes | Index | Verdict |
|---|---|---|---|
| `Data_Dries` | `timestamp` (ranges, ORDER BY, JOIN) | `UNIQUE(timestamp)` | OK pour les ranges/tri ; **KO** pour les filtres `YEAR()/MONTH()/DATE()` (non-sargables) |
| `Data_Solaire` / `Data_Brusol` | `timestamp` | `UNIQUE(timestamp)` | idem |
| `Data_gaz` / `Data_eau` | `reading_at` (ranges, ORDER BY) | `UNIQUE(reading_at)` **+ `INDEX(reading_at)` redondant** | sargable OK ; index simple **doublon** |
| `tariff_grids` | `energy_type`, `valid_from` | `INDEX(energy_type, valid_from)` | OK |
| `tariff_grid_lines` | `tariff_grid_id` (JOIN / IN) | index implicite de la FK | OK (N+1 déjà résolu par `fetchLinesForIds`) |

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

## Corrigé : filtres non-sargables → prédicats de plage (validé sur MariaDB)

3. Dans `LegacyDailyRepository`, les requêtes mensuelles/du-jour utilisaient
   `WHERE YEAR(timestamp)=… AND MONTH(timestamp)=…` et
   `WHERE DATE(timestamp)=CURDATE()` : la fonction sur la colonne empêchait
   l'usage de l'index `timestamp`. Réécrit en prédicats de plage :

   ```sql
   -- mois : WHERE YEAR(timestamp)=:y AND MONTH(timestamp)=:m
   WHERE timestamp >= :month_start
     AND timestamp <  :month_start + INTERVAL 1 MONTH   -- INTERVAL gère la bascule déc→jan

   -- mois courant : DATE_FORMAT(CURDATE(), '%Y-%m-01') ...
   -- jour : WHERE DATE(timestamp)=CURDATE()
   WHERE timestamp >= CURDATE() AND timestamp < CURDATE() + INTERVAL 1 DAY
   ```

   **Validation sur une base MariaDB de test** (`energ_test`) :
   - **Équivalence** : ancien vs nouveau renvoient des résultats identiques sur 12
     cas (mois courant, précédent, **bascule déc→jan**, mois futur vide…).
   - **`EXPLAIN`** : l'ancien faisait `type=index … Using where` (scan complet de
     l'index) ; le nouveau → `Select tables optimized away` (seek de plage sur
     l'index, MIN optimisé) → gain réel sur des tables qui grossissent.
   - **Bout-en-bout** : les méthodes réelles renvoient les bons deltas (11 contrôles).
   - **Régression** : `tests/Integration/LegacyDailyRepositoryDbTest` (transaction
     annulée, garde « base test ») — **s'exécute si une BDD est joignable, se skippe
     sinon** (CI sans base → vert).
