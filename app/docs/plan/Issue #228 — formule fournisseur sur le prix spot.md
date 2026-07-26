# Issue #228 — Formule fournisseur sur le prix spot (tarif dynamique)

## Contexte
Le tarif dynamique croisait la consommation horaire avec les prix ENTSO-E day-ahead bruts,
si bien que l'estimation ne correspondait pas à la facture. **La source de données n'était
pas en cause** : le day-ahead BE publié par ENTSO-E *est* l'index Belpex DAM que les
fournisseurs prennent pour référence. Ce qui manquait, c'est la **formule appliquée
par-dessus l'index**.

Ancienne formule (`CostCalculationService:690`) : `spot × (1+TVA) + markup`. Les offres
dynamiques réelles sont de la forme `spot × coefficient + marge`. Le coefficient
multiplicateur (typiquement 1,00–1,10 ; pertes réseau + coûts de profil/équilibrage)
n'existait nulle part — source d'écart dominante, de l'ordre de plusieurs ct€/kWh.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/228

Issues de suite ouvertes en même temps : #229 (écran de rapprochement facture) et #230
(calcul quart-horaire natif).

## Formule cible
```
rate TTC(h) = spot(h) × coefficient × (1 + TVA/100) + offset TTC
```
- `coefficient` : produit des lignes `spot_coefficient` du segment (1,0 si aucune)
- `offset_ttc` : somme des lignes `spot_offset` du segment (marge, équilibrage, profil…)
- `TVA` : `tariff_grids.vat_rate` du segment (source unique depuis #232, traitée
  juste après dans la même PR)

## Décisions
1. **Paramètres portés par la grille** (`tariff_grid_lines`), pas par `user_profiles` : un
   coefficient change à chaque renouvellement de contrat, et `valid_from`/`valid_to`
   conservent l'historique — les mois passés restent justes. Réutilise l'UI `/tariffs`.
2. **Offset conservé en TTC** (comportement d'avant préservé, cohérent avec la convention
   « montants de grille saisis TTC »). À coefficient 1,0 le calcul est **identique** à
   celui d'avant le lot.
3. **Précédence par composante** : seule une ligne `spot_offset` écarte
   `supplier_markup_per_kwh`, puisque eux seuls feraient double emploi. Un
   `spot_coefficient` saisi seul laisse la marge du profil intacte — l'écarter serait une
   perte silencieuse sur chaque heure, invisible dans `/account` qui affiche toujours le
   champ (corrigé en revue ; une première version basculait sur n'importe quelle ligne spot).
   Une ligne `spot_offset` à 0 reste un choix explicite et écarte bien le profil.
4. **Aucune migration SQL** : `component_kind` est un `VARCHAR(30)`, `amount_per_kwh` un
   `DECIMAL(12,7)` — les deux kinds et un coefficient `1.0800000` y entrent tels quels.

## Étapes
- [x] `ComponentKind` : cases `SpotCoefficient`/`SpotOffset`, prédicat `isSpotFormula()`
      (distinct de `isSupplierEnergy()`), `unit()` → `×`, `group()` → `energy`
- [x] `App\Domain\SpotFormula` : VO immuable (coefficient, offsetTtc, offsetSource,
      coefficientRejected) + `rateTtc()`
- [x] `App\Service\SpotFormulaResolver` : produit des coefficients, somme des offsets,
      repli profil **par composante**, neutralisation d'un coefficient hors `]0 ; 5]`
      **signalée** via `coefficientRejected` — sans quoi le dashboard annoncerait une
      formule ne correspondant ni à la grille, ni au repli
- [x] `TariffCalculatorService::computeLines()` : `continue` sur `isSpotFormula()` dans
      **tous** les modes + bras explicite dans `applyKind()` (match exhaustif)
- [x] `CostCalculationService` : formule résolue par segment, appliquée heure par heure,
      clé `formula` dans la réponse (segment dominant, via `dominantIndex()` extrait)
- [x] `TariffLineCatalog` : clés `spot_coefficient`/`spot_offset` + `kindFor()`, mappé sur
      **toutes** les énergies (sur une grille gaz, le repli `per_kwh` facturerait un
      coefficient 1,08 comme 1,08 €/kWh — corrigé en revue)
- [x] `app/routes/tariffs.php` : refus explicite d'un coefficient hors bornes
- [x] `app/templates/tariffs.php` : grisage symétrique des lignes spot en tarif fixe
- [x] `dashboard.js` + `dashboard.css` : note de formule sous la ligne énergie dynamique,
      en trois états — neutre, avertissement « marge reprise du profil », avertissement
      « coefficient hors bornes ignoré »
- [x] i18n fr/en/nl/de (`tariffs.kind.spot_*`, `tariffs.invalid_coefficient`,
      `tariffs.spot_ignored_fixed`, `dash.dynamic.formula*`, `tariffs.kind_legend` enrichie)
- [x] Tests : `SpotFormulaTest`, `SpotFormulaResolverTest`, + `CostCalculationServiceTest`
      (dont **non-régression** : grille sans ligne spot ⇒ montants inchangés),
      `TariffCalculatorServiceTest`, `ComponentKindTest`, `TariffLineCatalogTest`,
      `TariffControllerTest`
- [x] Doc : README, architecture.md, commentaire `schema.sql`
- [x] Vérifs : PHPUnit 539 tests (85 skips sans base), PHPStan 6 sans erreur

## Points d'attention
1. ~~**TVA du spot** : reste `user_profiles.vat_rate` alors que les paramètres vivent dans
   la grille.~~ **Traité par #232** (même PR) : la grille devient la source unique du taux
   et la colonne du profil est supprimée.
2. **`supplier_markup_per_kwh`** devient un mode dégradé, pas un champ déprécié : conservé
   dans `/account`, il reste la valeur par défaut sans ligne `spot_offset` — y compris
   quand la grille porte un coefficient.
3. **Économie solaire en dynamique** : `sumRatesForKinds()` utilise les lignes
   `energy_t1`/`energy_flat` statiques même en dynamique — écart préexistant, hors
   périmètre (un test garantit seulement que les lignes spot ne l'aggravent pas).
4. **Marge TTC** : le libellé du catalogue dit explicitement « TTC », la fiche tarifaire
   donnant souvent la valeur HTVA (6 % d'écart sur la marge seule).
5. `spot_coefficient` apparaît désormais dans `toTariffArray()` donc dans `tariff_rates`
   des réponses JSON : clé additionnelle, non cassante.
