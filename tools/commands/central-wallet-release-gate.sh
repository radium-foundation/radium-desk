#!/usr/bin/env bash
set -euo pipefail

PHASE="${1:-pre}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ "$PHASE" != "pre" && "$PHASE" != "post" ]]; then
  echo "Usage: $0 [pre|post]" >&2
  exit 1
fi

cd "$ROOT"
"$PHP_BIN" artisan central-wallet:verify-release-gate --phase="$PHASE" "$@"
