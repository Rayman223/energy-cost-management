---
name: fix-issue
description: Workflow complet pour fix d'une issue GitHub energy-cost-management — lecture de l'issue, plan de travail, implémentation (PHP vanilla, autoloader App\ → app/src/), commit, puis création de la PR associée (Closes #X). À utiliser quand l'utilisateur demande "fix issue #X" ou "résous l'issue #X".
---

# Fix Issue Workflow

Invoqué avec un numéro d'issue (ex: `/fix-issue 21`).

## Contexte projet (à garder en tête)

Projet **PHP « vanilla »** (pas de framework), avec Composer et une suite PHPUnit.
- Autoloader maison ([app/bootstrap.php](app/bootstrap.php)) : namespace `App\` → `app/src/`. Le namespace d'une classe **doit** refléter son chemin sous `app/src/`. Le bootstrap charge aussi `vendor/autoload.php` s'il est présent ([app/bootstrap.php:5-8](app/bootstrap.php#L5-L8)).
- Dépendances Composer ([composer.json](composer.json)) : `jumbojett/openid-connect-php` (runtime, OIDC) et `phpunit/phpunit` (dev).
- Routage : point d'entrée unique [app/public/index.php](app/public/index.php) → routeur [app/src/Http/Router.php](app/src/Http/Router.php) → scripts de route [app/routes/*.php](app/routes/) → Service ([app/src/Service](app/src/Service)) → Repository ([app/src/Repository](app/src/Repository)) → Infrastructure (PDO via [app/src/Infrastructure/Database.php](app/src/Infrastructure/Database.php), HTTP via `HttpClient`). Les objets-valeur vivent dans [app/src/Domain](app/src/Domain) ; le contrôle d'accès dans [app/src/Security/WebAccessGuard.php](app/src/Security/WebAccessGuard.php).
- Tests : [tests/Unit](tests/Unit) et [tests/Integration](tests/Integration) (config [phpunit.xml.dist](phpunit.xml.dist)). Lancer `vendor/bin/phpunit tests/Unit` en local ; l'intégration BDD nécessite MariaDB (sinon relayée à la CI).
- Tout fichier PHP commence par `declare(strict_types=1);`.
- Vérification : `php -l` + **PHPStan niveau 6** (CI [.github/workflows/ci.yml](.github/workflows/ci.yml), config [phpstan.dist.neon](phpstan.dist.neon) + baseline) + PHPUnit. Le code **nouveau** est analysé au niveau 6 — ne pas l'ajouter à la baseline.

## Phase 1 — Lecture
1. `gh issue view <num>` — titre, description, labels.
2. S'assigner l'issue (signale la prise en charge) : `gh api repos/{owner}/{repo}/issues/<num>/assignees -X POST -f "assignees[]=$(gh api user --jq .login)"`
   - On passe par l'API REST (et non `gh issue edit --add-assignee`) : la requête GraphQL de `gh ... edit` inclut `projectCards` (Projects classic, en dépréciation), ce qui fait échouer la mutation en silence (warning + exit 0 sans effet). REST contourne ce bug.
3. Si l'issue référence des fichiers ou des PR, les lire.
4. Identifier la branche cible : créer `fix/<num>-<slug>` (bugfix) ou `feat/<num>-<slug>` (feature) depuis `main`, sinon utiliser la branche en cours.

## Phase 2 — Plan
Établir un plan de travail (via `TodoWrite`, **sans** créer de fichier de plan versionné) couvrant :

- **Contexte** : pourquoi, problème observé, lien GH.
- **Fichiers impactés** : `path/file.php:LL` — rôle (convention projet `path:line`).
- **Étapes** : tâches ordonnées à cocher au fil de l'eau.
- **Vérification** : comment tester end-to-end.

## Phase 3 — Implémentation
- Respecter le sens des dépendances : route → Service → Repository → Infrastructure. Les scripts de route `app/routes/*.php` ne font que câblage + rendu ; la logique métier descend en Service/Repository.
- `declare(strict_types=1);` + namespace `App\…` aligné sur le chemin du fichier (sinon l'autoloader ne le trouve pas).
- Réutiliser l'existant (ex. `TariffCalculatorService`, repositories) plutôt que dupliquer.
- Garder PHPStan niveau 6 vert : typer les nouveaux paramètres/retours, ne pas introduire de code mort.
- Ajouter/compléter les tests PHPUnit quand le changement touche du code métier, et lancer `vendor/bin/phpunit tests/Unit` en local.
- Mettre à jour le plan de travail (`TodoWrite`) au fil de l'eau.

## Phase 4 — Commit
Format aligné sur l'historique :
- `fix(#<num>): <description>` — bugfix
- `feat(#<num>): <description>` — feature
- `chore(#<num>): <description>` — cleanup

Plusieurs commits autorisés si phases distinctes. Ne committer **que** les fichiers liés à la demande. Un **nouveau** fichier du projet (source, test) doit être ajouté (`git add`) et devient suivi — c'est attendu. Ne jamais committer ce qui n'a pas vocation à être versionné : configuration locale, dotfiles non suivis à la racine, `.mcp.json`.

> **Nuance `/.claude/`** : le dossier est listé dans `.gitignore`, mais certains fichiers y sont **déjà versionnés** (partagés avec l'équipe) et restent committables — la config partagée `.claude/settings.json` et **tout** le dossier des skills `.claude/skills/**`, y compris les **nouveaux** skills créés (qui doivent eux aussi être ajoutés à git). En revanche, ne pas committer la config locale du harness non suivie.

## Phase 5 — Créer la PR
À la fin du process, pousser la branche et ouvrir la **PR associée à l'issue** :
1. `git push -u origin <branche>`
2. `gh pr create --base main --head <branche> --title "<type>(#<num>): <description>" --body "..."`
   - Le corps **doit** contenir `Closes #<num>` (ferme l'issue au merge), un résumé des changements et le résultat des vérifs locales : `php -l` sur les fichiers modifiés **et** `vendor/bin/phpunit tests/Unit`. Rappeler que la CI relance ses 5 jobs : PHP Lint (matrice), JS Syntax (`node --check`), PHPStan (niveau 6), PHPUnit (matrice) et PHPUnit (intégration BDD).
3. Afficher l'URL de la PR + `gh pr checks <pr>` pour le statut CI.

Si la branche est empilée sur une autre branche non mergée, baser la PR dessus (ou prévenir l'utilisateur).

**Ne pas** merger automatiquement.

## Rappels conventions (cf. ~/.claude/CLAUDE.md)
- Les commandes sont automatiquement préfixées `rtk` via le hook (transparent).
- Réponses en français.
- Co-trailer de commit déjà géré par la config (`attribution` + `Co-Authored-By`).
