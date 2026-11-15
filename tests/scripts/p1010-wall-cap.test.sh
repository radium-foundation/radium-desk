#!/usr/bin/env bash
# P-15-19 — process-group wall cap. Disposable sleep only. Does not launch PHPUnit.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../../bin/phpunit-p1010/p1010-wrapper-lib.sh
source "$ROOT/bin/phpunit-p1010/p1010-wrapper-lib.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"; [[ -n "${OUTSIDER:-}" ]] && kill "$OUTSIDER" 2>/dev/null || true' EXIT

cat >"$TMP/parent.sh" <<'EOF'
#!/bin/bash
echo $$ >"$1"
sleep 30 &
echo $! >"$2"
wait
EOF
chmod +x "$TMP/parent.sh"

sleep 60 &
OUTSIDER=$!

run_case() {
  local label="$1"
  shift
  local reason="$TMP/${label}.reason"
  local parent="$TMP/${label}.parent"
  local child="$TMP/${label}.child"
  p1010_spawn_bounded 2 1 "$reason" "$@" bash "$TMP/parent.sh" "$parent" "$child"
  p1010_finish_bounded
  local parent_pid child_pid
  parent_pid="$(cat "$parent")"
  child_pid="$(cat "$child")"
  if kill -0 "$parent_pid" 2>/dev/null; then
    echo "FAIL: $label parent $parent_pid still alive" >&2
    exit 1
  fi
  if kill -0 "$child_pid" 2>/dev/null; then
    echo "FAIL: $label child $child_pid still alive" >&2
    exit 1
  fi
  if ! grep -q '^wall_clock_cap$' "$reason"; then
    echo "FAIL: $label reason was $(cat "$reason")" >&2
    exit 1
  fi
  if [[ "$P1010_BOUNDED_EXIT" -ne 143 && "$P1010_BOUNDED_EXIT" -ne 137 ]]; then
    echo "FAIL: $label exit $P1010_BOUNDED_EXIT" >&2
    exit 1
  fi
  if ! kill -0 "$OUTSIDER" 2>/dev/null; then
    echo "FAIL: $label killed unrelated outsider $OUTSIDER" >&2
    exit 1
  fi
  echo "PASS $label parent=$parent_pid child=$child_pid exit=$P1010_BOUNDED_EXIT reason=wall_clock_cap outsider=$OUTSIDER"
}

run_case direct
run_case layered stdbuf -oL -eL env
echo "WALL_CAP_TESTS_OK"
