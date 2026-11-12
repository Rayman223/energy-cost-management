#!/usr/bin/env bash
set -euo pipefail
umask 027
cd /app
test -r app/config/config.php
for volume in var logs backups; do
    test -f "$volume/.ec-volume" || { echo "Volume non initialisé : $volume" >&2; exit 1; }
done
test -f var/container-installed
mkdir -p var/sessions var/tmp var/scheduler var/notifications
rm -f var/scheduler/heartbeat
export TMPDIR=/app/var/tmp
exec "$@"
