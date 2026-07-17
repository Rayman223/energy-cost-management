#!/usr/bin/env bash
# =============================================================================
# backup_db.sh — Sauvegarde SQL complète de la base Manage-energy-costs
#
# Inspiré du backup journalier de Battle-Conquest, adapté à ce projet : les
# identifiants ne viennent pas d'un .env mais du tableau PHP
# app/config/config.php (clé 'database'), lu ici via `php -r`.
#
# Produit une archive horodatée et compressée dans backups/ :
#   backups/<base>_AAAA-MM-JJ_HHMMSS.sql.gz
# avec rotation automatique des sauvegardes de plus de RETENTION_DAYS jours.
#
# Usage manuel :
#   bash app/scripts/backup_db.sh
#
# Crontab (tous les jours à 3h ; nécessite le client mysqldump sur l'hôte) :
#   0 3 * * * /chemin/app/scripts/backup_db.sh >> /var/log/energy-backup.log 2>&1
# =============================================================================

set -euo pipefail

# Racine du projet (app/scripts/.. → app, ../.. → racine dépôt)
PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
CONFIG_FILE="$PROJECT_DIR/app/config/config.php"
LOCK_FILE="$PROJECT_DIR/var/backup-db.lock"
BACKUP_DIR="$PROJECT_DIR/backups"
RETENTION_DAYS=30

# --- Fonctions utilitaires ---------------------------------------------------

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

# Messages d'erreur sur stderr (visibles pour une supervision qui ne lit
# que le flux d'erreur, séparément de la sortie standard).
err() {
    log "$@" >&2
}

# Nettoyage : fichier de credentials temporaire + backup partiel éventuel.
# Le verrou (flock, fd 9) est libéré automatiquement à la fermeture du fd, y
# compris si le process est tué (SIGKILL) ou en cas de reboot : rien à purger.
DEFAULTS_FILE=""
PART_FILE=""
cleanup() {
    [[ -n "$DEFAULTS_FILE" ]] && rm -f "$DEFAULTS_FILE"
    [[ -n "$PART_FILE" ]] && rm -f "$PART_FILE"
    return 0
}

# --- Vérifications ------------------------------------------------------------

if [[ ! -f "$CONFIG_FILE" ]]; then
    err "ERREUR : config introuvable ($CONFIG_FILE)"
    exit 1
fi

trap cleanup EXIT

# Verrou d'exclusion via flock (atomique, et relâché automatiquement à la mort
# du process) : évite les exécutions parallèles sans laisser de verrou fantôme
# qui bloquerait tous les backups suivants après un crash/reboot.
mkdir -p "$(dirname "$LOCK_FILE")"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    err "ERREUR : backup déjà en cours (lock: $LOCK_FILE)"
    exit 1
fi

# --- Chargement des identifiants depuis config.php ----------------------------

# php -r émet des affectations shell sûres (escapeshellarg) ; eval les importe.
eval "$(php -r '
    $c = require $argv[1];
    $d = $c["database"] ?? [];
    foreach (["host", "port", "name", "user", "password"] as $k) {
        printf("DB_%s=%s\n", strtoupper($k), escapeshellarg((string) ($d[$k] ?? "")));
    }
' "$CONFIG_FILE")"

if [[ -z "${DB_NAME:-}" || -z "${DB_USER:-}" ]]; then
    err "ERREUR : identifiants DB manquants dans config.php (database.name / database.user)"
    exit 1
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

# --- Backup -------------------------------------------------------------------

mkdir -p "$BACKUP_DIR"

# Mot de passe passé via un fichier [client] temporaire (chmod 600) plutôt que
# sur la ligne de commande, pour ne pas l'exposer dans `ps aux`.
DEFAULTS_FILE="$(mktemp)"
chmod 600 "$DEFAULTS_FILE"
cat > "$DEFAULTS_FILE" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASSWORD
EOF

TIMESTAMP="$(date '+%Y-%m-%d_%H%M%S')"
FILENAME="${DB_NAME}_${TIMESTAMP}.sql.gz"
FILEPATH="$BACKUP_DIR/$FILENAME"

log "Début du backup de la base '$DB_NAME' ..."

# Dump vers un fichier .part : en cas d'échec (mysqldump interrompu, client
# absent, disque plein…), set -o pipefail arrête le script et cleanup() purge
# le .part. L'archive finale n'apparaît qu'une fois le dump réussi (mv atomique),
# donc jamais d'archive tronquée prise à tort pour un backup valide.
PART_FILE="$FILEPATH.part"

mysqldump \
    --defaults-extra-file="$DEFAULTS_FILE" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --quick \
    "$DB_NAME" | gzip > "$PART_FILE"

mv "$PART_FILE" "$FILEPATH"
PART_FILE=""  # promu : plus rien à purger

FILESIZE="$(du -h "$FILEPATH" | cut -f1)"
log "Backup terminé : $FILENAME ($FILESIZE)"

# --- Rotation (suppression des backups > RETENTION_DAYS jours) ----------------

DELETED=$(find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -type f -mtime +$RETENTION_DAYS -print -delete | wc -l)
if [[ "$DELETED" -gt 0 ]]; then
    log "Rotation : $DELETED ancien(s) backup(s) supprimé(s) (> ${RETENTION_DAYS} jours)"
fi

log "Backup OK"
