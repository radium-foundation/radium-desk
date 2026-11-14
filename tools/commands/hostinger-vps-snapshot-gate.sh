#!/usr/bin/env bash
# Hostinger snapshot verify-only gate CLI (invokes Python GET gate).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$SCRIPT_DIR/../lib/hostinger_vps_snapshot_gate.py" "$@"