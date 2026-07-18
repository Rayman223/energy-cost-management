# Issue #162 — Suppression de relevés & ré-import « écraser »

## Contexte

Import eau via CSV avec la **mauvaise unité** → valeurs m³ fausses. La ré-importation ne
corrigeait rien (import `INSERT IGNORE`, idempotent) et aucun moyen n'existait pour
supprimer un relevé fautif ou repartir propre.

Trois réponses (validées avec l'utilisateur) :
- **Supprimer un relevé** sur chaque ligne des 3 tableaux (élec, gaz, eau), avec popup de
  confirmation au design du site.
- **« Tout supprimer »** par fluide (vide les relevés ; pour l'électricité, supprime le
  compteur + registres + relevés en cascade). Équivaut à « supprimer un compteur ».
- **Ré-import « écraser »** : case « Remplacer les valeurs existantes » (upsert
  `ON DUPLICATE KEY UPDATE`) pour corriger un import fautif sans supprimer.

Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/162

## Fichiers impactés

### Suppression — backend
- [app/src/Repository/UtilityReadingRepository.php:70](app/src/Repository/UtilityReadingRepository.php#L70) — `deleteReading(int $id): bool` (scopé user+type), `deleteAll(): int`.
- [app/src/Repository/ElectricityReadingRepository.php:146](app/src/Repository/ElectricityReadingRepository.php#L146) — `deleteReadingAt(DateTimeImmutable): int` (join meters.user_id), `deleteMeter(): int` (cascade), helper `invalidateMonthlyDeltaCaches()`.
- [app/src/Http/Controller/ReadingDeletionController.php](app/src/Http/Controller/ReadingDeletionController.php) — nouveau, câblé avec repos concrets. 6 méthodes (par ligne + « tout »).
- [app/routes/api.php:172](app/routes/api.php#L172) — 6 routes POST session-only (`delete_*_reading`, `delete_*_all`, `delete_electricity_meter`). CSRF : header `application/json` + session (comme `gas_entry`).

### Suppression — frontend
- [app/public/assets/js/confirm.js](app/public/assets/js/confirm.js) — `window.siteConfirm = openConfirm` (usage programmatique).
- [app/templates/meter_readings.php](app/templates/meter_readings.php) — `data-confirm-*` sur `<html>`, `confirm.css`/`confirm.js`, colonne d'action + boutons « Tout supprimer », i18n dans `$meterData`.
- [app/public/assets/js/meter-readings.js](app/public/assets/js/meter-readings.js) — bouton corbeille par ligne (délégation tbody), helper `deleteAndReload`, boutons « Tout supprimer ».

### Ré-import « écraser »
- Contrats [UtilityIngestionInterface](app/src/Repository/Contract/UtilityIngestionInterface.php) / [ElectricityIngestionInterface](app/src/Repository/Contract/ElectricityIngestionInterface.php) — param `bool $replace = false`.
- Repos : `saveIgnore` / `insertIndexes` basculent en `ON DUPLICATE KEY UPDATE` si `$replace`.
- [BulkImportService](app/src/Service/BulkImportService.php) + [ImportRunner](app/src/Service/Import/ImportRunner.php) — propagation du flag ; `runFromRequest` lit `overwrite=1`.
- [app/templates/account.php:291](app/templates/account.php#L291) — case « Remplacer les valeurs existantes ».
- Fakes [FakeUtilityIngestion](tests/Fake/FakeUtilityIngestion.php) / [FakeElectricityIngestion](tests/Fake/FakeElectricityIngestion.php) mis à jour.

### Traductions
- fr/en/nl/de : clés `meter.actions/delete/delete_confirm/delete_all/delete_all_confirm/deleted/delete_error`, `import.overwrite`/`import.overwrite_hint`.

## Étapes

- [x] Repos : méthodes delete* + invalidation caches
- [x] ReadingDeletionController + routes API + wiring
- [x] confirm.js `window.siteConfirm` + branchement page relevés
- [x] meter-readings.js boutons + template colonnes/i18n
- [x] Upsert import (interfaces → repos → service → runner → formulaire)
- [x] Traductions fr/en/nl/de
- [x] Tests (unit BulkImportService replace ; intégration repos, contrôleur, runner)

## Suivi revue de code

- Feedback de suppression : `deleted:0` affiche un message neutre (`meter.delete_none`) au lieu d'une fausse confirmation.
- Zones de feedback dédiées sous chaque tableau (`*-del-feedback`), alignées sur le retrait des cellules ([dashboard.css](app/public/assets/css/dashboard.css) `.gas-history > .form-feedback`), plutôt que la zone du formulaire de saisie.
- Import : avertissement JS dynamique quand « écraser » **et** « simulation » sont cochés ([import.js](app/public/assets/js/import.js), `import.overwrite_dryrun_warning`).

## Vérification

- `php -l` OK sur tous les fichiers modifiés ; `node --check` OK sur les 2 JS.
- `vendor/bin/phpunit tests/Unit` : 284 tests verts.
- PHPStan niveau 6 (config projet) : `[OK] No errors`.
- Tests d'intégration BDD ajoutés (relayés à la CI MariaDB) : suppression par id / horodatage / masse, scoping user+fluide, cascade compteur, upsert de bout en bout.
- Manuel : `/meter-readings` → supprimer une ligne (popup rouge), « Tout supprimer », ré-import avec case « écraser » cochée.
