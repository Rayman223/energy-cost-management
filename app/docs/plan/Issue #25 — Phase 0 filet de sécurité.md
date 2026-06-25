# Issue #25 — Phase 0 : Filet de sécurité (tests + contrat d'API)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25) :
refactorisation, optimisation et découplage front/back. Avant tout refactoring,
on installe un **filet de sécurité** : tests automatisés sur les calculs de
coûts (logique la plus à risque) + documentation du contrat actuel de l'API.
C'est le premier item de la liste de l'épopée.

Décisions tranchées : outillage **Composer + PHPUnit** (`require-dev`, `vendor/`
ignoré) ; couverture **calculs purs + interfaces repo** ; **PHPUnit ^10.5**
(compat PHP 8.1) ; tests HTTP de `api.php` reportés en Phase 3 → ici on
**documente** le contrat.

## Fichiers impactés

- [app/autoload.php](app/autoload.php) — **nouveau**, autoloader `App\` extrait
  de bootstrap.
- [app/bootstrap.php](app/bootstrap.php) — délègue à `autoload.php`.
- `composer.json`, `phpunit.xml.dist`, [tests/bootstrap.php](tests/bootstrap.php) —
  outillage de test.
- `.gitignore` — `/vendor/`, `/.phpunit.result.cache`.
- `app/src/Repository/Contract/*.php` — **nouvelles** interfaces (seam de test).
- Repos concrets (`implements`) : [LegacyDailyRepository](app/src/Repository/LegacyDailyRepository.php),
  [TariffRepository](app/src/Repository/TariffRepository.php),
  [GasRepository](app/src/Repository/GasRepository.php),
  [DynamicPriceRepository](app/src/Repository/DynamicPriceRepository.php).
- [CostCalculationService](app/src/Service/CostCalculationService.php) — type-hints
  sur les interfaces.
- `tests/Unit/Service/*Test.php`, `tests/Fake/*.php` — suites de tests.
- [app/docs/api-contract.md](app/docs/api-contract.md), [app/docs/page-states.md](app/docs/page-states.md) — doc de référence.
- [.github/workflows/ci.yml](.github/workflows/ci.yml) — job `tests`.

## Étapes

- [ ] Extraire `app/autoload.php` + adapter `bootstrap.php`
- [ ] `composer.json` + `phpunit.xml.dist` + `tests/bootstrap.php` + `.gitignore`
- [ ] Interfaces `Repository/Contract/` + `implements` + type-hints du service
- [ ] `TariffCalculatorServiceTest` (calculs purs)
- [ ] Fakes + `CostCalculationServiceTest`
- [ ] `api-contract.md` + `page-states.md`
- [ ] Job CI `tests`
- [ ] Vérifs : phpunit vert, `php -l`, PHPStan niveau 5 (baseline non augmentée)

## Vérification

1. `composer install` puis `vendor/bin/phpunit` → tous les tests verts.
2. `php -l` sur les fichiers modifiés.
3. `phpstan analyse --configuration=phpstan.dist.neon` → 0 erreur.
4. Smoke : charger `index.php` / un endpoint API en local (autoloader + interfaces
   n'ont rien cassé).
5. PR avec `Closes #25 (Phase 0)` ; CI = lint + PHPStan + tests.
