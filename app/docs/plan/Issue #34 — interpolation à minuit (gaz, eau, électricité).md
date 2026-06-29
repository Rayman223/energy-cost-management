# Issue #34 — Calcul de consommation gaz / eau / électricité : interpolation à minuit

## Contexte

Le calcul mensuel de consommation était faux sur deux points (issue #34, `bug`) :

1. **Clamp au lieu d'extrapoler** : quand le relevé encadrant le mois tombait après
   minuit (relevé manuel à 07:54 le 01/01), le calcul démarrait au relevé et
   ignorait la conso entre minuit et l'heure du relevé.
2. **Relevés intermédiaires ignorés** : seul le couple encadrant le mois était
   utilisé, avec un débit moyen unique sur tout l'intervalle.

Méthode retenue (gaz, eau, électricité) : estimer l'index théorique **à minuit** le
1er de M et le 1er de M+1 par interpolation linéaire entre le couple de relevés le
plus serré ; `conso = index(fin) − index(début)` arrondi à 3 décimales. Bords :
extrapolation arrière au 1er relevé de l'historique ; mois en cours → extrapolation
avant (gaz/eau) si ≥2 relevés, sinon message « relevé manquant ».

## Fichiers impactés

- [MonthlyConsumptionInterpolator.php](app/src/Service/MonthlyConsumptionInterpolator.php)
  (← `GasMonthInterpolator`) — moteur pur partagé (`interpolateValueAt`, `interpolateMonth`).
- [MonthInterpolation.php](app/src/Service/MonthInterpolation.php) (← `GasMonthInterpolation`) — objet-valeur.
- [CostCalculationService.php](app/src/Service/CostCalculationService.php) — `estimateMonthGas`
  réécrit, `estimateMonthWater` ajouté, injection `waterRepo`.
- [GasRepository.php](app/src/Repository/GasRepository.php) / [WaterRepository.php](app/src/Repository/WaterRepository.php)
  — `getReadingsForInterpolation` (fenêtre : dernier avant le mois ∪ relevés du mois ∪ premier après).
- [LegacyDailyRepository.php](app/src/Repository/LegacyDailyRepository.php) — `getMonthlyDeltas[ForMonth]`
  interpolent les bornes à minuit (relevés horaires denses → bornes encadrantes uniquement).
- Interfaces `Repository\Contract\*`, [CostController.php](app/src/Http/Controller/CostController.php)
  + route `water_month_cost` ([api.php](app/public/api.php)).
- Front : [index.php](app/public/index.php), [dashboard.php](app/templates/dashboard.php),
  [dashboard.js](app/public/assets/js/dashboard.js) — section « Consommation eau » (volume m³).
- Tests : `MonthlyConsumptionInterpolatorTest`, `CostCalculationServiceTest`,
  `LegacyDailyRepositoryDbTest`, fakes.

## Étapes

- [x] Moteur générique + tests (dont le cas chiffré de l'issue = 999,754 m³).
- [x] Gaz : repo fenêtré, service réécrit, fakes/tests.
- [x] Électricité : interpolation à minuit dans le repo + test d'intégration.
- [x] Eau : `estimateMonthWater`, route/contrôleur, câblage, UI dashboard (volume).
- [x] Docs (architecture, api-contract).

## Vérification

- `php -l`, **PHPStan niveau 6** (OK), **PHPUnit** (89 tests, 4 intégration skippés sans BDD).
- Bout en bout : saisir deux relevés gaz à heure non ronde → la conso du mois
  récupère le décalage horaire (≈ minuit) ; section « Consommation eau » affichée ;
  mois en cours sans 2e relevé → message « relevé manquant ».
