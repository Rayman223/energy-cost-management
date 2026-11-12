#!/usr/bin/env bash
# Unraid host operator. No production action is performed by a Docker entrypoint.
set -Eeuo pipefail
umask 077
ROOT=${EC_ROOT:-/mnt/user/www/energy-cost}
CONTAINER=${CONTAINER:-energy-cost-prd}
NETWORK=${EC_NETWORK:-swagproxy}
PROXY=${EC_PROXY:-swag}
BACKUP_CONTAINER=${EC_BACKUP_CONTAINER:-database-backup}
BACKUP_JOB=${EC_BACKUP_JOB:-energyv3}
BACKUP_ROOT=${EC_BACKUP_ROOT:-/mnt/user/appdata/database-backup/backups}
REPOSITORY=${EC_REPOSITORY:-https://github.com/Rayman223/energy-cost-management.git}
BASE_URL=${EC_BASE_URL:-https://energy-cost.eu}
HEALTH_TIMEOUT=${EC_HEALTH_TIMEOUT:-180}
command=${1:-update}
[[ $# == 0 ]] || shift
case "$command" in prepare|activate|update|status|backup|rollback|resume-announcements) ;; *)
    echo 'Usage: deploy_unraid.sh prepare [main|tag] | activate | update [main|tag] | status | backup | rollback --schema-compatible | resume-announcements' >&2
    exit 2;;
esac
[[ "$ROOT" == /* && "$ROOT" != / && "$CONTAINER" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]+$ && "$BACKUP_JOB" =~ ^[a-zA-Z0-9_-]+$ && "$HEALTH_TIMEOUT" =~ ^[1-9][0-9]*$ ]] || exit 2
for binary in docker git jq flock timeout tar gzip sha256sum mountpoint; do command -v "$binary" >/dev/null; done
mountpoint -q /mnt/user || { echo 'Array /mnt/user absent.' >&2; exit 1; }
test -d "$ROOT" || { echo 'Préparer la racine selon le guide Unraid.' >&2; exit 1; }
mkdir -p "$ROOT/state"
exec 9>"$ROOT/state/operator.lock"
flock -n 9 || { echo 'Opération déjà active.' >&2; exit 75; }
docker() {
    local limit=10
    case "$1" in build|run|exec|stop) limit=600;; esac
    timeout -k 5 "$limit" docker "$@"
}
docker info >/dev/null
phase=preflight
previous_image=
previous_running=false
build=
publish_release=true
on_error() {
    code=$?
    trap - ERR
    echo "Échec (phase=$phase). Consulter state/delivery.json et les reçus Discord." >&2
    if [[ "$phase" == stopped && "$previous_running" == true ]]; then
        image=$previous_image
        commit=$(jq -r .commit "$ROOT/state/current.json")
        if docker start "$CONTAINER" >/dev/null && healthy; then
            rm -f -- "$ROOT/data/var/maintenance"
            if probe; then
                rm -f -- "$ROOT/data/var/cron-paused"
                record complete || true
            else
                touch "$ROOT/data/var/maintenance"
            fi
        fi
    elif [[ "$phase" == migrations_started || "$phase" == migrated || "$phase" == switched || "$phase" == backup_restarting ]]; then
        touch "$ROOT/data/var/maintenance" "$ROOT/data/var/cron-paused"
        echo 'Maintenance conservée : diagnostiquer le schéma avant tout retour arrière.' >&2
    fi
    exit "$code"
}
trap on_error ERR
record() {
    local current=${1:-$phase}
    jq -n --arg phase "$current" --arg image "${image:-}" --arg commit "${commit:-}" \
        --arg backup "${backup_dir:-}" --arg previous "$previous_image" --argjson publish "$publish_release" \
        '{phase:$phase,image:$image,commit:$commit,backup:$backup,previous_image:$previous,publish_release:$publish}' > "$ROOT/state/delivery.tmp"
    mv "$ROOT/state/delivery.tmp" "$ROOT/state/delivery.json"
}
mounts=(
    --mount "type=bind,source=$ROOT/config,target=/app/app/config,readonly"
    --mount "type=bind,source=$ROOT/data/var,target=/app/var"
    --mount "type=bind,source=$ROOT/data/logs,target=/app/logs"
    --mount "type=bind,source=$ROOT/backups/manual,target=/app/backups"
    --env-file "$ROOT/config/runtime.env"
)
preflight() {
    docker network inspect "$NETWORK" >/dev/null
    test -r "$ROOT/config/config.php"
    test -s "$ROOT/config/runtime.env"
    grep -Eq '^EC_SCHEDULER_TZ=[A-Za-z_]+(/[A-Za-z0-9_+-]+)*$' "$ROOT/config/runtime.env"
    docker exec "$PROXY" nginx -t
}
cli() {
    docker run --rm --pull=never --network "$NETWORK" "${mounts[@]}" --entrypoint php "$image" "$@"
}
build_image() {
    local ref=${1:-main}
    [[ "$ref" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo 'Référence Git invalide.' >&2; return 2; }
    test -d "$ROOT/src/.git"
    [[ "$(git -C "$ROOT/src" remote get-url origin)" == "$REPOSITORY" ]] || { echo 'Remote inattendu.' >&2; return 1; }
    if [[ "$ref" == main ]]; then
        git -C "$ROOT/src" fetch origin refs/heads/main
    else
        git -C "$ROOT/src" fetch origin "refs/tags/$ref"
    fi
    commit=$(git -C "$ROOT/src" rev-parse 'FETCH_HEAD^{commit}')
    build="$ROOT/state/build-$commit-$$"
    mkdir "$build"
    git -C "$ROOT/src" archive "$commit" | tar -x -C "$build"
    docker build --target production --build-arg "EC_COMMIT=$commit" -t "energy-cost-local:$commit" "$build"
    image=$(docker image inspect -f '{{.Id}}' "energy-cost-local:$commit")
    [[ "$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")" == "$commit" ]]
    cli app/scripts/config_check.php
    local proxy_ip
    proxy_ip=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "$PROXY")
    cli -r '$c=require "app/bootstrap.php"; if (!in_array($argv[1], $c["web_security"]["trusted_proxies"] ?? [], true) || ($c["seo"]["base_url"] ?? "") !== $argv[2]) { fwrite(STDERR, "Configurer trusted_proxies (IP SWAG exacte) et seo.base_url.\n"); exit(1); }' "$proxy_ip" "$BASE_URL"
    cli -r '$c=require "app/bootstrap.php"; (new App\Infrastructure\Database($c["database"]))->pdo()->query("SELECT 1"); echo "PHP/DB OK\n";'
    cli app/scripts/release-notes.php --validate
}
create_container() {
    docker run -d --name "$CONTAINER" --pull=never --network "$NETWORK" \
        --restart unless-stopped --stop-timeout 300 --log-opt max-size=10m --log-opt max-file=3 --label eu.energy-cost.managed=prd \
        "${mounts[@]}" "$image" >/dev/null
}
healthy() {
    local end=$((SECONDS + HEALTH_TIMEOUT)) state
    while (( SECONDS < end )); do
        state=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$CONTAINER")
        [[ "$state" != healthy ]] || return 0
        [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" == true ]] || return 1
        sleep 2
    done
    echo 'Santé non obtenue dans le délai.' >&2
    return 1
}
probe() {
    # Health is independent of visitor restrictions. Never forge an allowed client IP.
    docker exec "$PROXY" curl --fail --silent --show-error --max-time 30 \
        "http://$CONTAINER:8080/healthz" >/dev/null || return 1
    local headers status ip_restricted
    ip_restricted=$(docker exec "$CONTAINER" php -r '$c=require "app/bootstrap.php"; $s=$c["web_security"] ?? []; echo (($s["enabled"] ?? false) && !empty($s["allowed_ips"])) ? "1" : "0";') || return 1
    headers=$(docker exec "$PROXY" curl --silent --show-error --max-time 30 -D - -o /dev/null -w '\n%{http_code}' "$BASE_URL/login") || return 1
    status=${headers##*$'\n'}
    if [[ "$status" != 200 && ! ( "$status" == 403 && "$ip_restricted" == 1 ) ]]; then
        echo "Contrôle HTTP refusé (statut $status)." >&2
        return 1
    fi
    tr -d '\r' <<< "$headers" | grep -qiFx "X-Energy-Cost-Revision: $commit" || {
        echo 'Révision publique différente : vérifier la bascule SWAG/CDN.' >&2
        return 1
    }
}

container_present() {
    local names
    names=$(docker container ls --all --format '{{.Names}}') || return 1
    if grep -Fxq -- "$CONTAINER" <<< "$names"; then echo true; else echo false; fi
}
assert_managed() {
    [[ "$(docker inspect -f '{{index .Config.Labels "eu.energy-cost.managed"}}' "$CONTAINER")" == prd ]] || {
        echo 'Conteneur non géré par cet opérateur.' >&2; return 1;
    }
    local actual
    actual=$(docker inspect -f '{{json .Mounts}}' "$CONTAINER") || return 1
    jq -e --arg root "${ROOT%/}" '
      . as $actual |
      [
        {source:($root+"/config"),target:"/app/app/config",rw:false},
        {source:($root+"/data/var"),target:"/app/var",rw:true},
        {source:($root+"/data/logs"),target:"/app/logs",rw:true},
        {source:($root+"/backups/manual"),target:"/app/backups",rw:true}
      ] | all(.[]; . as $expected |
        any($actual[]; .Type=="bind" and .Source==$expected.source and
          .Destination==$expected.target and .RW==$expected.rw))
    ' <<< "$actual" >/dev/null || {
        echo 'Montages différents de la racine configurée : opération refusée.' >&2
        return 1
    }
}
pause() {
    test ! -f "$ROOT/data/var/maintenance"
    test ! -f "$ROOT/data/var/cron-paused"
    previous_running=$(docker inspect -f '{{.State.Running}}' "$CONTAINER")
    touch "$ROOT/data/var/maintenance" "$ROOT/data/var/cron-paused"
    phase=stopping
    record
    if [[ "$previous_running" == true ]]; then
        docker exec "$CONTAINER" php app/scripts/drain_scheduled_tasks.php
    fi
    docker stop --time 300 "$CONTAINER" >/dev/null
    phase=stopped
}
make_backup() {
    # A fresh, unambiguous dump is mandatory. Encrypted formats fail closed.
    local marker dump folder="$BACKUP_ROOT/mariadb/$BACKUP_JOB"
    test -d "$folder"
    marker=$(mktemp "$ROOT/state/backup-start-XXXXXX")
    sleep 1
    docker exec "$BACKUP_CONTAINER" dbbackup backup --job "$BACKUP_JOB"
    mapfile -d '' dumps < <(find "$folder" -maxdepth 1 -type f -newer "$marker" -print0)
    rm -f -- "$marker"
    [[ ${#dumps[@]} == 1 ]] || { echo 'Dump frais non identifiable.' >&2; return 1; }
    dump=${dumps[0]}
    [[ "$dump" == *.sql.gz && -s "$dump" ]] || { echo 'Dump attendu : .sql.gz non vide ; déchiffrer explicitement les autres formats.' >&2; return 1; }
    gzip -t "$dump"
    backup_dir="$ROOT/backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mkdir -m 700 "$backup_dir"
    cp -- "$dump" "$backup_dir/database.sql.gz"
    tar --exclude='state/build-*' --exclude='*.lock' -czf "$backup_dir/files.tar.gz" -C "$ROOT" config data state
    gzip -t "$backup_dir/files.tar.gz"
    (cd "$backup_dir" && sha256sum database.sql.gz files.tar.gz > SHA256SUMS)
    jq -n --arg image "$previous_image" --arg time "$(date -u +%FT%TZ)" '{image:$image,created_at:$time}' > "$backup_dir/manifest.json"
    # Only complete snapshots expire; incomplete ones require inspection.
    while IFS= read -r -d '' old; do
        test ! -f "$old/manifest.json" || rm -rf -- "$old"
    done < <(find "$ROOT/backups" -mindepth 1 -maxdepth 1 -type d -name '20*T*Z-*' -mtime +30 -print0)
    echo "Sauvegarde vérifiée : $backup_dir"
}
install_volumes() {
    for dir in data/var data/logs backups/manual; do
        mkdir -p "$ROOT/$dir"
        chown -R 33:33 "$ROOT/$dir"
        chmod 750 "$ROOT/$dir"
        touch "$ROOT/$dir/.ec-volume"
        chown 33:33 "$ROOT/$dir/.ec-volume"
    done
    mkdir -p "$ROOT/data/var/scheduler" "$ROOT/data/var/notifications" "$ROOT/data/var/tmp" "$ROOT/data/var/sessions"
    chown -R 33:33 "$ROOT/data/var"
    chown root:33 "$ROOT/config"
    chmod 750 "$ROOT/config"
    for file in config.php config.local.php runtime.env; do
        if [[ -f "$ROOT/config/$file" ]]; then
            chown root:33 "$ROOT/config/$file"
            chmod 640 "$ROOT/config/$file"
        fi
    done
    touch "$ROOT/data/var/container-installed" "$ROOT/data/var/maintenance" "$ROOT/data/var/cron-paused"
}
save_current() {
    if [[ -f "$ROOT/state/current.json" ]]; then
        [[ "$(jq -r .image "$ROOT/state/current.json")" != "$image" ]] || return 0
        cp "$ROOT/state/current.json" "$ROOT/state/previous.json"
    fi
    jq -n --arg image "$image" --arg commit "$commit" '{image:$image,commit:$commit}' > "$ROOT/state/current.tmp"
    mv "$ROOT/state/current.tmp" "$ROOT/state/current.json"
}
complete() {
    rm -f -- "$ROOT/data/var/maintenance"
    healthy
    probe
    phase=server_healthy
    record
    # Only after HTTP validation; failed publication can be resumed independently.
    if [[ "$publish_release" == true ]]; then
        docker exec "$CONTAINER" php app/scripts/release-notes.php --publish
    fi
    rm -f -- "$ROOT/data/var/cron-paused"
    phase=complete
    record
}
if [[ "$command" != status ]]; then preflight; fi
case "$command" in
    prepare)
        test ! -f "$ROOT/state/delivery.json"
        test ! -f "$ROOT/state/current.json"
        present=$(container_present)
        if [[ "$present" == true ]]; then
            echo 'Conteneur déjà présent : aucune préparation ni modification des volumes.' >&2
            exit 1
        fi
        install_volumes
        build_image "${1:-main}"
        phase=prepared
        record
        create_container
        healthy
        echo 'Préparation OK ; maintenance et crons suspendus. Geler l’ancienne instance puis lancer activate.'
        ;;
    activate)
        [[ "$(jq -r .phase "$ROOT/state/delivery.json")" == prepared ]]
        assert_managed
        image=$(jq -r .image "$ROOT/state/delivery.json")
        commit=$(jq -r .commit "$ROOT/state/delivery.json")
        test -f "$ROOT/data/var/maintenance"
        test -f "$ROOT/data/var/cron-paused"
        docker stop --time 300 "$CONTAINER" >/dev/null
        make_backup
        phase=migrations_started
        record
        cli app/scripts/migrate.php
        phase=migrated
        record
        docker start "$CONTAINER" >/dev/null
        healthy
        echo 'Migrations et santé OK. Basculer SWAG selon le guide puis lancer resume-announcements.'
        ;;
    update)
        assert_managed
        [[ "$(jq -r .phase "$ROOT/state/delivery.json")" == complete ]]
        previous_image=$(docker inspect -f '{{.Image}}' "$CONTAINER")
        build_image "${1:-main}"
        pause
        make_backup
        if [[ "$image" != "$previous_image" ]]; then
            cp "$ROOT/state/current.json" "$ROOT/state/previous.json"
        fi
        phase=migrations_started
        record
        cli app/scripts/migrate.php
        phase=migrated
        record
        docker rm "$CONTAINER" >/dev/null
        create_container
        phase=switched
        record
        healthy
        save_current
        complete
        ;;
    resume-announcements)
        phase=$(jq -r .phase "$ROOT/state/delivery.json")
        case "$phase" in migrated|switched|server_healthy|backup_restarting) ;; *) echo 'Livraison non validable.' >&2; exit 1;; esac
        image=$(jq -r .image "$ROOT/state/delivery.json")
        commit=$(jq -r .commit "$ROOT/state/delivery.json")
        previous_image=$(jq -r '.previous_image // ""' "$ROOT/state/delivery.json")
        backup_dir=$(jq -r '.backup // ""' "$ROOT/state/delivery.json")
        publish_release=$(jq -r 'if .publish_release == false then false else true end' "$ROOT/state/delivery.json")
        present=$(container_present)
        if [[ "$present" == true ]]; then
            assert_managed
            [[ "$(docker inspect -f '{{.Image}}' "$CONTAINER")" == "$image" ]]
        else
            [[ "$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")" == "$commit" ]]
            touch "$ROOT/data/var/maintenance" "$ROOT/data/var/cron-paused"
            create_container
        fi
        if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" != true ]]; then
            docker start "$CONTAINER" >/dev/null
        fi
        healthy
        save_current
        complete
        ;;
    backup)
        publish_release=false
        commit=$(jq -r .commit "$ROOT/state/current.json")
        image=$(jq -r .image "$ROOT/state/current.json")
        assert_managed
        [[ "$(jq -r .phase "$ROOT/state/delivery.json")" == complete ]]
        previous_image=$(docker inspect -f '{{.Image}}' "$CONTAINER")
        pause
        make_backup
        phase=backup_restarting
        record
        docker start "$CONTAINER" >/dev/null
        complete
        ;;
    rollback)
        publish_release=false
        [[ "${1:-}" == --schema-compatible ]] || { echo 'Confirmer la compatibilité SQL : rollback --schema-compatible' >&2; exit 2; }
        image=$(jq -r .image "$ROOT/state/previous.json")
        commit=$(jq -r .commit "$ROOT/state/previous.json")
        docker image inspect "$image" >/dev/null
        present=$(container_present)
        if [[ "$present" == true ]]; then
            assert_managed
            previous_image=$(docker inspect -f '{{.Image}}' "$CONTAINER")
        else
            previous_image=$(jq -er .image "$ROOT/state/current.json")
        fi
        # Retain an existing maintenance state after a failed migration.
        touch "$ROOT/data/var/maintenance" "$ROOT/data/var/cron-paused"
        if [[ "$present" == true ]]; then
            if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" == true ]]; then
                docker exec "$CONTAINER" php app/scripts/drain_scheduled_tasks.php
            fi
            docker stop --time 300 "$CONTAINER" >/dev/null
        fi
        make_backup
        phase=migrations_started
        record
        if [[ "$present" == true ]]; then docker rm "$CONTAINER" >/dev/null; fi
        create_container
        phase=switched
        record
        healthy
        save_current
        rm -f -- "$ROOT/data/var/maintenance"
        probe
        rm -f -- "$ROOT/data/var/cron-paused"
        phase=complete
        record
        ;;
    status)
        cat "$ROOT/state/delivery.json"
        docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$CONTAINER"
        ;;
esac
