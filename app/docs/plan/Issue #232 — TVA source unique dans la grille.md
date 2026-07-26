# Issue #232 — TVA : source unique dans `tariff_grids`

## Contexte
Suite immédiate de #228 (même PR). Deux colonnes `vat_rate` portaient la **même**
sémantique — le taux de TVA de l'électricité — avec des portées différentes :

- `tariff_grids.vat_rate` → décomposition HTVA/TVA des montants TTC de la grille
  (`TariffCalculatorService::computeLines()`) ;
- `user_profiles.vat_rate` → TVA appliquée au prix spot HTVA (`buildDynamicResponse()`).

Un utilisateur avec `grid = 21` et `profile = 6` obtenait un calcul **mixte incohérent** :
part énergie TVAée à 6 %, décomposition du total à 21 %, sans aucun signal.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/232

## Décision
La grille devient la **source unique**. Cohérent avec #228 (les paramètres contractuels y
vivent déjà) et surtout **versionné** par `valid_from`/`valid_to` : un taux de TVA change
dans le temps (21 % → 6 % sur l'électricité résidentielle belge) et les mois passés doivent
rester calculés au taux de l'époque, ce que le profil ne permettait pas.

`user_profiles.vat_rate` est **supprimée** plutôt que laissée inutilisée : un champ modifiable
dans `/account` sans effet sur aucun calcul est précisément le piège que cette bascule vise à
faire disparaître. Pas de production à ce stade → une migration de report suffit.

`supplier_markup_per_kwh` **reste** au profil : c'est un repli fonctionnel documenté (#228),
pas une duplication de sémantique.

## Étapes
- [x] Migration `2026-07-26_vat_rate_single_source.sql` : `DROP COLUMN IF EXISTS` **sans
      backfill** (cf. « Pourquoi aucun report » ci-dessous). Hors seed de baseline (idempotente)
- [x] `schema.sql` : colonne retirée de `user_profiles`
- [x] `CostCalculationService` : `$segments[$i]->grid->vatRate` dans la formule spot ; le
      paramètre `vatRatePercent` du constructeur disparaît ; `formulaMeta()` expose le taux
      du segment dominant ; `dominantIndex()` calculé une fois pour `tariff_rates` et `formula`
- [x] `UserProfile` (champ + `defaults()`), `UserRepository` (SELECT / INSERT / bornage)
- [x] `/account` : champ, validation, reconduction ; `data-vat` du sélecteur pays et code
      mort correspondant dans `account.js`
- [x] `AccountDataExporter` (export RGPD), `routes/dashboard.php`, `routes/api.php`
- [x] `ConfigSchema` : le message `moved` de `dynamic_prices.vat_rate` pointe désormais
      `/tariffs` (la marge continue de pointer `/account`)
- [x] i18n fr/en/nl/de : `account.vat_rate`, `account.vat_rate_hint`,
      `account.invalid_vat_rate` retirées
- [x] Tests : `testDynamicVatComesFromTheGrid`, `testDynamicVatIsResolvedPerTariffSegment`,
      verrou anti-facteur-100 réécrit sur la grille, `ConfigValidatorTest` vérifie les deux
      destinations, `AccountRgpdDbTest` vérifie l'**absence** de la clé, `UserProfileTest` et
      `UserRepositoryDbTest` alignés
- [x] Doc : README (extrait de config désynchronisé corrigé au passage), architecture.md
- [x] Vérifs : PHPUnit 533 tests, PHPStan 6 sans erreur

## Pourquoi aucun report (revue de code)
Une première version reportait `user_profiles.vat_rate` dans les grilles électricité avant
de supprimer la colonne, au nom du « rien ne change ». **C'était destructeur** :
`tariff_grids.vat_rate` fait déjà autorité (saisie explicite dans `/tariffs`, pré-remplie par
pays, et déjà utilisée pour la décomposition HTVA), tandis que la colonne du profil vaut le
plus souvent son défaut `21.00` jamais touché — son champ n'était même rendu que si les prix
dynamiques étaient activés. Le report aurait donc :

- écrasé une grille allemande à 19 % avec un 21.00 par défaut et jamais choisi ;
- aplati sur une seule valeur les grilles d'un utilisateur ayant correctement versionné
  21 % → 6 %, c'est-à-dire détruit le versionnage que cette issue introduit.

La migration se réduit donc à `ALTER TABLE user_profiles DROP COLUMN IF EXISTS vat_rate` —
idempotente sans SQL dynamique, le `DROP … IF EXISTS` suffisant.

**Conséquence assumée** : pour un utilisateur dont `profile.vat_rate` différait de
`grid.vat_rate`, la TVA du prix spot change — elle s'aligne sur le taux de la grille, déjà
appliqué à tout le reste de sa facture. C'est l'incohérence corrigée, pas un effet de bord.
Un utilisateur ayant délibérément saisi un taux spot distinct doit le reporter sur sa grille.

Sur base fraîche, `2026-07-17_user_dynamic_pricing.sql` (hors seed, idempotente) recrée la
colonne juste avant que celle-ci ne la resupprime : ordre lexical garanti et `user_profiles`
vide à ce stade. Laissé tel quel plutôt que de baseliner 2026-07-17, dont l'autre colonne
(`supplier_markup_per_kwh`) reste nécessaire.

## Points d'attention
1. **Migration non vérifiable en local** (aucune base accessible) : la garde CI C1 et la
   suite d'intégration en sont les seuls juges.
2. Le taux de TVA n'est plus modifiable depuis `/account` : c'est volontaire, il se saisit sur
   la grille — l'y dupliquer recréerait exactement le problème corrigé ici.
3. Un utilisateur avec plusieurs grilles électricité successives doit renseigner le taux sur
   chacune. Le pré-remplissage par pays existe déjà dans `/tariffs` (`data-vat` +
   `EuropeanCountries::vatRate`), ce qui limite la friction.
