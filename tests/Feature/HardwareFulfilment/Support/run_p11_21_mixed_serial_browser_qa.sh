#!/usr/bin/env bash
set -euo pipefail

# Local mixed-serial browser QA (P-11-21). Unsets inherited DB_DATABASE so artisan serve
# uses .env sqlite (a stale shell DB_DATABASE breaks session/login).

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
PORT="${P11_21_QA_PORT:-8771}"
PHP="${PHP_BIN:-/opt/homebrew/bin/php}"

cd "$ROOT"

env -u DB_DATABASE "$PHP" tests/Feature/HardwareFulfilment/Support/bootstrap_p11_21_mixed_serial_browser.php

lsof -ti ":$PORT" | xargs kill -9 2>/dev/null || true
env -u DB_DATABASE APP_ENV=local nohup "$PHP" artisan serve --host=127.0.0.1 --port="$PORT" >/tmp/p11-21-serve.log 2>&1 &
SERVE_PID=$!
trap 'kill -9 "$SERVE_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
  if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/login"; then
    break
  fi
  sleep 0.25
done

export INVENTORY_POS_QA_CHROME="${INVENTORY_POS_QA_CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
env -u DB_DATABASE node tests/Feature/HardwareFulfilment/Support/run_p11_21_mixed_serial_browser_qa.cjs
