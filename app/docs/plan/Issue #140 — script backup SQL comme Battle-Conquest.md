# Issue #140 — script backup SQL comme Battle-Conquest

## Contexte
Aucun mécanisme de sauvegarde de la base n'existe dans Manage-energy-costs.
L'issue demande de s'inspirer du backup journalier de Battle-Conquest
(`scripts/backup_db.sh` : `mysqldump | gzip` + lock + rotation) pour produire
une sauvegarde SQL complète de la base.

Différence clé adaptée ici : Battle-Conquest lit les identifiants depuis un
`.env`, alors que ce projet les stocke dans un tableau PHP
[app/config/config.php](../../config/config.php) sous la clé `database`. Le
script extrait donc les identifiants via `php -r` (quoting sûr via
`escapeshellarg`).

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/140

## Fichiers impactés
- [app/scripts/backup_db.sh](../../scripts/backup_db.sh) — nouveau script bash de
  backup (exécutable). Aligné sur le bash existant `deploy_unraid.sh`.
- [.gitignore](../../../.gitignore) — ignore `/backups/` et `/var/`.
- [README.md](../../../README.md) — entrée crontab + note dans « Cron jobs ».

## Étapes
- [x] Créer la branche `feat/140-backup-sql` depuis `origin/main`.
- [x] Écrire `app/scripts/backup_db.sh` :
  - `set -euo pipefail`, `PROJECT_DIR` = racine dépôt.
  - Identifiants lus depuis `config.php` via `php -r` + `escapeshellarg`.
  - Lock `var/backup-db.lock` + `trap cleanup EXIT`.
  - Mot de passe passé via fichier `[client]` temporaire (`chmod 600`,
    `--defaults-extra-file`) → non exposé dans `ps aux`.
  - `mysqldump --single-transaction --routines --triggers --events --quick`
    `| gzip` → `backups/<db>_<ts>.sql.gz`.
  - Rotation `find … -mtime +30 -delete`.
- [x] `.gitignore` : `/backups/`, `/var/`.
- [x] README : entrée crontab (03:00) + note client `mysqldump` requis.
- [ ] Commit `feat(#140)` + PR `Closes #140`.

## Vérification
- `bash -n app/scripts/backup_db.sh` (syntaxe) — OK.
- Exécution manuelle : `bash app/scripts/backup_db.sh` → une archive
  `backups/<db>_<ts>.sql.gz` non vide, `gunzip -t` valide.
- Mot de passe absent de `ps aux` pendant le dump.
- 2ᵉ exécution : lock et rotation opérationnels.
- `git status` : `backups/` et `var/` ignorés.
