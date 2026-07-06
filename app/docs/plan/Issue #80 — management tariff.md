# Issue #80 — management tariff

## Contexte
Refonte complète de la gestion des tarifs : ajout du tarif **eau** (aujourd'hui
sans coût), **formulaire dynamique par pays** (moteur de calcul générique par
type de composante), **templates** de tarifs importables/créables, **dropdowns**
pays européens (défaut = profil) et devise, **TVA par grille**, et refonte
visuelle de la page en parcours guidé.
GH : https://github.com/Rayman223/Manage-energy-costs/issues/80

## Décisions
- Moteur générique : chaque ligne porte un `component_kind` (énergie/€kWh/€m³/fixe…).
- TVA : taux par grille (`vat_rate`), montants toujours saisis TTC.
- Templates : builtin en PHP (`TariffTemplateCatalog`), templates user en BDD.
- Design : onglets énergie + panneau « Point de départ » + formulaire groupé.

## Fichiers impactés
- [app/sql/migrations/2026-07-06_tariff_templates_water.sql](app/sql/migrations/2026-07-06_tariff_templates_water.sql) — schéma
- [app/sql/schema.sql](app/sql/schema.sql) — baseline
- [app/src/Domain/ComponentKind.php](app/src/Domain/ComponentKind.php) — enum kinds
- [app/src/Domain/TariffLine.php](app/src/Domain/TariffLine.php) — VO ligne
- [app/src/Domain/EuropeanCountries.php](app/src/Domain/EuropeanCountries.php) — pays/devises/TVA
- [app/src/Domain/TariffTemplateCatalog.php](app/src/Domain/TariffTemplateCatalog.php) — templates builtin
- [app/src/Domain/TariffLineCatalog.php](app/src/Domain/TariffLineCatalog.php) — + water + kindFor
- [app/src/Repository/TariffTemplateRepository.php](app/src/Repository/TariffTemplateRepository.php)
- [app/src/Repository/TariffRepository.php](app/src/Repository/TariffRepository.php) — vat_rate, méta lignes
- [app/src/Repository/Contract/TariffRepositoryInterface.php](app/src/Repository/Contract/TariffRepositoryInterface.php)
- [app/src/Domain/TariffGrid.php](app/src/Domain/TariffGrid.php) — TariffLine[], vatRate
- [app/src/Service/TariffCalculatorService.php](app/src/Service/TariffCalculatorService.php) — moteur générique
- [app/src/Service/CostCalculationService.php](app/src/Service/CostCalculationService.php) — eau + calcul
- [app/public/tariffs.php](app/public/tariffs.php) + [app/templates/tariffs.php](app/templates/tariffs.php)
- [app/public/assets/js/tariffs.js](app/public/assets/js/tariffs.js) + [app/public/assets/css/tariffs.css](app/public/assets/css/tariffs.css)
- [app/src/Http/Controller/TariffController.php](app/src/Http/Controller/TariffController.php) — API water
- [app/public/assets/js/dashboard.js](app/public/assets/js/dashboard.js) — rendu générique + eau
- `app/translations/{fr,en,nl,de}.php` — clés i18n

## Étapes
- [ ] 1. Migration BDD + baseline
- [ ] 2. Domain (ComponentKind, TariffLine, EuropeanCountries, TariffLineCatalog, TariffTemplateCatalog)
- [ ] 3. Repository + TariffGrid + TariffTemplateRepository + interface
- [ ] 4. Moteur de calcul générique + eau + CostCalculationService (iso-calcul vérifié)
- [ ] 5. Page tarifs (contrôleur, template, JS, CSS, i18n)
- [ ] 6. API TariffController (water, BC, vat_rate)
- [ ] 7. Dashboard (rendu générique + coût eau)

## Vérification
`php -l` + PHPStan (niveau 6 réel) ; migration sur base de dev + contrôle backfill ;
iso-calcul avant/après sur grille belge ; E2E : création grille eau, import template,
champ custom, sauver comme template, édition grille pré-migration, tarif dynamique.
