#!/usr/bin/env bash
#
# Read-only Hostinger VPS preflight (GET /api/vps/v1/* only).
# P-14-09 — credential at ~/.config/hostinger/token (never committed).
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PY="$SCRIPT_DIR/../lib/hostinger_vps_preflight.py"

if [[ ! -f "$PY" ]]; then
    echo "ERROR: missing $PY" >&2
    exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
    echo "ERROR: python3 is required for Hostinger VPS preflight" >&2
    exit 1
fi

exec python3 "$PY" "$@"