#!/usr/bin/env bash
# ============================================================================
# cron_load_profile_unraid.sh — Rappel d'import des profils RLP depuis Unraid
# ============================================================================
#
# Exécute app/scripts/cron_load_profile_check.php DANS le container PHP (SWAG) et,
# quand un mois de profil manque, envoie une NOTIFICATION Unraid qui pointe sur la
# page d'import /admin/load-profiles (#101).
#
# Pourquoi : le RLP est un profil mesuré, publié par Synergrid après la clôture de
# chaque mois et sans API. Un mois oublié ne se voit pas — le calcul des tarifs
# indexés retombe sans bruit sur la moyenne simple des cotations.
#
# Une seule notification par mois manquant : les mois déjà signalés sont retenus
# dans STATE_FILE. Le log, lui, répète l'avertissement à chaque passage.
#
# Usage (plugin Unraid « User Scripts » ou SSH) :
#   ./cron_load_profile_unraid.sh
#
# Plugin « User Scripts » : Settings → User Scripts → Add New Script, puis coller
# un wrapper qui délègue à CE fichier versionné :
#
#   #!/bin/bash
#   APP_URL="https://energie.example.tld" \
#     exec bash /mnt/user/appdata/swag/www/energyv3/app/scripts/cron_load_profile_unraid.sh
#
# Schedule « Custom » : 0 9 * * *  (une fois par jour ; sans mois manquant, rien
# n'est notifié).
#
# Documentation complète : app/docs/entsoe-dynamic-prices.md (§ 6 ter)
# ============================================================================

set -euo pipefail

# ── Configuration (surchargeable par l'environnement) ───────────────────────
APP_NAME="${APP_NAME:-energyv3}"
CONTAINER="${CONTAINER:-swag}"                                  # container PHP (SWAG)
CONTAINER_APP_DIR="${CONTAINER_APP_DIR:-/config/www/$APP_NAME}" # code vu du container
LOG_FILE="${LOG_FILE:-/mnt/user/appdata/swag/log/energy-load-profile.log}"
LOG_MAX_BYTES="${LOG_MAX_BYTES:-1048576}"                       # 1 Mo → troncature
STATE_FILE="${STATE_FILE:-/mnt/user/appdata/swag/log/energy-load-profile.notified}"
GRACE_DAYS="${GRACE_DAYS:-5}"                                   # attente après clôture du mois
LOOKBACK="${LOOKBACK:-12}"                                      # mois examinés
# URL publique de l'application (ex. https://energie.example.tld). Prioritaire sur
# seo.base_url de config.php ; sans l'une ni l'autre, la notification n'a pas de
# lien cliquable mais cite toujours la page à ouvrir.
APP_URL="${APP_URL:-}"
NOTIFY_BIN="${NOTIFY_BIN:-/usr/local/emhttp/webGui/scripts/notify}"

# ── Journalisation : stdout (fenêtre User Scripts) + fichier ────────────────
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
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

log "── Démarrage cron_load_profile (container=$CONTAINER, dir=$CONTAINER_APP_DIR)"

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

# ── Vérification dans le container ──────────────────────────────────────────
# Sortie capturée (et non pipée dans tee) : il faut la relire pour en extraire les
# mois manquants. Codes : 0 = à jour, 2 = mois manquant(s), autre = erreur.
set +e
output="$(docker exec -w "$CONTAINER_APP_DIR" "$CONTAINER" \
    php app/scripts/cron_load_profile_check.php \
    "--grace-days=$GRACE_DAYS" "--lookback=$LOOKBACK" 2>&1)"
status=$?
set -e

if [ -n "$output" ]; then
    printf '%s\n' "$output" | tee -a "$LOG_FILE"
fi

if [ "$status" -eq 0 ]; then
    # Plus rien ne manque : on oublie les mois signalés, pour qu'un trou futur
    # (profil supprimé, nouveau contrat) soit de nouveau notifié.
    rm -f "$STATE_FILE" 2>/dev/null || true
    log "── Profils à jour."
    exit 0
fi

if [ "$status" -ne 2 ]; then
    log "[ERROR] cron_load_profile_check.php a terminé avec le code $status."
    exit "$status"
fi

# ── Mois manquants ──────────────────────────────────────────────────────────
# Lignes « [MISSING] CODE PAYS AAAA-MM COUVERTURE » → clés « CODE PAYS AAAA-MM ».
missing="$(printf '%s\n' "$output" | awk '$1 == "[MISSING]" { print $2, $3, $4 }')"
upload_url="$(printf '%s\n' "$output" | awk '$1 == "[UPLOAD_URL]" { print $2; exit }')"
download_url="$(printf '%s\n' "$output" | awk '$1 == "[DOWNLOAD_URL]" { print $2; exit }')"
if [ -n "$APP_URL" ]; then
    upload_url="${APP_URL%/}/admin/load-profiles"
fi

# L'état = les mois manquants DÉJÀ notifiés. Il est réécrit avec la liste courante :
# un mois importé depuis en sort, et serait donc de nouveau notifié s'il venait à
# manquer encore (profil supprimé, fichier réimporté tronqué).
write_state() {
    local tmp_state="$STATE_FILE.tmp.$$"
    if printf '%s\n' "$missing" > "$tmp_state" 2>/dev/null; then
        mv -f "$tmp_state" "$STATE_FILE"
    else
        rm -f "$tmp_state" 2>/dev/null || true
        log "[WARN] État non inscriptible ($STATE_FILE) : la notification sera répétée."
    fi
}

touch "$STATE_FILE" 2>/dev/null || true
# grep sort en 1 quand il a tout filtré (tout est déjà notifié) : ce n'est PAS une
# erreur. Seul un code ≥ 2 (état illisible) fait tout considérer comme nouveau —
# mieux vaut un rappel en double qu'un rappel perdu. Un état vide ne filtre rien.
set +e
new="$(printf '%s\n' "$missing" | grep -vxF -f "$STATE_FILE" 2>/dev/null)"
grep_status=$?
set -e
if [ "$grep_status" -gt 1 ]; then
    new="$missing"
fi

count_missing=$(printf '%s\n' "$missing" | grep -c . || true)
log "[WARN] $count_missing mois de profil à importer."

if [ -z "$new" ]; then
    write_state
    log "── Déjà notifié, pas de nouvelle notification."
    exit 0
fi

# Libellés lisibles : « RLP0N/BE 2026-09 », séparés par des virgules.
labels="$(printf '%s\n' "$new" | awk 'NF == 3 { printf "%s%s/%s %s", (n++ ? ", " : ""), $1, $2, $3 }')"

page_hint="Administration → Profils de charge"
message="Synergrid publie le profil RLP après la clôture du mois. 1) Télécharger : ${download_url:-https://www.synergrid.be} 2) Convertir la feuille en CSV 3) Importer : ${upload_url:-$page_hint}"

if [ ! -x "$NOTIFY_BIN" ]; then
    log "[WARN] $NOTIFY_BIN introuvable — notification impossible (hors Unraid ?)."
    exit 0
fi

notify_args=(
    -e "Energy Cost Management"
    -s "Profil RLP à importer"
    -d "Mois à importer : $labels"
    -m "$message"
    -i "warning"
)
# Le lien rend la notification cliquable (affichage « détaillé » des notifications
# Unraid). Une URL externe remplace l'onglet de l'interface Unraid.
if [ -n "$upload_url" ]; then
    notify_args+=(-l "$upload_url")
fi

if "$NOTIFY_BIN" "${notify_args[@]}"; then
    write_state
    log "── Notification envoyée : $labels"
else
    # État inchangé : le prochain passage retentera la notification.
    log "[WARN] Échec de la notification Unraid ($NOTIFY_BIN)."
fi

exit 0
