# États des pages web — référence de non-régression (Phase 0)

> **Référence figée — Phase 0 de la refonte initiale.**
> Inventaire des **états visibles actuels** des pages `app/public/*.php` (branche
> `main`), à comparer manuellement après le découplage front/back (Phases 1‑3)
> pour garantir l'absence de régression visuelle/fonctionnelle.

## `index.php` — Dashboard

Rendu **server-side** avec dégradation gracieuse (le bootstrap est encapsulé dans
un `try/catch` qui peuple `$dbError`). Les données « live » (puissance réseau,
solaire) et les coûts mensuels sont chargés ensuite en **JS** via `api.php`.

| État | Déclencheur | Rendu attendu |
|------|-------------|---------------|
| **Nominal** | BDD joignable, données présentes | Cartes consommation/production, deltas mensuels, estimation de coût, statut de synchro EnergyID (`d/m H:i`), historiques. |
| **Erreur BDD** | Exception au bootstrap → `$dbError` | La page se charge quand même ; un bandeau d'erreur affiche `$dbError` ; les blocs dépendant de la BDD montrent les placeholders. |
| **Données vides** | BDD OK mais relevés absents (`$deltas`, `$gasLatest`, `$waterLatest` nuls/false) | Helper `fmt()` affiche `—` (`<span class="nd">`) ; `fmtCost()` affiche `—` ; aucune valeur erronée. |
| **Estimation indisponible** | `$cost['available'] === false` | Le bloc coût affiche la `reason` au lieu d'un montant. |
| **Live KO** | `api.php?action=live` renvoie `*_error` | Les jauges temps réel signalent l'indisponibilité sans casser le reste de la page. |
| **Coûts injection** | Montant négatif | `fmtCost()` préfixe le signe `−` et formate la valeur absolue. |

## `tariffs.php` — Gestion des grilles tarifaires

Rendu **server-side** + formulaire **POST classique** (`action=save`,
*non* JSON — logique de sauvegarde distincte de `api.php?action=save_tariff`, à
réconcilier lors du découplage).

| État | Déclencheur | Rendu attendu |
|------|-------------|---------------|
| **Liste** | GET | Tableau des grilles électricité et gaz existantes. |
| **Création/édition** | Formulaire (clé `edit_id` pour l'édition) | Champs de lignes tarifaires selon `energy_type` (jeux de clés distincts élec/gaz). |
| **Succès** | POST `save` valide → `$success` | Message de confirmation. |
| **Erreur de validation** | `energy_type` hors énum, `name` vide, `valid_from` vide → `$error` | Message d'erreur, saisie conservée. |

## `login.php` — Authentification

| État | Déclencheur | Rendu attendu |
|------|-------------|---------------|
| **Désactivé** | `web_security.enabled` ou `basic_auth.enabled` à `false` | **Redirection 302** vers la racine de l'app (pas de page de login). |
| **Formulaire** | Sécurité + Basic Auth activés | Page de connexion bilingue (FR/EN, négociée via `?lang=` puis `Accept-Language`). |
| **Identifiants invalides** | Échec d'authentification | Message d'erreur localisé (`Identifiants invalides.` / `Invalid credentials.`). |

---

## Méthode de comparaison

Avant/après chaque phase de découplage, vérifier manuellement les états ci‑dessus
(au minimum : nominal, erreur BDD, données vides) sur les trois pages. Les
montants et libellés doivent rester identiques ; seules la structure des fichiers
et la provenance des données (server-side → `fetch` API) évoluent.
