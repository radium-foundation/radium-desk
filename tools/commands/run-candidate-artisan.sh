#!/usr/bin/env bash
# Run Artisan from the desk P-14-41 candidate (App\ autoload = candidate app/).
set -euo pipefail

GATE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -z "${PHP_BIN:-}" ]]; then
  if [[ -x /opt/homebrew/bin/php ]]; then
    PHP_BIN=/opt/homebrew/bin/php
  else
    PHP_BIN=php
  fi
fi

export APP_BASE_PATH="$GATE_ROOT"
cd "$GATE_ROOT"

exec "$PHP_BIN" "$GATE_ROOT/tests/run-candidate-artisan.php" "$@"
