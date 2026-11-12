#!/usr/bin/env bash
# Host-only relay: no scheduled business job, socket or host binary in the app.
set -euo pipefail
container=${CONTAINER:-energy-cost-prd}
notify=${NOTIFY_BIN:-/usr/local/emhttp/webGui/scripts/notify}
root=${EC_ROOT:-/mnt/user/www/energy-cost}
mountpoint -q /mnt/user || { echo 'Array absent.' >&2; exit 1; }
docker() { timeout -k 5 30 docker "$@"; }
test -d "$root/state"
exec 9>"$root/state/notify.lock"
flock -n 9 || exit 0
queue=$(docker exec "$container" php app/scripts/notification_queue.php --list)
jq -e 'type == "array" and all(.[]; (.job == "dynamic-prices" or .job == "load-profiles") and
    (.id | test("^[a-f0-9]{32}$")) and (.kind == "missing" or .kind == "error") and
    (.labels | type == "array") and all(.labels[]; test("^[A-Za-z0-9_-]+/[A-Z]{2} [0-9]{4}-[0-9]{2}$")) and
    (.url | type == "string") and (.url | length < 2048))' <<< "$queue" >/dev/null
test -x "$notify"
failed=0
while IFS= read -r item; do
    job=$(jq -r .job <<< "$item")
    id=$(jq -r .id <<< "$item")
    kind=$(jq -r .kind <<< "$item")
    url=$(jq -r .url <<< "$item")
    args=(-e "Energy Cost Management")
    if [[ "$kind" == missing ]]; then
        labels=$(jq -r '.labels | join(", ")' <<< "$item")
        args+=(-s "Profil RLP à importer" -d "Mois à importer : $labels" -i warning
            -m "Télécharger sur Synergrid, convertir en CSV puis importer dans Administration → Profils de charge.")
        if [[ "$url" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]]; then
            args+=(-l "${url%/}/admin/load-profiles")
        fi
    else
        args+=(-s "Tâche Energy Cost en échec" -d "$job : consulter le journal privé." -i alert -m "Journal : $root/data/logs/$job.log")
    fi
    if timeout -k 5 30 "$notify" "${args[@]}"; then
        docker exec "$container" php app/scripts/notification_queue.php --ack "$job" "$id" || failed=1
    else
        failed=1
    fi
done < <(jq -c '.[]' <<< "$queue")
exit "$failed"
