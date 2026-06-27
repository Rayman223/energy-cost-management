# Architecture & décisions

Vue d'ensemble de l'architecture en couches issue de l'épopée
[#25](https://github.com/Rayman223/Manage-energy-costs/issues/25)
(refactorisation, optimisation, découplage front/back). Reste un projet **PHP
vanilla**, sans framework, avec **Composer uniquement pour les outils de dev**.

## Couches

```
Requête HTTP
   │
   ▼
public/*.php (entrée mince : bootstrap → données → rendu/dispatch)
   ├─ Pages  → View (templates/) + échappement centralisé View::e()
   └─ api.php → Http\Router → Http\Controller\* → Http\JsonResponse
   │
   ▼
Service (logique métier : calcul tarifaire, interpolation, sync…)
   │
   ▼
Repository (accès données — type-hinté sur Repository\Contract\*)
   │
   ▼
Infrastructure (Database/PDO, HttpClient)
```

Le front (`public/assets/js/`) consomme l'API JSON via `fetch` pour le temps réel
et les sections transactionnelles (puissance live, navigation des coûts,
graphique, tables gaz/eau).

## Décisions clés

- **Entrées minces.** `public/*.php` ne contient que câblage + préparation de
  données ; aucun HTML ni logique métier. Le HTML vit dans `app/templates/`, le
  CSS/JS dans `app/public/assets/`.
- **Moteur de vues maison** (`App\View\View`) : templates PHP + **échappement
  centralisé** (`$this->e()`), pas de moteur tiers (cohérent vanilla).
- **Couche HTTP** (`App\Http`) : `Request` / `JsonResponse` / `Router` +
  **contrôleurs dédiés** ; `api.php` n'est plus qu'un fichier de câblage. Validation
  centralisée, codes HTTP normalisés. Voir [api-contract.md](api-contract.md).
- **Interfaces de repository** (`Repository\Contract\*`) comme *seams de test* :
  les services et contrôleurs dépendent d'abstractions, ce qui permet de les tester
  avec des fakes (`tests/Fake/`) sans base.
- **Logique pure extraite & testée** : `TariffCalculatorService`,
  `GasMonthInterpolator` (interpolation gaz), `ElectricityReadingMerger` (fusion
  des relevés), `TariffLineCatalog` (source unique des lignes tarifaires).
- **Pipeline d'assets** : `App\Support\Assets::url()` ajoute un cache-busting
  `?v=<mtime>` sans build tooling.
- **Qualité** : PHPStan **niveau 6**, suite PHPUnit, CI lint + PHPStan + tests sur
  PHP 8.1/8.2/8.3.

## Optimisation (Phase 5)

Voir [sql-audit.md](sql-audit.md) : mémoïsation requête-scopée, suppression d'index
redondants, et **réécriture des filtres non-sargables** (`YEAR()/MONTH()/DATE()` →
prédicats de plage) pour rendre l'index `timestamp` utilisable — validé sur une
base MariaDB. Côté front, scripts en `defer` (chargement non-bloquant).

## Références

- [api-contract.md](api-contract.md) — contrat de l'API JSON.
- [sql-audit.md](sql-audit.md) — audit SQL & optimisation.
- [page-states.md](page-states.md) — états de référence des pages (anti-régression).
- [plan/](plan/) — plan détaillé de chaque incrément de l'épopée #25.
- [energyid-v2-model.md](energyid-v2-model.md) — protocole EnergyID V2.
