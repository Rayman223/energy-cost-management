#!/usr/bin/env bash
# ============================================================================
# deploy_unraid.sh — Déploiement / mise à jour de l'application sur Unraid (SWAG)
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
#               le container MariaDB.
#   4. Migr.  — applique les migrations versionnées (app/sql/migrations/) via
#               app/scripts/migrate.php (idempotent, tracé dans schema_migrations).
#   5. Config — contrôle non-bloquant de la config OIDC (section 'oidc').
#
# Config OIDC (multi-utilisateurs) : renseigner la section 'oidc' de
# app/config/config.php (issuer, client_id, client_secret, redirect_uri,
# enabled=true). Ce fichier est hors dépôt (.gitignore) et conservé par le
# déploiement (clean sans -x). Ne jamais committer de secret : config.php reste
# la source de vérité unique, lue par ce script sans rien coder en dur.
#
# Dépôt privé : le script installe lui-même la deploy key SSH (Étape 0) avant tout
# git — il copie la clé depuis /boot en RAM avec chmod 600 (rootfs Unraid en RAM +
# /boot FAT32 ne conservant pas les permissions), puis exporte GIT_SSH_COMMAND.
# Emplacements surchargeables : SSH_KEY_SRC, SSH_KNOWN_HOSTS_SRC. Repasser REPO_URL
# en https://… désactive ce bloc (repo public).
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
# Nom du dossier de déploiement (hôte + container). Surchargable :
#   APP_NAME=energyv4 ./deploy_unraid.sh
APP_NAME="${APP_NAME:-energyv3}"

# Dépôt privé → accès SSH via deploy key. Repasser en https://… pour un repo public.
REPO_URL="${REPO_URL:-git@github.com:Rayman223/Manage-energy-costs.git}"
# Emplacements persistants de la deploy key sur /boot (survivent aux reboots).
SSH_KEY_SRC="${SSH_KEY_SRC:-/boot/config/ssh/github_deploy_ed25519}"
SSH_KNOWN_HOSTS_SRC="${SSH_KNOWN_HOSTS_SRC:-/boot/config/ssh/github_known_hosts}"
APP_DIR="${APP_DIR:-/mnt/user/appdata/swag/www/$APP_NAME}"    # code côté hôte Unraid
CONTAINER="${CONTAINER:-swag}"                                # container PHP (SWAG)
CONTAINER_APP_DIR="${CONTAINER_APP_DIR:-/config/www/$APP_NAME}" # même code, vu du container
DB_CONTAINER="${DB_CONTAINER:-mariadb}"                       # container MariaDB
TAG="${1:-}"                                                  # vide → dernier commit de main

# ── Helpers ─────────────────────────────────────────────────────────────────
log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*"
}

# ── Étape 0 : SSH (deploy key pour dépôt privé) ──────────────────────────────
# Autonome : installe la clé en RAM (rootfs Unraid en RAM + /boot FAT32 sans
# chmod). Ne dépend d'aucun autre User Script. Ignoré si REPO_URL est en HTTPS.
if [[ "$REPO_URL" == git@* || "$REPO_URL" == ssh://* ]]; then
    log "=== Étape 0 — Configuration SSH (deploy key) ==="
    [ -f "$SSH_KEY_SRC" ] || { log "ERREUR : clé SSH introuvable : $SSH_KEY_SRC"; exit 1; }

    SSH_RUN_DIR="$(mktemp -d)"
    trap 'rm -rf "$SSH_RUN_DIR"' EXIT
    install -m 600 "$SSH_KEY_SRC" "$SSH_RUN_DIR/id"        # chmod 600 direct

    KNOWN_HOSTS="$SSH_RUN_DIR/known_hosts"
    if [ -f "$SSH_KNOWN_HOSTS_SRC" ]; then
        install -m 644 "$SSH_KNOWN_HOSTS_SRC" "$KNOWN_HOSTS"
    else
        log "known_hosts absent → ssh-keyscan github.com (TOFU)"
        ssh-keyscan -t ed25519 github.com > "$KNOWN_HOSTS" 2>/dev/null
    fi

    export GIT_SSH_COMMAND="ssh -i $SSH_RUN_DIR/id -o IdentitiesOnly=yes \
-o UserKnownHostsFile=$KNOWN_HOSTS -o StrictHostKeyChecking=yes"
fi

# ── Étape 1 : Git ────────────────────────────────────────────────────────────
log "=== Étape 1/4 — Git (cible : ${TAG:-main}) ==="
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
log "=== Étape 2/4 — composer install (--no-dev) ==="
# Le code est cloné par l'hôte Unraid (UID nobody/users) mais composer tourne en
# root dans le container → git y voit une « dubious ownership ». On déclare le
# dossier comme sûr (idempotent : ajout seulement s'il n'est pas déjà présent).
docker exec "$CONTAINER" sh -c \
    "git config --global --get-all safe.directory | grep -qx '$CONTAINER_APP_DIR' \
     || git config --global --add safe.directory '$CONTAINER_APP_DIR'"

docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ── Étape 3 : Schéma DB ──────────────────────────────────────────────────────
log "=== Étape 3/4 — Application de schema.sql ==="
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

# ── Étape 4 : Migrations versionnées ─────────────────────────────────────────
log "=== Étape 4/4 — Migrations versionnées (migrate.php) ==="
docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" php app/scripts/migrate.php

# ── Étape 5 : Contrôle de configuration (OIDC) ───────────────────────────────
# Non-bloquant : signale si OIDC est activé sans issuer/client, ou désactivé
# (mode Basic Auth historique, mono-tenant). N'interrompt jamais le déploiement.
log "=== Étape 5/5 — Contrôle config OIDC ==="
docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" php -r '
    $c = require "app/config/config.php";
    $o = $c["oidc"] ?? [];
    if (!($o["enabled"] ?? false)) {
        fwrite(STDOUT, "OIDC désactivé — mode Basic Auth (mono-tenant).\n");
        exit(0);
    }
    $missing = array_values(array_filter(["issuer","client_id","client_secret","redirect_uri"], fn($k) => empty($o[$k])));
    if ($missing) {
        fwrite(STDOUT, "ATTENTION : OIDC activé mais champs manquants : " . implode(", ", $missing) . "\n");
    } else {
        fwrite(STDOUT, "OIDC activé et configuré (issuer=" . $o["issuer"] . ").\n");
    }
' || log "Contrôle config ignoré (config.php illisible ?)"

log "=== Déploiement OK (${TAG:-main}) ==="
