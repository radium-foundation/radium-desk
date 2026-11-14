#!/usr/bin/env bash
# P-14-23 — deploy-kvm Hostinger gate ordering regression (static).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY="$ROOT/tools/commands/deploy-kvm.sh"
GATE_PY="$ROOT/tools/lib/hostinger_vps_snapshot_gate.py"
GATE_CREATE="$ROOT/tools/lib/hostinger_vps_snapshot_create.py"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

line_initial="$(grep -n 'if ! hostinger_verify_snapshot_gate_if_required' "$DEPLOY" | cut -d: -f1)"
line_final="$(grep -n 'if ! hostinger_reverify_snapshot_gate_before_rsync' "$DEPLOY" | cut -d: -f1)"
line_chown="$(grep -n '^[[:space:]]*ensure_remote_deploy_ownership$' "$DEPLOY" | cut -d: -f1)"
line_rsync="$(grep -n '^[[:space:]]*rsync_application_to_kvm$' "$DEPLOY" | cut -d: -f1)"

[[ -n "$line_initial" && -n "$line_final" && -n "$line_chown" && -n "$line_rsync" ]] \
  || fail "could not locate gate/chown/rsync lines"

[[ "$line_final" -lt "$line_chown" ]] || fail "final gate must precede ensure_remote_deploy_ownership (final=$line_final chown=$line_chown)"
[[ "$line_chown" -lt "$line_rsync" ]] || fail "chown must precede rsync"
[[ "$line_final" -lt "$line_rsync" ]] || fail "final gate must precede rsync"
pass "final gate before remote chown and rsync"

grep -q 'HOSTINGER_SNAPSHOT_GATE_LOCK_HELD' "$DEPLOY" \
  || fail "deploy must register EXIT trap when deploy lock held"
pass "deploy lock EXIT trap present"

for f in "$GATE_PY"; do
  grep -qE 'method=.POST|method=.PUT|method=.PATCH|method=.DELETE' "$f" && fail "gate must not use mutating HTTP"
done
[[ -f "$GATE_CREATE" ]] && grep -q POST "$GATE_CREATE" && fail "create must not be in PR #259 tree" || true
pass "no mutating HTTP in gate module"

# Snapshot identity change covered in python suite
pass "deploy-kvm-hostinger-gate-order.test.sh complete"