# Issue #53 — P2 Multi-tenant (étape 2/2 : switch + contract)

## Contexte
Seconde et dernière étape de P2 (épopée #47). L'étape 1 (#62) a créé le nouveau schéma et migré les données ;
cette étape **bascule toutes les lectures/écritures** sur le modèle à registres scopé par utilisateur, puis
**retire les tables legacy** (`Data_*`) du schéma. Clôt #53.

## Architecture
- **`UserContext`** ([app/src/Security/UserContext.php](app/src/Security/UserContext.php)) — tenant courant :
  session OIDC ; en mode Basic Auth, tenant unique (`local/owner`, créé au premier besoin) ; CLI : `--user=<id>`
  sinon premier compte.
- **`MeterTopology`** ([app/src/Infrastructure/MeterTopology.php](app/src/Infrastructure/MeterTopology.php)) —
  résolution/création compteur + registres (source unique, partagée lecture/ingestion/backfill).
- **`ElectricityReadingRepository`** — port complet de l'ancien LegacyDailyRepository sur `meter_readings`
  (interpolation à minuit, deltas jour/heure, premières valeurs du jour pour le webhook, `insertIndexes()` pour le
  cron). Contrat JSON du dashboard conservé (clés `Prelev_jour`…).
- **`UtilityReadingRepository`** — gaz+eau unifiés (`utility_readings`), remplace GasRepository/WaterRepository.
- **`WebhookSyncStateRepository`** — état de sync EnergyID scopé user (PK `(user_id, source_name)` via migration
  `2026-07-02`, baselinée).
- **Supprimés** : `LegacyDailyRepository`, `GasRepository`, `WaterRepository`, `LegacyIngestionRepository`
  (+ leur test BDD). `schema.sql` ne crée plus les tables `Data_*`.

## Déploiement (ordre, non-cassant)
1. Déployer (migrations auto : `webhook_sync_state` gagne `user_id`) — l'app lit/écrit le **nouveau** modèle.
2. Vérifier le dashboard (les données de l'étape 1 sont déjà dans le nouveau modèle).
3. `php app/scripts/finalize_multitenant.php` (dry-run) puis `--execute` : backfill final de **rattrapage**
   (lignes écrites entre l'étape 1 et ce déploiement), réattribution du sync state (user_id=0 → owner),
   **DROP des tables `Data_*`**.
4. Les crons fonctionnent sans changement de crontab (résolution CLI du propriétaire = premier compte).

## Étapes
- [x] UserContext + MeterTopology + WebhookSyncStateRepository
- [x] ElectricityReadingRepository (port interpolation/deltas/webhook + ingestion idempotente)
- [x] UtilityReadingRepository (gaz+eau, scoping user, fenêtre d'interpolation)
- [x] Recâblage : ReadingsController, DailyLegacyWebhookSyncService, api.php, index.php, cron_hourly,
      cron_daily_webhook, import_gaz/import_eau (→ `utility_readings`, `--user`)
- [x] Migration `2026-07-02_webhook_sync_state_user.sql` + baseline complète dans schema.sql
- [x] finalize_multitenant.php (dry-run par défaut) + suppression des classes legacy
- [x] Tests : ElectricityReadingRepositoryDbTest (port des scénarios minuit + isolation user + idempotence),
      UtilityReadingRepositoryDbTest (CRUD, flux, isolation), MultiTenantBackfillDbTest (crée ses tables legacy)

## Vérification
- PHPUnit unit + intégration BDD (CI MariaDB) verts.
- Dashboard complet (deltas mois, graphique, historiques gaz/eau, coûts) sur le nouveau modèle.
- `finalize_multitenant.php` en dry-run liste rattrapage/réattribution/drops sans rien toucher.

## Notes
- `dynamic_prices` reste non scopé (données de marché) ; le scoping par bidding zone arrive en **P3** (#54).
- `tariff_grids.user_id` (catalogue partagé/surcharge) arrive en **P3** avec la refonte tarifaire.
- Le renommage du contrat JSON front (`Prelev_jour` → clés génériques) viendra avec l'API publique (**P4**).
