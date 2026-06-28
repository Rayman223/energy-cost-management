# Issue #28 — Ajouter un script de déploiement Unraid (SWAG) par tag git

## Contexte

L'app `energyv2` est servie sur **Unraid** par le container **SWAG** (Nginx + PHP-FPM)
à `/config/www/energyv2/`. Le déploiement / la MAJ du code et du schéma était **manuel**
(clone/pull, deps, schéma à la main) → non reproductible, source d'erreurs.

Objectif : un script bash idempotent qui met à jour `energyv2` depuis un **tag git**
(ou le dernier `main`), lançable en SSH ou via le plugin *User Scripts*.

GH : https://github.com/Rayman223/Manage-energy-costs/issues/28

## Fichiers impactés

- [app/scripts/deploy_unraid.sh](../../scripts/deploy_unraid.sh) — script de déploiement (nouveau, exécutable).
- [README.md](../../../README.md) — sous-section « Déploiement Unraid (SWAG) » après l'Installation.

## Décisions

- **Schéma DB : `schema.sql` seul** (`CREATE TABLE IF NOT EXISTS`, idempotent). Les
  migrations `app/sql/migrations/*.sql` (dont un `ALTER ... DROP INDEX` non-idempotent)
  restent **manuelles** — runner versionné = follow-up hors périmètre.
- **Pas** d'étape `assets:build` (pas de pipeline d'assets) ni de `migrate_db.php`
  (migration de données one-shot).
- Credentials DB lus depuis `config.php` (source unique) via le PHP du container SWAG.

## Étapes

- [x] Script `deploy_unraid.sh` : en-tête doc + `set -euo pipefail` + variables de config + `log()` horodaté.
- [x] Étape Git : `init`/`remote` idempotents, `fetch --depth 1` (tag ou main), `reset --hard FETCH_HEAD`, `checkout -f`, `git clean -fd` **sans `-x`** (préserve `config.php` et `vendor/`).
- [x] Étape deps : `composer install --no-dev --optimize-autoloader` dans le container SWAG.
- [x] Étape schéma : lecture creds depuis `config.php`, `docker exec -i mariadb mysql … < app/sql/schema.sql`.
- [x] Doc README + en-tête script (usage, exemple `deploy_unraid.sh beta-0.3`, User Scripts).

## Vérification

- `bash -n app/scripts/deploy_unraid.sh` → OK (shellcheck non dispo en local, relancé en CI si configuré).
- Revue manuelle des chemins/containers (pas d'exécution réelle hors Unraid).
- `git clean -fd` ne supprime pas `app/config/config.php` (ignoré) — absence de `-x` vérifiée.
- Sur Unraid : `deploy_unraid.sh beta-0.3` met à jour le code, lance composer, applique `schema.sql`.
