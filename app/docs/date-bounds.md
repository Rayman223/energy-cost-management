# Convention de bornes de dates (Issue #1)

> **Règle du projet — s'applique à toute date de fin, sans exception.**

## La règle

Une date de fin désigne un **instant à minuit**, et elle est donc **exclue** de la
période qu'elle borne. Tout intervalle s'écrit `[début, fin[`.

```
Juin 2026  =  du 01/06/2026 au 01/07/2026
              ^^^^^^^^^^^^     ^^^^^^^^^^
              inclus           exclu (minuit, le jour n'a pas commencé)
```

- Une borne de fin `NULL` signifie **ouverte** : la période court indéfiniment.
- Une plage **vide** (`fin <= début`) n'a pas de sens et est refusée à la saisie.
- La règle vaut pour les trois surfaces à la fois : la **base de données**,
  l'**API JSON** et l'**écran**. Une même date signifie la même chose partout, et
  aucune conversion n'a lieu entre les couches.

## Pourquoi « exclue » plutôt que « incluse »

1. **Une date *est* un instant.** `2026-07-01` désigne le 1er juillet à 00:00 —
   le jour pas encore commencé. Lire cette même valeur comme « le 1er juillet tout
   entier » demande d'ajouter mentalement 24 heures invisibles.
2. **C'est la convention des factures de fournisseurs d'énergie**, dont
   l'application rapproche les montants. Un relevé d'index est pris à un instant,
   pas sur une journée.
3. **Les périodes successives se recollent.** La fin de l'une est le début de la
   suivante :

   ```
   Grille A : 01/01/2026 → 01/07/2026
   Grille B : 01/07/2026 → NULL
   ```

   Aucun « la veille du », aucun jour de bascule facturé deux fois ou oublié, et
   aucune arithmétique `±1 jour` disséminée dans le code pour rattraper une
   asymétrie entre deux conventions.
4. **C'est déjà la convention du calcul.** `CostCalculationService` et le bilan
   d'acomptes raisonnent en `[from, to[` ; c'était l'affichage qui divergeait.

## Ce que ça donne à l'écran

La date **stockée est la date affichée** : aucune conversion cosmétique. Ce sont
les libellés qui portent la convention — les champs de fin sont annotés « non
incluse » (`common.end_exclusive`), sur `/tariffs` comme sur `/advances`.

Afficher « 01/07/2026 (soit jusqu'au 30/06 inclus) » a été écarté volontairement :
cela remet les deux lectures côte à côte, ce que cette règle supprime.

## Surfaces concernées

| Donnée | Colonne | Sens |
|---|---|---|
| Validité d'une grille tarifaire | `tariff_grids.valid_to` | premier jour **non** couvert |
| Validité d'un barème d'acompte | `energy_advances.valid_to` | premier jour **non** couvert |
| Période du bilan d'acomptes | `user_profiles.advances_period_to` | premier jour **hors** bilan |
| Période du bilan (URL) | `/advances?from=…&to=…` | idem |

Les relevés d'index ne sont pas concernés : ce sont des **instants** (`DATETIME`),
pas des dates, et la comparaison y est déjà exacte.

## Historique et migration

Avant l'issue #1, `tariff_grids.valid_to` et `energy_advances.valid_to` étaient
**inclusives** tandis que la période du bilan d'acomptes était déjà exclusive — les
deux conventions cohabitaient sur `/advances`, à quelques centaines de pixels l'une
de l'autre. Une erreur d'un jour sur cette borne ajoutait ou retirait **un
prélèvement entier** au total payé.

La bascule est portée par
[`app/sql/migrations/2026-08-21_exclusive_end_dates.sql`](../sql/migrations/2026-08-21_exclusive_end_dates.sql),
qui décale les deux colonnes de `+1 jour`. Migration et code se compensent
exactement : **aucune valeur calculée ne change**. `advances_period_to` et les URL
`/advances?to=…` déjà en favori gardent leur sens, puisqu'ils étaient déjà exclusifs.

## Pour un nouvel écran

Toute nouvelle date de fin exposée à l'utilisateur suit cette règle — il n'y a plus
de choix à faire ni à documenter au cas par cas. En pratique :

- champ de saisie annoté `common.end_exclusive` ;
- validation refusant `fin <= début` ;
- en SQL, `valid_to > :from` et `valid_from < :to` (jamais `>=` / `<=`) ;
- en PHP, `$date < $end` (jamais `<=`), bornes normalisées par `setTime(0, 0, 0)`.
