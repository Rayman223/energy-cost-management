---
name: fix-issue
description: Résoudre une issue GitHub de energy-cost-management, de son analyse à la PR associée. À utiliser lorsque l'utilisateur demande de corriger ou résoudre une issue identifiée par son numéro.
---

# Workflow de résolution d'issue

Utiliser ce workflow lorsqu'un numéro d'issue est fourni. Les consignes générales et conventions PHP sont dans `AGENTS.md` à la racine.

## 1. Comprendre l'issue

1. Lire l'issue avec `gh issue view <num>` : titre, description, labels et commentaires utiles.
2. Si l'accès GitHub est disponible, assigner l'issue au compte courant via l'API REST. Éviter `gh issue edit --add-assignee`, qui peut échouer silencieusement à cause de sa mutation GraphQL `projectCards`.
3. Lire les fichiers et PR cités par l'issue.
4. Créer depuis `main` une branche `fix/<num>-<slug>` pour un correctif ou `feat/<num>-<slug>` pour une fonctionnalité. Si le travail doit s'empiler sur une branche non fusionnée, baser la branche et la PR sur celle-ci et l'indiquer.

## 2. Planifier

Construire une courte liste de tâches dans le suivi de travail de la session, sans ajouter un fichier de plan au dépôt. Elle couvre le problème et son contexte, les fichiers concernés, les étapes, puis la vérification pertinente.

## 3. Implémenter

- Respecter le flux route → Service → Repository → Infrastructure. Garder les scripts `app/routes/*.php` au câblage et au rendu.
- Mettre `declare(strict_types=1);` dans les nouveaux fichiers PHP et faire correspondre namespace et chemin sous `app/src/`.
- Réutiliser les services et repositories existants.
- Ajouter une migration versionnée et idempotente pour tout changement de base de données, dans la même PR, et mettre à jour les autres définitions de schéma utilisées par le projet.
- Garder le code métier nouveau compatible avec PHPStan niveau 6 sans étendre la baseline. N'ajouter ou lancer des tests que si l'utilisateur le demande. Ne pas attendre ni surveiller les checks CI.
- Mettre à jour la liste de tâches au fil du travail.

## 4. Commit

Utiliser le format du dépôt : `fix(#<num>): <description>`, `feat(#<num>): <description>` ou `chore(#<num>): <description>`. N'inclure que les fichiers liés à la demande. Les nouveaux fichiers source et tests sont à ajouter au suivi Git. Ne pas committer les réglages locaux, les dotfiles locaux non suivis ou `.mcp.json`.

## 5. Créer la PR

1. Pousser la branche avec `git push -u origin <branche>`.
2. Ouvrir une PR vers sa branche cible avec `gh pr create`, inclure `Closes #<num>`, le résumé des changements et les vérifications locales réellement effectuées.
3. Donner à l'utilisateur le lien de la PR. Ne pas lancer `gh pr checks` et ne pas attendre ou surveiller la CI.

Ne pas fusionner automatiquement la PR.
