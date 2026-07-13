# Issue #130 — Audit global : corriger 14 bugs

## Contexte

Audit complet du projet (3 passes) en préparation de #122. Cette issue regroupe
les 14 corrections de bugs, dont 1 critique (installation neuve cassée par le
seed `schema_migrations`). Optimisations → #131 ; dettes OIDC → #122.
Ordre d'exécution acté : **#130 → #122 → #131**.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/130

## Fichiers impactés

- [app/sql/schema.sql](app/sql/schema.sql#L222) — seed `schema_migrations` (C1)
- [.github/workflows/ci.yml](.github/workflows/ci.yml#L139) — étape anti-récidive migrate (C1)
- [app/routes/auth/logout.php](app/routes/auth/logout.php) — POST + CSRF (A2)
- [app/templates/*.php](app/templates/) — logout en formulaire POST (A2)
- [app/public/assets/css/tokens.css](app/public/assets/css/tokens.css) — `.logout-form`, `.link-button` (A2)
- [app/src/Http/Request.php](app/src/Http/Request.php#L28) — décodage JSON gaté par Content-Type + méthodes à corps (C3+B7)
- [app/src/Service/CostCalculationService.php](app/src/Service/CostCalculationService.php#L350) — `computeDays` en `[from, to)` (B1)
- [app/src/Repository/WebhookSyncStateRepository.php](app/src/Repository/WebhookSyncStateRepository.php) — `rewindLastSentAt` (B2)
- [app/src/Http/Controller/MeterEntryController.php](app/src/Http/Controller/MeterEntryController.php) — rewind après insert antidaté + validation ≥ 0 + virgule (B2, B8, B4)
- [app/src/Service/Import/ImportRunner.php](app/src/Service/Import/ImportRunner.php) + [ImportReport.php](app/src/Service/Import/ImportReport.php) + [BulkImportService.php](app/src/Service/BulkImportService.php) — rewind après import antidaté (B2)
- [app/src/Repository/ElectricityReadingRepository.php](app/src/Repository/ElectricityReadingRepository.php#L60) — `insertIndexes` transactionnel (B3)
- [app/translations/nl.php](app/translations/nl.php), [de.php](app/translations/de.php) — 24 clés `meter.*`/`nav.meter_readings` (C2)
- [app/src/Service/EntsoePriceParser.php](app/src/Service/EntsoePriceParser.php) — anti-collision DST (B5)
- [app/src/Service/EnergyIdPayloadFactory.php](app/src/Service/EnergyIdPayloadFactory.php), CostCalculationService — commentaires fuseau (B6)
- [app/templates/tariffs.php](app/templates/tariffs.php) — lien mort migrate_db supprimé (C5)
- [app/routes/{account,admin,tariffs,privacy,terms,api}.php](app/routes/) — bootstrap en try/catch dégradé (C6)
- [app/routes/dashboard.php](app/routes/dashboard.php#L130) + template — `syncStatus` en booléens (C7)

## Étapes

- [x] C1 — Seed 07-06→07-11 dans `schema_migrations` + étape CI `migrate.php --dry-run`
- [x] A2 — Logout POST+CSRF, 5 templates en formulaire, CSS bouton-lien
- [x] C3+B7 — `Request::fromGlobals` : décodage si `application/json` + POST/PUT/PATCH/DELETE
- [x] B1 — `computeDays` sans `+1` (convention gaz), tests à 14/12/1 jours
- [x] B2 — `rewindLastSentAt` (LEAST) appelé après saisie manuelle et import antidaté
- [x] B3 — boucle `insertIndexes` dans transaction (garde `inTransaction()`)
- [x] C2 — traductions NL/DE + test de parité des clés
- [x] Mineurs B4 (doc TOCTOU), B5 (DST), B6 (commentaires fuseau), B8 (≥0 + virgule), C5, C6, C7

## Vérification

1. `php -l` sur chaque fichier modifié → OK.
2. `vendor/bin/phpunit --testsuite unit` → 217 tests OK (dont B1, parité i18n, gating Request).
3. Suite `integration` → rewind watermark (B2) exercé en CI (base MariaDB).
4. `phpstan analyse` niveau 6 (PHAR 2.2.2) → No errors, **baseline inchangée**.
5. CI : nouvelle étape « schema.sql + migrations synchronisés » échoue si le seed diverge.
