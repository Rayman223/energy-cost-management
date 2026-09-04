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
| **Nominal** | BDD joignable, données présentes | Cartes consommation/production, deltas mensuels, estimation de coût, historiques. |
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

## `/stats` — Statistiques communautaires (#8)

Seule page du site rendue à un visiteur **anonyme** tout en partageant la coquille
applicative. Le rendu s'enrichit d'un bloc personnel quand une session existe,
sans jamais l'exiger. Les agrégats sont k-anonymisés en SQL (seuil de 5 foyers
contributeurs), et un membre peut se retirer depuis `/account`.

| État | Déclencheur | Rendu attendu |
|------|-------------|---------------|
| **Anonyme** | Aucune session, mode OIDC actif | Blocs publics seuls ; en-tête sans navigation privée ni bouton de déconnexion ; encart « Se connecter ». **Aucun cookie de session n'est posé** (la session n'est sondée que si un cookie existe déjà). |
| **Connecté, pays renseigné** | Session valide + `user_profiles.country` non nul | Bloc personnel EN PREMIER (tarif au kWh, consommation, percentile, coût réel), puis graphes 12 mois et par poste, puis les blocs publics. |
| **Connecté, sans pays** | Session valide, `country` nul | Pas de bloc personnel : invite à compléter le profil, avec lien vers `/account`. Les blocs publics restent affichés. |
| **Connecté, retiré des statistiques** | `stats_opt_out = 1` | Bloc personnel affiché normalement, précédé d'un avertissement : les chiffres restent visibles, mais ne comptent dans aucune moyenne. |
| **Pays sous le seuil** | Moins de 5 foyers contributeurs dans le pays | Le pays est fondu dans « Autres pays » (aucune valeur, seulement un nombre de foyers) ; percentile masqué ; comparaison de moyenne à `—`. |
| **Corpus insuffisant** | Aucun agrégat ne franchit le seuil | Message explicatif à la place des tableaux et graphes — jamais un tableau vide. |
| **Erreur BDD** | Exception au bootstrap ou au chargement → `$dbError` | La page se rend quand même : bandeau d'erreur, sections à `—`, aucune trace d'exception. |
| **Instance en Basic Auth** | Mode OIDC **désactivé** | La page N'EST PAS publique : `AuthGuard::protect()` s'applique comme sur toute autre page. Sans cela, `/stats` percerait l'allowlist IP d'une installation auto-hébergée. |
| **Compte bloqué** | Session vivante mais `users.status <> 'active'` | Rendu anonyme : `AuthGuard::protect()` n'ayant pas tourné, la route vérifie `isActive()` elle-même. |

---

## Méthode de comparaison

Avant/après chaque phase de découplage, vérifier manuellement les états ci‑dessus
(au minimum : nominal, erreur BDD, données vides) sur les trois pages. Les
montants et libellés doivent rester identiques ; seules la structure des fichiers
et la provenance des données (server-side → `fetch` API) évoluent.
