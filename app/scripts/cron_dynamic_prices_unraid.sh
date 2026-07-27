#!/usr/bin/env bash
# ============================================================================
# cron_dynamic_prices_unraid.sh — Prix dynamiques ENTSO-E depuis Unraid
# ============================================================================
#
# Exécute app/scripts/cron_dynamic_prices.php DANS le container PHP (SWAG),
# horodate la sortie dans un log persistant et propage le code de sortie du
# script PHP (0 = OK/skip/warn, 1 = token manquant ou zone en erreur).
#
# Usage (plugin Unraid « User Scripts » ou SSH) :
#   ./cron_dynamic_prices_unraid.sh
#
# Plugin « User Scripts » : Settings → User Scripts → Add New Script. Y coller
# un wrapper qui délègue à CE fichier versionné (le plugin exécute sa propre
# copie, donc déléguer évite de recoller le script à chaque mise à jour) :
#
#   #!/bin/bash
#   exec bash /mnt/user/appdata/swag/www/energyv3/app/scripts/cron_dynamic_prices_unraid.sh
#
# Schedule « Custom » : 30 13,18 * * *  (passe principale après la publication
# day-ahead ~13h CET, puis rattrapage — l'upsert en base est idempotent).
# Attention : le cron Unraid tourne dans le fuseau du serveur, pas en UTC.
#
# Documentation complète : app/docs/entsoe-dynamic-prices.md
# ============================================================================

set -euo pipefail

# ── Configuration (surchargeable par l'environnement) ───────────────────────
APP_NAME="${APP_NAME:-energyv3}"
CONTAINER="${CONTAINER:-swag}"                                  # container PHP (SWAG)
CONTAINER_APP_DIR="${CONTAINER_APP_DIR:-/config/www/$APP_NAME}" # code vu du container
LOG_FILE="${LOG_FILE:-/mnt/user/appdata/swag/log/energy-dynamic.log}"
LOG_MAX_BYTES="${LOG_MAX_BYTES:-1048576}"                       # 1 Mo → troncature

# ── Journalisation : stdout (fenêtre User Scripts) + fichier ────────────────
# Un log inaccessible (disque plein, part non montée) ne doit pas empêcher la
# récupération des prix : on prévient et on continue en sortie console seule.
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
# Test par écriture réelle : `touch` réussirait sur un fichier en lecture seule
# appartenant à l'utilisateur, alors que `tee -a` échouerait ensuite.
if ! { : >> "$LOG_FILE"; } 2>/dev/null; then
    printf '[WARN] Log non inscriptible (%s) — sortie console uniquement.\n' "$LOG_FILE"
    LOG_FILE="/dev/null"
fi

# Rotation minimale : Unraid n'a pas de logrotate sur /mnt/user/appdata.
if [ -f "$LOG_FILE" ]; then
    size=$(wc -c < "$LOG_FILE")
    if [ "$size" -gt "$LOG_MAX_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
        : > "$LOG_FILE"
    fi
fi

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE"
}

log "── Démarrage cron_dynamic_prices (container=$CONTAINER, dir=$CONTAINER_APP_DIR)"

# ── Vérifications préalables ────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
    log "[ERROR] docker introuvable — ce script doit tourner sur l'hôte Unraid."
    exit 1
fi

running="$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || echo 'missing')"
if [ "$running" != "true" ]; then
    log "[ERROR] Container '$CONTAINER' indisponible (état: $running). Rien à faire."
    exit 1
fi

# ── Exécution du cron PHP dans le container ─────────────────────────────────
# On lit PIPESTATUS[0] et non $? : sous pipefail, $? vaudrait aussi 1 si c'est
# `tee` qui échoue, et l'échec serait imputé à tort au PHP.
# Deux pièges évités ici : pas de `|| true` sur le pipeline (PIPESTATUS
# refléterait alors le `true`), et copie du tableau en une seule instruction
# (la commande suivante l'écrase). D'où le `set +e` encadrant.
set +e
docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" \
    php app/scripts/cron_dynamic_prices.php 2>&1 | tee -a "$LOG_FILE"
pipe_status=("${PIPESTATUS[@]}")
set -e
php_status="${pipe_status[0]}"

if [ "${pipe_status[1]:-0}" -ne 0 ]; then
    log "[WARN] Écriture du log interrompue (tee code ${pipe_status[1]})."
fi

if [ "$php_status" -ne 0 ]; then
    log "[ERROR] cron_dynamic_prices.php a terminé avec le code $php_status."
    exit "$php_status"
fi

log "── Terminé avec succès."
