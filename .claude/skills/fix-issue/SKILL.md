---
name: fix-issue
description: Workflow complet pour fix d'une issue GitHub Manage-energy-costs — lecture de l'issue, plan dans app/docs/plan/, implémentation (PHP vanilla, autoloader App\ → app/src/), commit, puis création de la PR associée (Closes #X). À utiliser quand l'utilisateur demande "fix issue #X" ou "résous l'issue #X".
---

# Fix Issue Workflow

Invoqué avec un numéro d'issue (ex: `/fix-issue 21`).

## Contexte projet (à garder en tête)

Projet **PHP « vanilla »** : pas de Composer, pas de framework, pas de tests.
- Autoloader maison ([app/bootstrap.php](app/bootstrap.php)) : namespace `App\` → `app/src/`. Le namespace d'une classe **doit** refléter son chemin sous `app/src/`.
- Couches : front controller ([app/public/*.php](app/public/)) → Service ([app/src/Service](app/src/Service)) → Repository ([app/src/Repository](app/src/Repository)) → Infrastructure (PDO via [app/src/Infrastructure/Database.php](app/src/Infrastructure/Database.php), HTTP via `HttpClient`). Les objets-valeur vivent dans [app/src/Domain](app/src/Domain) ; le contrôle d'accès dans [app/src/Security/WebAccessGuard.php](app/src/Security/WebAccessGuard.php).
- Tout fichier PHP commence par `declare(strict_types=1);`.
- Vérification : `php -l` + **PHPStan niveau 5** (CI [.github/workflows/ci.yml](.github/workflows/ci.yml), config [phpstan.dist.neon](phpstan.dist.neon) + baseline). Le code **nouveau** est analysé au niveau 5 — ne pas l'ajouter à la baseline.

## Phase 1 — Lecture
1. `gh issue view <num>` — titre, description, labels.
2. S'assigner l'issue (signale la prise en charge) : `gh api repos/{owner}/{repo}/issues/<num>/assignees -X POST -f "assignees[]=$(gh api user --jq .login)"`
   - On passe par l'API REST (et non `gh issue edit --add-assignee`) : la requête GraphQL de `gh ... edit` inclut `projectCards` (Projects classic, en dépréciation), ce qui fait échouer la mutation en silence (warning + exit 0 sans effet). REST contourne ce bug.
3. Si l'issue référence des fichiers ou des PR, les lire.
4. Identifier la branche cible : créer `fix/<num>-<slug>` (bugfix) ou `feat/<num>-<slug>` (feature) depuis `main`, sinon utiliser la branche en cours.

## Phase 2 — Plan
Créer ou mettre à jour `app/docs/plan/Issue #<num> — <titre>.md` :

    # Issue #<num> — <titre>

    ## Contexte
    [Pourquoi, problème observé, lien GH]

    ## Fichiers impactés
    - [path/file.php:LL](path/file.php#LLL) — rôle
    - ...

    ## Étapes
    - [ ] Étape 1
    - [ ] Étape 2

    ## Vérification
    [Comment tester end-to-end]

Référencer les fichiers avec `path:line` (convention projet).

## Phase 3 — Implémentation
- Respecter le sens des dépendances : front controller → Service → Repository → Infrastructure. Pas de logique métier dans `app/public/*.php` (uniquement câblage + rendu).
- `declare(strict_types=1);` + namespace `App\…` aligné sur le chemin du fichier (sinon l'autoloader ne le trouve pas).
- Réutiliser l'existant (ex. `TariffCalculatorService`, repositories) plutôt que dupliquer.
- Garder PHPStan niveau 5 vert : typer les nouveaux paramètres/retours, ne pas introduire de code mort.
- Mettre à jour le plan au fil de l'eau (cocher ✅).

## Phase 4 — Commit
Format aligné sur l'historique :
- `fix(#<num>): <description>` — bugfix
- `feat(#<num>): <description>` — feature
- `chore(#<num>): <description>` — cleanup

Plusieurs commits autorisés si phases distinctes. Ne committer **que** les fichiers du projet (pas les dotfiles non suivis à la racine ni `.claude/`).

## Phase 5 — Créer la PR
À la fin du process, pousser la branche et ouvrir la **PR associée à l'issue** :
1. `git push -u origin <branche>`
2. `gh pr create --base main --head <branche> --title "<type>(#<num>): <description>" --body "..."`
   - Le corps **doit** contenir `Closes #<num>` (ferme l'issue au merge), un résumé des changements et le résultat des vérifs : `php -l` sur les fichiers modifiés (+ rappel que la CI relance lint multi-versions + PHPStan).
3. Afficher l'URL de la PR + `gh pr checks <pr>` pour le statut CI.

Si la branche est empilée sur une autre branche non mergée, baser la PR dessus (ou prévenir l'utilisateur).

**Ne pas** merger automatiquement.

## Rappels conventions (cf. ~/.claude/CLAUDE.md)
- Les commandes sont automatiquement préfixées `rtk` via le hook (transparent).
- Réponses en français.
- Co-trailer de commit déjà géré par la config (`attribution` + `Co-Authored-By`).
