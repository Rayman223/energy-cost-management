# Issue #70 — Energyid en module (système de connecteurs d'export)

## Contexte

Transformer la partie EnergyID en **module** avec un **système de gestion de
modules**, afin d'ajouter facilement d'autres sites externes.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/70

Avant : EnergyID était câblé en dur (4 services `App\Service`, un repository et
une table dédiés, un cron dédié, une section codée à la main dans « Mon compte »).
Aucune abstraction « module/connecteur ». Ajouter un site externe imposait de
dupliquer tout ce câblage.

Périmètre retenu (validé) : abstraction ciblée **« connecteur d'export »** (push
sortant, opt-in par utilisateur). EnergyID = 1er module. ENTSO-E (pull de prix)
non touché. Table générique `user_integrations` (settings JSON). UI générique.
PR non-cassante (parité fonctionnelle EnergyID).

## Fichiers impactés

- [app/src/Domain/SyncStateKeys.php](../../src/Domain/SyncStateKeys.php) — constantes de flux `webhook_sync_state` extraites (dépendance inverse).
- [app/src/Integration/ExportModuleInterface.php](../../src/Integration/ExportModuleInterface.php) — contrat de connecteur d'export.
- [app/src/Integration/IntegrationStatus.php](../../src/Integration/IntegrationStatus.php) — VO de statut UI (state + lignes libres).
- [app/src/Integration/ModuleRegistry.php](../../src/Integration/ModuleRegistry.php) — registre à liste manuelle.
- [app/src/Integration/EnergyId/](../../src/Integration/EnergyId/) — module EnergyId + 4 services déplacés (git mv depuis `App\Service`) + `ElectricityPoints`.
- [app/src/Repository/UserIntegrationRepository.php](../../src/Repository/UserIntegrationRepository.php) — opt-in générique par (user, module), settings JSON.
- [app/sql/schema.sql](../../sql/schema.sql) + [migration 2026-07-18](../../sql/migrations/2026-07-18_user_integrations.sql) — table `user_integrations` + reprise des données.
- [app/scripts/cron_export_sync.php](../../scripts/cron_export_sync.php) — cron générique (itère modules × users). `cron_daily_webhook.php` → alias déprécié.
- [app/routes/account.php](../../routes/account.php) + [partials/integration-card.php](../../templates/partials/integration-card.php) — UI générique + actions POST `integration_enable/disable`.
- `app/translations/{fr,en,nl,de}.php` — clés `integration.energyid.*` + `account.integration_unknown`.
- [app/src/Service/AccountDataExporter.php](../../src/Service/AccountDataExporter.php) — export RGPD `"integrations"` depuis `user_integrations`.

## Étapes

- [x] Extraire `SyncStateKeys` des constantes de `DailyLegacyWebhookSyncService` + adapter `MeterEntryController`, `ImportRunner`.
- [x] Table `user_integrations` + migration idempotente + `UserIntegrationRepository` (+ DbTest).
- [x] Contrat `ExportModuleInterface` + `IntegrationStatus` + `ModuleRegistry` + `EnergyIdModule` (git mv des services) + tests unitaires.
- [x] Cron générique `cron_export_sync.php` + `cron_daily_webhook.php` alias déprécié + README.
- [x] UI générique (registre + partiel) + i18n renommée + export RGPD généralisé + suppression de l'ancien repository ; `AccountRgpdDbTest` adapté.
- [x] Commentaire `ConfigSchema` + ce plan + issue de suivi « DROP energyid_integrations ».

## Vérification

1. `php -l` sur les fichiers modifiés ; **PHPStan niveau 6 vert** ; `vendor/bin/phpunit tests/Unit` vert (293 tests).
2. Migration `2026-07-18_user_integrations.sql` sur une base de dev avec un opt-in EnergyID → ligne migrée avec `settings.device_id` / `settings.claimed_at` corrects (idempotence vérifiée par `UserIntegrationRepositoryDbTest`).
3. `php app/scripts/cron_export_sync.php` → itère modules × users (skip si kill-switch) ; `cron_daily_webhook.php` → warning de dépréciation + même exécution.
4. Page « Mon compte » : carte EnergyID rendue à l'identique dans les 4 locales (états disabled/pending/active — validé par rendu réel du partiel) ; enable/disable fonctionnels ; `module_key` inconnu rejeté.
5. Watermark : relevé antidaté → `rewindLastSentAt` inchangé (clés `webhook_sync_state` identiques).
6. RGPD : export contient la clé `integrations` ; suppression de compte purge `user_integrations` (CASCADE).
