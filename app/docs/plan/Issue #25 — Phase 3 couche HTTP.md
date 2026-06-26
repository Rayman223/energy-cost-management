# Issue #25 — Phase 3 : Couche HTTP / contrôleurs

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 3. `api.php` mélangeait routing (`match`), handlers inline, validation et
gestion d'erreurs en un seul fichier de 234 lignes. On extrait une vraie couche
HTTP (`src/Http/`) : primitives réutilisables + contrôleurs dédiés, `api.php`
devenant un fichier de câblage.

## Fichiers

- [app/src/Http/Request.php](app/src/Http/Request.php) — requête (méthode, query,
  corps JSON) ; `fromGlobals()` ; helpers `queryInt`, `input`, et parsing de
  dates `parseDate`/`optionalDate` (lèvent `ValidationException`).
- [app/src/Http/JsonResponse.php](app/src/Http/JsonResponse.php) — statut + data,
  `ok()`/`error()`, `send()` (mêmes drapeaux JSON que l'ancien `jsonOut`).
- [app/src/Http/ValidationException.php](app/src/Http/ValidationException.php) — 422.
- [app/src/Http/Router.php](app/src/Http/Router.php) — `add(method, action, handler)`
  + `dispatch()` ; reproduit à l'identique 405 / 400 (messages GET vs POST) /
  422 (ValidationException) / 500 (Throwable).
- `app/src/Http/Controller/` — `MeterController` (live), `ReadingsController`
  (today, monthly_delta, chart_data, gas/water_history, sync_status),
  `CostController` (month_cost, cost_estimate, gas_cost, gas_month_cost),
  `TariffController` (tariffs, save_tariff), `MeterEntryController` (gas/water_entry,
  logique de validation **factorisée**).
- [app/public/api.php](app/public/api.php) — bootstrap + sécurité + connexion DB
  (503) + enregistrement des routes + `dispatch()->send()`.

## Comportement préservé (parité stricte)

En-têtes, `WebAccessGuard::protect(..., true)`, connexion DB d'abord (503), les
12 routes GET + 3 POST, messages 400 (« Unknown action » / « Unknown POST action »),
405, 422 (messages de validation identiques), 500, drapeaux JSON.

## Vérification

1. `php -l` sur tous les fichiers — OK.
2. PHPStan niveau 5 — 0 erreur.
3. **Tests** : `tests/Unit/Http/` — Request, JsonResponse, Router (200/400/405/422/500),
   CostController (via fakes Phase 0). Suite : **42 tests / 131 assertions**.
4. **Smoke** (serveur intégré, DB bidon) : 503 au format exact + en-têtes
   (Content-Type json, nosniff, no-store), aucune erreur fatale.

## Suite (reste Phase 3)

- Front : les pages consomment déjà `api.php` via `fetch` (dashboard.js) ;
  vérifier/compléter là où pertinent.
- Centralisation `WebAccessGuard` (déjà au point d'entrée API).
