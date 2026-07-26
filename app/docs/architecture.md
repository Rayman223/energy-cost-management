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
public/index.php (front controller : URL propre → require routes/<page>.php)
   │
   ▼
routes/*.php (entrée mince : bootstrap → données → rendu/dispatch)
   ├─ Pages     → View (templates/) + échappement centralisé View::e()
   └─ routes/api.php → Http\Router → Http\Controller\* → Http\JsonResponse
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

- **Front controller & URLs propres** (#106). `public/index.php` est le point
  d'entrée unique : il traduit une URL sans extension (`/account`, `/tariffs`,
  `/api?action=…`) vers le script de page correspondant dans `app/routes/`
  (serveur en `try_files … /index.php`). Les liens sont générés par
  `App\Support\Url::to()` / `View::url()` (pendant de `Assets::url()`, gère les
  installs en sous-répertoire) ; les anciennes URLs `*.php` sont redirigées en 301.
- **Entrées minces.** `routes/*.php` ne contient que câblage + préparation de
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
  `MonthlyConsumptionInterpolator` (interpolation à minuit de la conso mensuelle,
  partagée gaz/eau/électricité), `ElectricityReadingMerger` (fusion des relevés),
  `TariffLineCatalog` (source unique des lignes tarifaires).
- **Pipeline d'assets** : `App\Support\Assets::url()` ajoute un cache-busting
  `?v=<mtime>` sans build tooling.
- **Qualité** : PHPStan **niveau 6**, suite PHPUnit, CI lint + PHPStan + tests sur
  PHP 8.4.

## Optimisation (Phase 5)

Voir [sql-audit.md](sql-audit.md) : mémoïsation requête-scopée, suppression d'index
redondants, et **réécriture des filtres non-sargables** (`YEAR()/MONTH()/DATE()` →
prédicats de plage) pour rendre l'index `timestamp` utilisable — validé sur une
base MariaDB. Côté front, scripts en `defer` (chargement non-bloquant).

## Plateforme communautaire européenne (épopée #47)

Transformation du site mono-utilisateur belge en plateforme **multi-tenant**,
**publique** et **européenne**, livrée en phases non-cassantes P0–P7.

- **Authentification OIDC** (`App\Security\*`, `oidc` dans la config) : client
  OpenID Connect générique (Authorization Code + PKCE/state/nonce). **Aucun
  mot de passe ni e-mail stocké** — identité = `issuer` + `subject` + nom
  d'affichage (`users`). Le mode Basic Auth historique reste actif tant qu'OIDC
  est désactivé (`enabled=false`), donc strictement rétrocompatible.
- **Multi-tenant** : `user_id` sur toutes les tables de données, `UNIQUE`
  composites, repositories scopés via `App\Security\UserContext`. Électricité en
  **modèle à registres** (`meters`/`meter_registers`/`meter_readings`) ; gaz/eau
  unifiés (`utility_readings`).
- **Catalogue tarifaire européen** : `tariff_grids.user_id` NULL = grille
  **partagée** (communauté), renseigné = surcharge perso ; multi-devises (ISO
  4217) ; zones de marché **ENTSO-E**.
- **Tarif dynamique = index + formule** (#228) : ENTSO-E ne fournit que l'index
  day-ahead brut (`dynamic_prices.price_eur_kwh`, HTVA), qu'aucun fournisseur ne
  facture tel quel. La formule du contrat est portée par la grille sous forme de
  lignes typées — `spot_coefficient` (multiplicateur) et `spot_offset` (€/kWh TTC)
  — résolues par `App\Service\SpotFormulaResolver` en `App\Domain\SpotFormula`, qui
  applique `spot × coef × (1+TVA) + offset` heure par heure. Ces kinds ne sont
  jamais facturés comme postes (`ComponentKind::isSpotFormula()`) ; les héberger
  dans la grille leur fait suivre `valid_from`/`valid_to`, donc un changement de
  contrat ne réécrit pas les mois passés. Repli **par composante** : sans ligne
  `spot_offset`, `user_profiles.supplier_markup_per_kwh` s'applique toujours (même
  si la grille porte un coefficient) ; sans `spot_coefficient`, le coefficient vaut
  simplement 1,0. Un coefficient hors bornes est neutralisé **et signalé**, la
  formule appliquée ne correspondant alors à rien de saisi.
- **TVA : source unique** (#232) — `tariff_grids.vat_rate`, pour la décomposition
  HTVA des montants TTC **comme** pour la TVA du prix spot. Deux colonnes portaient
  auparavant le même taux (profil + grille), ce qui produisait un calcul mixte
  silencieux quand elles divergeaient. Vivant dans la grille, le taux est
  versionné : un passage de 21 % à 6 % s'applique à partir de sa date.
- **API d'ingestion** (`api.php`) : jetons Bearer par utilisateur (`api_tokens`,
  hachés SHA-256, scope `ingest`, rate-limit à fenêtre fixe, révocables). Un
  jeton Bearer est restreint aux routes d'ingestion ; le reste exige une session.
- **i18n complète** (`App\I18n\*`, `App\View\ViewFactory`) : résolution de locale
  (`?lang` > profil > cookie > Accept-Language > défaut), catalogues
  `fr/en/nl/de` extensibles, formatage localisé (`Formatter`, ext-intl optionnel
  avec repli). Voir [plan/i18n.md](plan/i18n.md).
- **Self-service & RGPD** (`account.php`) : profil, jetons, EnergyID **opt-in**
  (BE/NL), export JSON et suppression de compte en cascade.
- **Administration** (`admin.php`, réservé aux `role=admin`) : gestion des
  membres (rôle `user`/`admin`, statut `active`/`blocked`). Un blocage prend
  effet **dès la requête suivante** (`AuthGuard` revérifie le statut, pas
  seulement à la connexion). Le catalogue partagé se gère depuis la page Tarifs.
- **Durcissement public** : CSRF sur les formulaires, en-têtes de sécurité
  (`App\Http\SecurityHeaders` — CSP en Report-Only, enforcement à venir), cookies
  de session durcis (`App\Security\Session`), secrets hors dépôt (config.php).
  Checklist : [security-review.md](security-review.md).

## Références

- [api-contract.md](api-contract.md) — contrat de l'API JSON.
- [security-review.md](security-review.md) — checklist de revue sécurité (P7).
- [sql-audit.md](sql-audit.md) — audit SQL & optimisation.
- [page-states.md](page-states.md) — états de référence des pages (anti-régression).
- [plan/](plan/) — plan détaillé de chaque incrément de l'épopée #25.
- [energyid-v2-model.md](energyid-v2-model.md) — protocole EnergyID V2.
