# Issue #25 — Phase 4 : Découpe du service webhook (fusion électricité)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 4, point « découpe des gros services ». `DailyLegacyWebhookSyncService`
(390 l., **0 test**) a sa logique la plus complexe — la **fusion par date des 5
jeux de relevés électricité** + l'assemblage des points — entièrement non testée
dans `syncElectricity()`. On l'extrait dans un objet pur, testable, **sans
toucher** à l'orchestration HTTP/BDD (refactor à faible risque).

## Changements

- **[ElectricityReadingMerger](app/src/Service/ElectricityReadingMerger.php)**
  (nouveau) : `build(driesT1, driesT2, driesI1, driesI2, solar)` → fusion par
  date (jour/nuit, import/injection, solaire Wh→kWh), tri, assemblage des points
  EnergyID. Logique pure (utilise `EnergyIdPayloadFactory` pour l'horodatage Unix).
- **[ElectricityPoints](app/src/Service/ElectricityPoints.php)** (nouveau) :
  objet-valeur (points + première/dernière date + horodatage du dernier point).
- **[DailyLegacyWebhookSyncService](app/src/Service/DailyLegacyWebhookSyncService.php)** :
  `syncElectricity()` délègue la fusion/assemblage au merger ; ne garde que
  l'orchestration (fetch → merge → post → état de synchro → log). **Extraction
  verbatim, aucun changement de comportement.**

## Vérification

- Nouveau [ElectricityReadingMergerTest](tests/Unit/Service/ElectricityReadingMergerTest.php) :
  vide, fusion + tri par date, conversion solaire Wh→kWh arrondie, conservation de
  l'horodatage du premier dataset.
- Suite **69 tests / 304 assertions** ; PHPStan niveau 6 (0 erreur) ; `php -l` OK.
- Extraction verbatim + types `array<int,array{timestamp:string,value:string}>`
  (alignés sur les retours des repos) → pas de cascade niveau 6.

## Reste (découpe complète du service webhook — chantier dédié)

La décomposition de l'**orchestration HTTP** (`postWithRetry`, renouvellement de
session, flux `syncUntil`/gas/water) nécessite des **tests de caractérisation
d'abord** (interfaces + fakes pour `EnergyIdV2Client` et les repos), puis la
découpe — ce qui résorberait la dette by-ref de `$session` encore en baseline.
À traiter en effort dédié.
