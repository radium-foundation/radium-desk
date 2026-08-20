#!/usr/bin/env bash
#
# Static checks for desk CLI rollback routing (no production SSH).
#
# Run: bash tests/scripts/rollback-routing.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DESK="$ROOT/tools/desk"
ROLLBACK="$ROOT/tools/commands/rollback.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DESK" ]] || fail "tools/desk missing"
[[ -f "$ROLLBACK" ]] || fail "rollback.sh missing"

bash -n "$DESK" || fail "tools/desk syntax check failed"
bash -n "$ROLLBACK" || fail "rollback.sh syntax check failed"
pass "desk and rollback.sh syntax valid"

grep -q 'desk_rollback' "$DESK" || fail "desk must define desk_rollback router"
grep -q 'desk_rollback_legacy' "$DESK" || fail "desk must define desk_rollback_legacy router"
grep -q 'rollback-legacy' "$DESK" || fail "desk must expose rollback-legacy command"

if awk '/^[[:space:]]*rollback\)/,/;;/ { print }' "$DESK" | grep -q 'rollback\.sh'; then
    fail "desk rollback must not exec rollback.sh directly"
fi

if ! awk '/^[[:space:]]*rollback\)/,/;;/ { print }' "$DESK" | grep -q 'desk_rollback'; then
    fail "desk rollback must route through desk_rollback"
fi

if ! awk '/desk_rollback\(\)/,/^}/' "$DESK" | grep -q 'DEPLOY_MODE.*kvm'; then
    fail "desk_rollback must gate on DEPLOY_MODE=kvm"
fi

if awk '/desk_rollback\(\)/,/^}/' "$DESK" | grep -q 'rollback\.sh'; then
    fail "desk_rollback must not exec rollback.sh"
fi

if ! awk '/desk_rollback_legacy\(\)/,/^}/' "$DESK" | grep -q 'rollback\.sh'; then
    fail "desk_rollback_legacy must exec rollback.sh when allowed"
fi

if ! awk '/desk_rollback_legacy\(\)/,/^}/' "$DESK" | grep -q 'DEPLOY_MODE.*kvm'; then
    fail "desk_rollback_legacy must refuse when DEPLOY_MODE=kvm"
fi

pass "desk rollback routing guards present"

grep -q 'DEPLOY_MODE.*kvm' "$ROLLBACK" || fail "rollback.sh must guard against kvm mode"

guard_line="$(grep -n 'DEPLOY_MODE.*kvm' "$ROLLBACK" | head -1 | cut -d: -f1)"
git_log_line="$(grep -n 'git log' "$ROLLBACK" | head -1 | cut -d: -f1)"
git_reset_line="$(grep -n 'git reset' "$ROLLBACK" | head -1 | cut -d: -f1)"
[[ -n "$guard_line" && -n "$git_log_line" && "$guard_line" -lt "$git_log_line" ]] \
    || fail "rollback.sh kvm guard must precede git log"
[[ -n "$guard_line" && -n "$git_reset_line" && "$guard_line" -lt "$git_reset_line" ]] \
    || fail "rollback.sh kvm guard must precede git reset"

pass "rollback.sh blocks legacy path when DEPLOY_MODE=kvm"

HELP_OUTPUT="$("$DESK" help)"
echo "$HELP_OUTPUT" | grep -q 'rollback-legacy' || fail "desk help missing rollback-legacy"
echo "$HELP_OUTPUT" | grep -q 'rollback disabled' || fail "desk help must note rollback disabled on kvm"

pass "desk help documents kvm vs legacy rollback"

REFUSE_OUTPUT="$("$DESK" rollback 2>&1 >/dev/null || true)"
echo "$REFUSE_OUTPUT" | grep -qi 'disabled when DEPLOY_MODE=kvm' || fail "desk rollback must refuse on kvm config"
echo "$REFUSE_OUTPUT" | grep -qi 'known-good release/tag' || fail "desk rollback must mention future tag redeploy"
echo "$REFUSE_OUTPUT" | grep -q 'rollback.sh' && fail "desk rollback refusal must not invoke rollback.sh"

LEGACY_REFUSE_OUTPUT="$("$DESK" rollback-legacy 2>&1 >/dev/null || true)"
echo "$LEGACY_REFUSE_OUTPUT" | grep -qi 'rollback-legacy is disabled when DEPLOY_MODE=kvm' \
    || fail "desk rollback-legacy must refuse on kvm config"
echo "$LEGACY_REFUSE_OUTPUT" | grep -q 'git reset' && fail "desk rollback-legacy refusal must not mention executing git reset"

pass "desk rollback commands refuse on kvm without remote git operations"

if grep -vE '^\s*#' "$DESK" | awk '/desk_rollback\(\)/,/^}/' | grep -qE 'git reset'; then
    fail "kvm desk rollback path must not invoke git reset"
fi

pass "no kvm rollback path invokes remote git reset"

echo "All rollback routing static checks passed."
