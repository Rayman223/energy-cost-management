#!/usr/bin/env bash
# Manual only after migration: the internal scheduler owns the timetable.
set -euo pipefail
exec docker exec "${CONTAINER:-energy-cost-prd}" php app/scripts/run_scheduled_task.php dynamic-prices
