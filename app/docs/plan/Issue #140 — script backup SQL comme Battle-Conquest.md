# Issue #140 — script backup SQL comme Battle-Conquest

## Contexte
Aucun mécanisme de sauvegarde de la base n'existe dans Manage-energy-costs.
L'issue demande de s'inspirer du backup journalier de Battle-Conquest
(`mysqldump | gzip` + lock + rotation) pour produire une sauvegarde SQL complète.

**Pivot d'approche** : la 1ʳᵉ version (`backup_db.sh`) appelait `mysqldump`. Or le
déploiement cible tourne dans un container web **Alpine (swag)** qui n'embarque
pas le client MariaDB, et l'y installer via `custom-cont-init.d` n'est pas
pérenne (ré-exécuté à chaque redémarrage, dépendance à un dépôt apk). Le
container a en revanche PHP + PDO/MySQL (l'app s'en sert déjà). La sauvegarde est
donc réimplémentée **100 % PHP via PDO**, sans binaire externe : elle tourne
telle quelle dans swag.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/140

## Fichiers impactés
- [app/src/Service/DatabaseBackupService.php](../../src/Service/DatabaseBackupService.php)
  — génère le dump SQL logique par fragments (générateur) : tables (structure +
  données en lecture non bufferisée), vues, triggers, routines, events. Exclut
  les colonnes générées des INSERT ; DELIMITER pour les objets à corps multi-instructions.
- [app/scripts/backup_db.php](../../scripts/backup_db.php) — CLI : verrou `flock`,
  snapshot cohérent (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), écriture
  gzip atomique `.part` → `rename`, rotation 30 j, logs (erreurs sur stderr).
- [.gitignore](../../../.gitignore) — ignore `/backups/` et `/var/`.
- [README.md](../../../README.md) — entrée crontab + note « dump PHP, sans mysqldump ».
- (supprimé) `app/scripts/backup_db.sh` — remplacé par la version PHP.

## Étapes
- [x] Service `DatabaseBackupService` (dump PDO, streaming, objets non-table).
- [x] Script `backup_db.php` (flock, snapshot, `.part`→rename, rotation, stderr).
- [x] Supprimer `backup_db.sh`, mettre à jour README + `.gitignore`.
- [x] PHPStan niveau 6 vert sur les deux fichiers (pas d'ajout en baseline).

## Vérification
- `php -l` + **PHPStan niveau 6** (PHAR 2.2.2) : 0 erreur sur les fichiers neufs.
- Exécution réelle : archive `backups/<db>_<ts>.sql.gz` valide (`gunzip -t`),
  16 `CREATE TABLE`, aucun `.part` résiduel.
- **Round-trip restore** : réimport du dump → import `rc=0`, 16 tables, row-count
  identique avant/après.
- **Fidélité des données** : `CHECKSUM TABLE` identique avant/après sur une table
  piégée (NULL, quote, backslash, newline, unicode) ; colonne **générée exclue**
  de l'INSERT et recalculée correctement au restore.
- Verrou : 2ᵉ exécution concurrente rejetée (« backup déjà en cours »).
- Cron container : `docker exec swag php /config/www/energyv3/app/scripts/backup_db.php`.
