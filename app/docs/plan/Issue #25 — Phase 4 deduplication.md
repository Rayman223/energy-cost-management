# Issue #25 — Phase 4 : Refactor backend & qualité (déduplication ciblée)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 4. Premier incrément : **déduplication ciblée**, à faible risque et gardée
par les tests existants.

(Mesure préalable : PHPStan niveau 6 = 52 erreurs, niveau 7 = 80 — surtout des
types d'array manquants dans les gros fichiers legacy ; le durcissement du niveau
sera un incrément dédié ultérieur.)

## Changements

### 1. Catalogue des lignes tarifaires (source unique)

[app/src/Domain/TariffLineCatalog.php](app/src/Domain/TariffLineCatalog.php) —
`electricity()`, `gas()`, `forType()`, `keysFor()`. Les clés électricité/gaz
étaient listées **2×** dans `tariffs.php` (la liste `$lineKeys` de validation POST
**et** les définitions `$elecLines`/`$gasLines` d'affichage). Désormais une seule
source.

- [app/public/tariffs.php](app/public/tariffs.php) : `$lineKeys = TariffLineCatalog::keysFor($energyType)`,
  `$elecLines = TariffLineCatalog::electricity()`, `$gasLines = TariffLineCatalog::gas()`.
  Données **identiques** → aucun changement d'affichage ni de validation.

### 2. Helpers `TariffCalculatorService`

[app/src/Service/TariffCalculatorService.php](app/src/Service/TariffCalculatorService.php) —
deux helpers privés factorisent des motifs répétés :
- `wholeMonths(int $days): int` — abonnement mensuel (était dupliqué élec/gaz,
  avec une incohérence de cast int corrigée au passage, **sans effet numérique**).
- `prorateAnnual(float $annual, int $days): float` — proratisation des forfaits
  annuels (`/365`), répétée 6× → 1 helper.

## Vérification

- Filet de sécurité : les **10 tests existants de `TariffCalculatorService`**
  (Phase 0) passent → factorisation sans changement de comportement.
- Nouveau test [tests/Unit/Domain/TariffLineCatalogTest.php](tests/Unit/Domain/TariffLineCatalogTest.php)
  (clés, labels/units, `keysFor`, `forType`).
- Suite : **48 tests / 223 assertions**.
- `php -l` OK ; PHPStan niveau 5 — 0 erreur.

## Suite (reste Phase 4)

- Découpe des gros services (`CostCalculationService`, `DailyLegacyWebhookSyncService`).
- Durcissement PHPStan (niveau 6) — incrément dédié.
- Interfaces sur les repos restants (Legacy/Gas/Water) pour tester les contrôleurs.
