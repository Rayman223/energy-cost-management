# Issue #25 — Phase 4 : Découpe de CostCalculationService (interpolation gaz)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 4, point « réduire la complexité des gros services ». `estimateMonthGas()`
mélangeait ~57 lignes de **mathématiques d'interpolation pures** avec la
résolution tarif/PCS et le calcul de coût. On extrait l'interpolation dans un
objet dédié, testable indépendamment.

## Changements

- **[GasMonthInterpolator](app/src/Service/GasMonthInterpolator.php)** (nouveau) :
  `interpolate(from, fromM3, to, toM3, year, month)` — toute la logique pure
  (timestamps, bornes du mois, fenêtre de couverture, interpolation linéaire,
  jours de proratisation, cas d'échec).
- **[GasMonthInterpolation](app/src/Service/GasMonthInterpolation.php)** (nouveau) :
  objet-valeur résultat (`available`/`reason` ou valeurs interpolées + métadonnées).
- **[CostCalculationService](app/src/Service/CostCalculationService.php)** :
  `estimateMonthGas()` délègue à l'interpolateur (injecté, défaut
  `new GasMonthInterpolator()` → constructeur rétro-compatible). La méthode passe
  de ~110 à ~55 lignes ; le service ne garde que l'orchestration (relevés → tarif
  → coût). **Aucun changement de comportement.**

## Vérification

- **Filet de sécurité** : les tests `estimateMonthGas` existants
  (`CostCalculationServiceTest` : mois plein, couverture partielle, horodatages
  identiques, pas de relevés) passent → refactor sans régression.
- Nouveau [GasMonthInterpolatorTest](tests/Unit/Service/GasMonthInterpolatorTest.php) :
  mois plein aligné, couverture partielle, interpolation au-delà du mois, horodatages
  identiques, hors couverture, bascule décembre→janvier.
- Suite : **65 tests / 286 assertions** ; PHPStan niveau 6 (0 erreur) ; `php -l` OK.

## Suite (reste Phase 4)

- `DailyLegacyWebhookSyncService` (390 l., 0 test) : **tests de caractérisation
  d'abord**, puis découpe (résorberait la dette by-ref de `$session` en baseline).
