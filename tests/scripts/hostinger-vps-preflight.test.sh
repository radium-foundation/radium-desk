#!/usr/bin/env bash
#
# Static + unit checks for Hostinger VPS preflight (P-14-09).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CMD="$ROOT/tools/commands/hostinger-vps-preflight.sh"
LIB="$ROOT/tools/lib/hostinger_vps_preflight.py"
UNIT="$ROOT/tests/scripts/hostinger_vps_preflight_unittest.py"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$CMD" ]] || fail "command script missing"
[[ -f "$LIB" ]] || fail "library missing"
bash -n "$CMD" || fail "command syntax"
python3 -m py_compile "$LIB" || fail "python syntax"

grep -q 'hostinger_vps_preflight.py' "$CMD" || fail "command must invoke python module"
grep -qE 'print\(token|print\(f?".*\{token\}' "$LIB" && fail "must not print token"
grep -q 'USER_AGENT' "$LIB" || fail "must set User-Agent"
grep -q '1910783' "$LIB" || fail "must pin verified vm id"
grep -q 'srv1910783.hstgr.cloud' "$LIB" || fail "must pin verified hostname"
if grep -qE 'POST|PUT|PATCH|DELETE' "$LIB"; then
    if grep -vE '^[[:space:]]*#|docstring|GET only|Read-only' "$LIB" | grep -qE 'method=.POST|method=.PUT|method=.PATCH|method=.DELETE'; then
        fail "must not use mutating HTTP methods"
    fi
fi
pass "static guards"

python3 "$UNIT" || fail "unit tests"
pass "python unit tests"

pass "hostinger-vps-preflight.test.sh complete"