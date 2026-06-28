# Issue #18 — Ajouter la possibilité d'utiliser un tarif dynamique

## Contexte

Tarif dynamique day-ahead (ENTSO-E) dont **seule la part énergie** suit le prix de marché belge,
les autres postes restant ceux du tarif classique régulé. Affichage en **comparaison** (classique
vs dynamique), par jour et par mois. Implémenté **après le refactor #25** (couche HTTP
Router/Controllers, interfaces `Repository/Contract`, vues/assets, tests PHPUnit, PHPStan niveau 6).

Décisions : ENTSO-E A44 (zone BE, token) · prix 15 min stocké, croisé à la conso horaire (moyenne
des 4 quarts) · énergie seule indexée · vue comparaison (pas de préférence persistée).
GH : https://github.com/Rayman223/Manage-energy-costs/issues/18

## Fichiers impactés

- [app/sql/schema.sql](../../sql/schema.sql) + [migration](../../sql/migrations/2026-06-27_dynamic_prices.sql) — table `dynamic_prices`
- [config.example.php](../../config/config.example.php) — bloc `dynamic_prices`
- [HttpClient::get()](../../src/Infrastructure/HttpClient.php)
- [EntsoePriceParser](../../src/Service/EntsoePriceParser.php) (pur) + [EntsoePriceClient](../../src/Service/EntsoePriceClient.php) (HTTP)
- [DynamicPriceRepository](../../src/Repository/DynamicPriceRepository.php) + [interface](../../src/Repository/Contract/DynamicPriceRepositoryInterface.php)
- [LegacyDailyRepository::getHourlyImportDeltas](../../src/Repository/LegacyDailyRepository.php) (+ interface + fake)
- [TariffCalculatorService](../../src/Service/TariffCalculatorService.php) — helper commun + `calculateElectricityCostDynamic`
- [CostCalculationService](../../src/Service/CostCalculationService.php) — estimations dynamiques + repli classique
- [CostController](../../src/Http/Controller/CostController.php) — sous-clé `dynamic` sur `monthCost`/`costEstimate`
- [api.php](../../public/api.php) / [index.php](../../public/index.php) — câblage du repo dynamique
- [dashboard.js](../../public/assets/js/dashboard.js) — carte de comparaison + détail/jour
- [cron_dynamic_prices.php](../../scripts/cron_dynamic_prices.php) — fetch quotidien
- Tests : `EntsoePriceParserTest`, extensions `TariffCalculatorServiceTest` / `CostCalculationServiceTest`, `FakeDynamicPriceRepository`

## Étapes

- [x] Schéma `dynamic_prices` (+ migration) et bloc de config
- [x] `HttpClient::get()`
- [x] `EntsoePriceParser` (pur, fill-forward, €/MWh→€/kWh, UTC→local) + `EntsoePriceClient`
- [x] `DynamicPriceRepository` + interface Contract + fake
- [x] `getHourlyImportDeltas` (interface + repo + fake)
- [x] `TariffCalculatorService` : helper commun + variante dynamique
- [x] `CostCalculationService` : estimations dynamiques + repli + couverture + détail/jour
- [x] `CostController` + `api.php` + `index.php` : sous-clé `dynamic`
- [x] `dashboard.js` : carte comparaison
- [x] `cron_dynamic_prices.php` + README
- [x] Tests PHPUnit + PHPStan niveau 6

## Vérification

- `php -l`, **PHPStan niveau 6**, **`composer test`** verts.
- Invariant : dynamique avec énergie = T1+T2 ⇒ total identique au classique (composants fixes inchangés).
- Parser ENTSO-E : 24 intervalles, fill-forward, conversion, UTC→local, acquittement.
- End-to-end (hors CI) : migration, `cron_dynamic_prices.php` (token), dashboard → carte comparaison,
  `api.php?action=month_cost` expose `dynamic`.
