#!/usr/bin/env bash
# P-15-19 — watchdog fallback when SIGALRM is ignored. Does not run PHPUnit.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${DESK_PHP_BIN:-/opt/homebrew/bin/php}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SENTINEL="$TMP/sentinel"
LOG="$TMP/budget.jsonl"
echo "busy" >"$SENTINEL"

"$PHP_BIN" -n -r 'pcntl_async_signals(true); pcntl_signal(SIGALRM, function (): void {}); $end = time() + 30; while (time() < $end) { }' &
TARGET=$!
"$PHP_BIN" -n "$ROOT/tests/Support/phpunit-test-budget-watchdog.php" "$SENTINEL" "$TARGET" 1 1 "$LOG" &
WATCH=$!

deadline=$((SECONDS + 12))
while kill -0 "$TARGET" 2>/dev/null; do
  if [[ "$SECONDS" -ge "$deadline" ]]; then
    kill -KILL "$TARGET" "$WATCH" 2>/dev/null || true
    echo "FAIL: ignored-SIGALRM process was still alive" >&2
    exit 1
  fi
  sleep 0.2
done
wait "$WATCH" 2>/dev/null || true
if kill -0 "$WATCH" 2>/dev/null; then
  kill -KILL "$WATCH" 2>/dev/null || true
  echo "FAIL: watchdog did not exit" >&2
  exit 1
fi
grep -q 'budget_fallback' "$LOG"
echo "PASS watchdog fallback pid=$TARGET"
