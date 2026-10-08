#!/usr/bin/env bash
set -euo pipefail

PHASE="${1:-pre}"
PHP_BIN="${PHP_BIN:-php}"

if [[ "$PHASE" != "pre" && "$PHASE" != "post" ]]; then
  echo "Usage: $0 [pre|post]" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
declare -a PROJECTS=(
  "$ROOT|radium-desk"
  "$ROOT/../rdservice.in|rdservice.in"
  "$ROOT/../radiumbox.com|radiumbox.com"
  "$ROOT/../rdservice.net|rdservice.net"
)

OVERALL=0

for entry in "${PROJECTS[@]}"; do
  IFS='|' read -r path label <<< "$entry"
  echo "===== CENTRAL WALLET RELEASE GATE ($label) phase=$PHASE ====="
  if [[ ! -d "$path" ]]; then
    echo "SKIP: missing path $path" >&2
    OVERALL=1
    continue
  fi
  (
    cd "$path"
    "$PHP_BIN" artisan central-wallet:verify-release-gate --phase="$PHASE"
  ) || OVERALL=1
  echo
done

exit "$OVERALL"
