#!/usr/bin/env bash
# Static + unit checks for Hostinger snapshot verify-only gate (P-14-17).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE_SH="$ROOT/tools/lib/hostinger-snapshot-gate.sh"
GATE_PY="$ROOT/tools/lib/hostinger_vps_snapshot_gate.py"
GATE_CMD="$ROOT/tools/commands/hostinger-vps-snapshot-gate.sh"
UNIT="$ROOT/tests/scripts/hostinger_vps_snapshot_gate_unittest.py"
DEPLOY="$ROOT/tools/commands/deploy-kvm.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$GATE_SH" ]] || fail "gate shell missing"
[[ -f "$GATE_PY" ]] || fail "gate python missing"
bash -n "$GATE_SH" || fail "gate shell syntax"
bash -n "$DEPLOY" || fail "deploy-kvm syntax"
python3 -m py_compile "$GATE_PY" || fail "gate python syntax"

grep -q 'hostinger-snapshot-gate.sh' "$DEPLOY" || fail "deploy-kvm must source gate"
grep -q 'hostinger_reverify_snapshot_gate_before_rsync' "$DEPLOY" || fail "deploy must final-reverify"
grep -q 'import hostinger_vps_preflight' "$GATE_PY" || fail "gate must reuse preflight module"
grep -qE 'method=.POST|method=.PUT|method=.DELETE' "$GATE_PY" && fail "gate must be GET-only"
grep -q 'HostingerVpsPreflight' "$GATE_PY" || fail "gate must delegate to HostingerVpsPreflight"

print_warning() { WARN+=("$*"); }
print_error() { ERR+=("$*"); }
print_success() { :; }
WARN=()
ERR=()
DEPLOY_MODE=kvm
SSH_HOST="187.127.129.16"
REMOTE_PROJECT="/var/www/radium-desk"
RIP_KVM_PRODUCTION_SSH_HOST="187.127.129.16"
RIP_KVM_PRODUCTION_REMOTE_PROJECT="/var/www/radium-desk"
# shellcheck source=tools/lib/hostinger-snapshot-gate.sh
source "$GATE_SH"

unset HOSTINGER_SNAPSHOT_GATE
unset DEPLOY_TIER
if ! hostinger_verify_snapshot_gate_if_required; then
    fail "gate disabled should pass"
fi
pass "gate disabled skips"

export HOSTINGER_SNAPSHOT_GATE=1
export DEPLOY_TIER=C
if ! hostinger_verify_snapshot_gate_if_required; then
    fail "tier C should pass without python"
fi
pass "tier C exempt"

unset HOSTINGER_SNAPSHOT_GATE
export DEPLOY_TIER=A
WARN=()
hostinger_warn_if_unprotected_tier
[[ ${#WARN[@]} -ge 1 ]] || fail "tier A without gate should warn"
pass "unprotected tier warning"

python3 "$UNIT" || fail "unit tests"
pass "python unit tests"

pass "hostinger-snapshot-gate.test.sh complete"