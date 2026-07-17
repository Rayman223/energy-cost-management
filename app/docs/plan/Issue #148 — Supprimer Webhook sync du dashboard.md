# Issue #148 — Supprimer « Webhook sync — état » du dashboard

## Contexte
Reliquat visuel de la V2 : le dashboard affiche une section d'en-tête **vide**
« Webhook sync — état » (rien rendu dessous) et un badge header mêlant une pastille
de synchro morte à un libellé « Sync ». Devenu obsolète → à retirer.

À bien distinguer du **moteur de sync EnergyID** (`WebhookSyncStateRepository`,
`DailyLegacyWebhookSyncService`, cron `cron_daily_webhook.php`, table `webhook_sync_state`),
qui reste **vivant** (cron quotidien, import CSV, saisie de relevés, export/effacement RGPD)
et n'est **pas** touché.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/148

## Fichiers impactés
- [app/routes/dashboard.php](../../routes/dashboard.php) — retrait `$syncStatus`/`$syncState` + import.
- [app/templates/dashboard.php](../../templates/dashboard.php) — retrait du badge header + section vide.
- [app/public/assets/css/dashboard.css](../../public/assets/css/dashboard.css) — retrait styles `.sync-badge`/`.sync-dot` + bloc mort `.sync-table`.
- [app/translations/*.php](../../translations/) — retrait clé orpheline `dash.sync` (fr/en/de/nl).
- [app/src/Http/Controller/ReadingsController.php](../../src/Http/Controller/ReadingsController.php) — retrait méthode `syncStatus()` + dépendance `WebhookSyncStateRepository`.
- [app/routes/api.php](../../routes/api.php) — retrait arg `$syncState` de `ReadingsController` + route `sync_status`. `$syncState` conservé (requis par `MeterEntryController`).

## Étapes
- [x] Retrait affichage dashboard (route + template + CSS + i18n).
- [x] Retrait endpoint `sync_status` (`ReadingsController` + `api.php`).
- [ ] `php -l` + relecture des diffs, PR `Closes #148`.

## Nettoyage V2/legacy supplémentaire
- **Supprimés** (migration multi-tenant terminée, confirmée sur le serveur QA V3 multi-user) :
  `app/scripts/backfill_multitenant.php`, `app/scripts/finalize_multitenant.php` et la classe
  `app/src/Infrastructure/MultiTenantBackfill.php` (utilisée uniquement par ces deux scripts).
  Références de doc mises à jour (`installation.md`, `README.md`, `schema.sql`, migration `2026-07-02`).
- **Reporté à l'issue #70** (« Energyid en module ») : le moteur EnergyID/webhook complet, nommé
  « Legacy/V2 » mais vivant via le cron → décision produit (commentaire ajouté sur #70).

## Vérification
- `php -l` sur les fichiers PHP modifiés.
- Dashboard : plus de badge ni de section « Webhook sync — état », aucune erreur PHP.
- `GET /api?action=sync_status` → route inconnue (404).
- Saisie d'un relevé → `MeterEntryController` OK (watermarks via `$syncState` intact).
