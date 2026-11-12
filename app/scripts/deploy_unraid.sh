#!/usr/bin/env bash
# Run on the Unraid host; the checked-out operator is updated explicitly.
set -euo pipefail
directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
exec bash "$directory/deploy/unraid.sh" "$@"
