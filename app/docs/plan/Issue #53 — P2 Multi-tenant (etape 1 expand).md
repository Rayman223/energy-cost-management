# Issue #53 — P2 Multi-tenant (étape 1/2 : expand + migration des données)

## Contexte
Troisième phase de l'épopée #47. P2 cloisonne les données par utilisateur et généralise le modèle de relevés.
Vu l'ampleur, livré en **2 étapes non-cassantes** (expand-contract) :
- **Étape 1/2 (cette PR)** : créer le nouveau schéma **à côté** des tables `Data_*` et **migrer les données** historiques
  vers le compte propriétaire. L'application continue de lire/écrire les anciennes tables → **zéro régression**.
- **Étape 2/2 (PR suivante)** : basculer repositories/lectures/écritures sur le nouveau modèle, puis supprimer les
  tables `Data_*` (contract).

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/53

## Fichiers (étape 1)
- [app/sql/migrations/2026-07-01_multitenant_index_tables.sql](app/sql/migrations/2026-07-01_multitenant_index_tables.sql) + [app/sql/schema.sql](app/sql/schema.sql) — `meters`, `meter_registers`, `meter_readings`, `utility_readings`.
- [app/src/Infrastructure/MultiTenantBackfill.php](app/src/Infrastructure/MultiTenantBackfill.php) — migration des données `Data_*` → nouveau modèle.
- [app/scripts/backfill_multitenant.php](app/scripts/backfill_multitenant.php) — CLI one-shot (`--user=<id>` optionnel).
- tests/Integration/MultiTenantBackfillDbTest.php.

## Modèle
- **Électricité/solaire** : `meters` → `meter_registers` (jusqu'à 5 : import_t1/t2, export_t1/t2, production) → `meter_readings`.
- **Gaz + eau** : `utility_readings(user_id, energy_type[gas|water], reading_at, counter_m3)`.
- Tout scopé `user_id`, contraintes `UNIQUE` composites.

## Rattachement des données au propriétaire
**Le compte doit exister au préalable** : on crée d'abord son compte (connexion OIDC), puis on lance le backfill.
Résolution du propriétaire :
1. `--user=<id>` explicite s'il est fourni ;
2. sinon le premier compte de `users` ;
3. sinon **échec explicite** (aucun compte → se connecter d'abord).
`Data_Brusol` n'est **pas** reprise (abandonnée) ; réimport possible via l'import générique (#59).

## Étapes
- [x] Nouveau schéma (migration idempotente + schema.sql).
- [x] Backfill idempotent (INSERT IGNORE) + résolution du propriétaire.
- [x] Test d'intégration (rattachement au 1er utilisateur, création owner si vide, idempotence).
- [ ] *(étape 2)* Repositories scopés + bascule lectures/écritures + suppression des `Data_*`.

## Vérification
- `php app/scripts/migrate.php` crée les 4 tables (idempotent).
- Se connecter via OIDC (crée ton compte), puis `php app/scripts/backfill_multitenant.php` → tes données historiques
  sont copiées sous ton compte ; le script affiche l'utilisateur cible et les compteurs.
- Ré-exécution du backfill : 0 ligne recopiée (idempotent). App inchangée (lit toujours les `Data_*`).
