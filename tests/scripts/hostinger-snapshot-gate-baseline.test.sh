#!/usr/bin/env bash
# P-23-18 — Hostinger snapshot baseline survives initial gate command substitution.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE_SH="$ROOT/tools/lib/hostinger-snapshot-gate.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

MOCK_PY="$(mktemp "${TMPDIR:-/tmp}/hostinger-snapshot-gate-mock.XXXXXX.py")"
trap 'rm -f "$MOCK_PY"' EXIT

cat >"$MOCK_PY" <<'PY'
import json
import os
import sys

phase = os.environ.get("HOSTINGER_SNAPSHOT_GATE_PHASE", "initial")
baseline = os.environ.get("HOSTINGER_SNAPSHOT_GATE_BASELINE_ID", "").strip()

if phase == "initial":
    print(json.dumps({"ok": True, "snapshot_id": 470114, "phase": "initial"}))
    sys.exit(0)

if phase == "final":
    if not baseline:
        print(json.dumps({"ok": False, "abort_reason": "baseline_missing_in_env"}))
        sys.exit(0)
    try:
        b = int(baseline)
    except ValueError:
        print(json.dumps({"ok": False, "abort_reason": "baseline_invalid"}))
        sys.exit(0)
    if b != 470114:
        print(json.dumps({"ok": False, "abort_reason": f"baseline_mismatch:{b}"}))
        sys.exit(0)
    print(json.dumps({"ok": True, "snapshot_id": b, "phase": "final"}))
    sys.exit(0)

print(json.dumps({"ok": False, "abort_reason": f"unknown_phase:{phase}"}))
PY

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

export HOSTINGER_SNAPSHOT_GATE=1
export DEPLOY_TIER=A
export HOSTINGER_SNAPSHOT_GATE_PY="$MOCK_PY"
unset HOSTINGER_SNAPSHOT_GATE_BASELINE_ID
unset HOSTINGER_SNAPSHOT_GATE_LOCK
unset HOSTINGER_SNAPSHOT_GATE_LOCK_HELD

# shellcheck source=tools/lib/hostinger-snapshot-gate.sh
source "$GATE_SH"

if ! hostinger_verify_snapshot_gate_if_required; then
    fail "initial gate should pass with mock python"
fi

[[ "${HOSTINGER_SNAPSHOT_GATE_BASELINE_ID:-}" == "470114" ]] \
    || fail "baseline not persisted in parent shell after initial gate (got: ${HOSTINGER_SNAPSHOT_GATE_BASELINE_ID:-<unset>})"
pass "baseline persisted after command-substitution initial gate"

if ! hostinger_reverify_snapshot_gate_before_rsync; then
    fail "final gate should pass when baseline persisted"
fi
pass "final gate receives persisted baseline"

unset HOSTINGER_SNAPSHOT_GATE_BASELINE_ID
if hostinger_reverify_snapshot_gate_before_rsync; then
    fail "final gate must fail when baseline missing"
fi
[[ "${#ERR[@]}" -ge 1 ]] || fail "expected error when baseline missing"
pass "final gate fails closed without baseline"

export HOSTINGER_SNAPSHOT_GATE_BASELINE_ID=999999
MOCK_PY_NO_ID="$(mktemp "${TMPDIR:-/tmp}/hostinger-snapshot-gate-mock-noid.XXXXXX.py")"
cat >"$MOCK_PY_NO_ID" <<'PY'
import json
print(json.dumps({"ok": True, "phase": "initial"}))
PY
export HOSTINGER_SNAPSHOT_GATE_PY="$MOCK_PY_NO_ID"
ERR=()
if hostinger_verify_snapshot_gate_if_required; then
    fail "initial gate must fail when ok but snapshot_id absent"
fi
pass "initial gate fails when report ok but snapshot_id missing"

MOCK_PY_MISMATCH="$(mktemp "${TMPDIR:-/tmp}/hostinger-snapshot-gate-mock-mismatch.XXXXXX.py")"
cat >"$MOCK_PY_MISMATCH" <<'PY'
import json
print(json.dumps({"ok": True, "snapshot_id": 470115, "phase": "initial"}))
PY
export HOSTINGER_SNAPSHOT_GATE_PY="$MOCK_PY_MISMATCH"
export HOSTINGER_SNAPSHOT_GATE_BASELINE_ID=470114
ERR=()
if hostinger_verify_snapshot_gate_if_required; then
    fail "initial gate must fail when operator baseline disagrees with verified id"
fi
pass "operator baseline mismatch fails closed"

rm -f "$MOCK_PY_NO_ID" "$MOCK_PY_MISMATCH"
pass "hostinger-snapshot-gate-baseline.test.sh complete"