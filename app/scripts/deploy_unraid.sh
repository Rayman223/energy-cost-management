#!/usr/bin/env bash
# ============================================================================
# deploy_unraid.sh — Déploiement / mise à jour d'energyv2 sur Unraid (SWAG)
# ============================================================================
#
# Met à jour l'application dans le container SWAG (Nginx + PHP-FPM) à partir
# d'un TAG git (ou du dernier `main` si aucun tag n'est fourni), de façon
# idempotente et reproductible.
#
# Étapes :
#   1. Git    — fetch + reset --hard sur le tag/main demandé (clean SANS -x,
#               donc app/config/config.php et /vendor/ sont préservés).
#   2. Deps   — composer install --no-dev dans le container SWAG.
#   3. Schéma — applique app/sql/schema.sql (CREATE TABLE IF NOT EXISTS) sur
#               le container MariaDB. Les migrations ALTER (app/sql/migrations/)
#               restent MANUELLES — voir le README.
#
# Usage (SSH ou plugin Unraid « User Scripts ») :
#   ./deploy_unraid.sh              # déploie le dernier commit de main
#   ./deploy_unraid.sh beta-0.3     # déploie le tag git beta-0.3
#
# Plugin « User Scripts » : Settings → User Scripts → Add New Script, coller
# ce contenu (ou un wrapper qui l'appelle), schedule « At Startup of Array » ou
# « Custom » / manuel. Adapter les variables de configuration ci-dessous.
# ============================================================================

set -euo pipefail

# ── Configuration (à adapter à votre installation) ──────────────────────────
REPO_URL="https://github.com/Rayman223/Manage-energy-costs.git"
APP_DIR="/mnt/user/appdata/swag/www/energyv2"   # chemin du code côté hôte Unraid
CONTAINER="swag"                                 # container PHP (SWAG)
CONTAINER_APP_DIR="/config/www/energyv2"         # même code, vu depuis le container
DB_CONTAINER="mariadb"                            # container MariaDB
TAG="${1:-}"                                      # vide → dernier commit de main

# ── Helpers ─────────────────────────────────────────────────────────────────
log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*"
}

# ── Étape 1 : Git ────────────────────────────────────────────────────────────
log "=== Étape 1/3 — Git (cible : ${TAG:-main}) ==="
mkdir -p "$APP_DIR"
cd "$APP_DIR"

if [ ! -d .git ]; then
    log "Dépôt absent → git init + remote origin"
    git init
fi

# Remote origin idempotent (add si absent, sinon set-url pour rester à jour)
if git remote get-url origin >/dev/null 2>&1; then
    git remote set-url origin "$REPO_URL"
else
    git remote add origin "$REPO_URL"
fi

if [ -n "$TAG" ]; then
    log "Fetch du tag $TAG"
    git fetch --depth 1 origin tag "$TAG"
else
    log "Fetch de main"
    git fetch --depth 1 origin main
fi

log "Reset --hard sur FETCH_HEAD + checkout"
git reset --hard FETCH_HEAD
git checkout -f

# clean SANS -x : les fichiers ignorés (.gitignore → app/config/config.php,
# /vendor/) NE sont PAS supprimés. reset --hard ne touche pas non plus les
# fichiers non suivis : config.php est conservé.
log "Clean des fichiers non suivis (config.php et vendor/ préservés)"
git clean -fd

# ── Étape 2 : Dépendances ────────────────────────────────────────────────────
log "=== Étape 2/3 — composer install (--no-dev) ==="
# Seule dépendance runtime actuelle : php >=8.1. Étape conservée pour rester
# cohérent et préparer d'éventuelles dépendances futures.
docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ── Étape 3 : Schéma DB ──────────────────────────────────────────────────────
log "=== Étape 3/3 — Application de schema.sql ==="
# Credentials lus depuis config.php (source de vérité unique) via le PHP du
# container SWAG, pour ne rien coder en dur dans ce script.
mapfile -t DB_CFG < <(docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" php -r \
    '$d=(require "app/config/config.php")["database"]; echo $d["name"]."\n".$d["user"]."\n".$d["password"]."\n";')
DB_NAME="${DB_CFG[0]}"
DB_USER="${DB_CFG[1]}"
DB_PASS="${DB_CFG[2]}"

log "Schéma sur la base '$DB_NAME' (container $DB_CONTAINER)"
docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < "$APP_DIR/app/sql/schema.sql"

log "=== Déploiement OK (${TAG:-main}) ==="
