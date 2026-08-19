#!/usr/bin/env bash
#
# Static checks for desk CLI deployment routing (no production SSH).
#
# Run: bash tests/scripts/desk-routing.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DESK="$ROOT/tools/desk"
DEPLOY="$ROOT/tools/commands/deploy.sh"
DEPLOY_KVM="$ROOT/tools/commands/deploy-kvm.sh"
CONFIG="$ROOT/tools/config.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DESK" ]] || fail "tools/desk missing"
[[ -f "$DEPLOY" ]] || fail "deploy.sh missing"
[[ -f "$DEPLOY_KVM" ]] || fail "deploy-kvm.sh missing"
[[ -f "$CONFIG" ]] || fail "config.sh missing"

bash -n "$DESK" || fail "tools/desk syntax check failed"
bash -n "$DEPLOY" || fail "deploy.sh syntax check failed"
pass "desk and deploy.sh syntax valid"

grep -q 'source "$TOOLS_DIR/config.sh"' "$DESK" || fail "desk must source config.sh for DEPLOY_MODE routing"
grep -q 'desk_deploy' "$DESK" || fail "desk must define desk_deploy router"
grep -q 'deploy-kvm.sh' "$DESK" || fail "desk must reference deploy-kvm.sh"
grep -q 'deploy-legacy' "$DESK" || fail "desk must expose deploy-legacy command"

if awk '/^[[:space:]]*deploy\)/,/;;/ { print }' "$DESK" | grep -q 'deploy\.sh'; then
    fail "desk deploy must not exec deploy.sh directly"
fi

if ! awk '/^[[:space:]]*deploy\)/,/;;/ { print }' "$DESK" | grep -q 'desk_deploy'; then
    fail "desk deploy must route through desk_deploy"
fi

if ! awk '/desk_deploy\(\)/,/^}/' "$DESK" | grep -q 'DEPLOY_MODE.*kvm'; then
    fail "desk_deploy must gate on DEPLOY_MODE=kvm"
fi

if ! awk '/desk_deploy\(\)/,/^}/' "$DESK" | grep -q 'deploy-kvm.sh'; then
    fail "desk_deploy must exec deploy-kvm.sh when kvm"
fi

grep -q 'DEPLOY_MODE=kvm' "$DESK" || fail "desk help must document kvm routing"
grep -q 'deploy-legacy' "$DESK" || fail "desk help must document deploy-legacy"

pass "desk deploy routing guards present"

grep -q 'DEPLOY_MODE.*kvm' "$DEPLOY" || fail "deploy.sh must guard against kvm mode"

guard_line="$(grep -n 'DEPLOY_MODE.*kvm' "$DEPLOY" | head -1 | cut -d: -f1)"
pull_line="$(grep -n 'git pull' "$DEPLOY" | head -1 | cut -d: -f1)"
[[ -n "$guard_line" && -n "$pull_line" && "$guard_line" -lt "$pull_line" ]] \
    || fail "deploy.sh kvm guard must precede git pull"

pass "deploy.sh blocks legacy path when DEPLOY_MODE=kvm"

# deploy-kvm.sh must not be modified for routing; still requires kvm mode itself.
grep -q 'DEPLOY_MODE.*kvm' "$DEPLOY_KVM" || fail "deploy-kvm.sh must require DEPLOY_MODE=kvm"

pass "deploy-kvm.sh retains kvm-only guard"

# Help output includes routing distinction.
HELP_OUTPUT="$("$DESK" help)"
echo "$HELP_OUTPUT" | grep -q 'deploy-kvm' || fail "desk help missing deploy-kvm"
echo "$HELP_OUTPUT" | grep -q 'deploy-legacy' || fail "desk help missing deploy-legacy"
echo "$HELP_OUTPUT" | grep -q 'DEPLOY_MODE=kvm' || fail "desk help missing DEPLOY_MODE=kvm note"

pass "desk help documents kvm vs legacy deploy"

if ! awk '/desk_deploy\(\)/,/^}/' "$DESK" | grep -q 'deploy-legacy'; then
    fail "desk_deploy must direct users to deploy-legacy when DEPLOY_MODE is not kvm"
fi

pass "desk deploy refuses non-kvm DEPLOY_MODE without legacy fallback"

echo "All desk routing static checks passed."
