#!/usr/bin/env bash
# Manual only after migration. Notifications are delivered by notify-unraid.sh.
set -euo pipefail
exec docker exec "${CONTAINER:-energy-cost-prd}" php app/scripts/run_scheduled_task.php load-profiles
