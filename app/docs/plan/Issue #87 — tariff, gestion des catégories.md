# Issue #87 — tariff : les templates ne gèrent pas les catégories

## Contexte

Les « catégories » d'une grille tarifaire (Énergie, Abonnements & forfaits, Taxes &
contributions, Injection) ne sont pas des données : elles sont dérivées automatiquement du
`component_kind` via [ComponentKind::group()](../../src/Domain/ComponentKind.php#L69). L'utilisateur
ne peut ni choisir la catégorie d'un champ, ni déplacer un champ ; tout champ custom retombe
dans « Taxes & contributions » (seul groupe muni du bouton « + Ajouter »). Ce regroupement
sert à l'éditeur de grille **et** au détail de coût du dashboard.

Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/87

Objectif : catégorie choisie par l'utilisateur, persistée, dans une liste prédéfinie étendue
(4 actuelles + `distribution`), via un `<select>` par champ, mémorisée sur grilles ET
templates, reflétée dans l'éditeur **et** le détail de coût.

## Fichiers impactés

- [app/src/Domain/TariffCategory.php](../../src/Domain/TariffCategory.php) — NOUVEAU : enum fermée.
- [app/src/Domain/TariffLine.php](../../src/Domain/TariffLine.php) — champ `category` + helper.
- [app/src/Domain/TariffGrid.php](../../src/Domain/TariffGrid.php) — `toCalculationTariff` porte la catégorie.
- [app/sql/migrations/2026-07-10_tariff_line_category.sql](../../sql/migrations/2026-07-10_tariff_line_category.sql) — NOUVEAU.
- [app/sql/schema.sql](../../sql/schema.sql) — colonne `category` (2 tables).
- [app/src/Repository/TariffRepository.php](../../src/Repository/TariffRepository.php) — persistance/hydratation.
- [app/src/Repository/TariffTemplateRepository.php](../../src/Repository/TariffTemplateRepository.php) — idem templates.
- [app/public/tariffs.php](../../public/tariffs.php) — parsing + options + ordre par catégorie.
- [app/templates/tariffs.php](../../templates/tariffs.php) — sélecteur par ligne, bloc par catégorie.
- [app/public/assets/js/tariffs.js](../../public/assets/js/tariffs.js) — ajout de champ par catégorie.
- [app/src/Service/TariffCalculatorService.php](../../src/Service/TariffCalculatorService.php) — `group` = catégorie.
- [app/public/assets/js/dashboard.js](../../public/assets/js/dashboard.js) — ordre/labels incl. distribution.
- [app/templates/dashboard.php](../../templates/dashboard.php) — labels de groupe.
- [app/translations/{fr,en,nl,de}.php](../../translations/fr.php) — `group_distribution`, `category`.
- [app/src/Http/Controller/TariffController.php](../../src/Http/Controller/TariffController.php) — `category => null`.
- [tests/Integration/TariffTemplateDbTest.php](../../../tests/Integration/TariffTemplateDbTest.php) — round-trip catégorie.

## Étapes

- [x] Étape 0 — plan + branche `feat/87-tariff-categories`.
- [ ] Étape 1 — Domaine.
- [ ] Étape 2 — DB + repositories.
- [ ] Étape 3 — Éditeur.
- [ ] Étape 4 — Détail de coût.
- [ ] Étape 5 — i18n + API JSON.
- [ ] Étape 6 — tests + vérifs.

## Vérification

- `php -l` + PHPStan niveau 6 (code neuf hors baseline).
- Migration appliquée sur base de test ; `schema.sql` sur base neuve.
- Éditeur : déplacer un champ vers « Distribution », ajouter un champ custom dans une
  catégorie, enregistrer, rouvrir → persistance OK ; via template → conservé.
- Coût : bloc « Distribution » au dashboard ; grilles legacy inchangées.
- `phpunit` vert.
