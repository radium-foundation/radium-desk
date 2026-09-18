#!/usr/bin/env bash
#
# Static checks for tools/commands/verify-ready-queue-contract.sh.
#
# Run: bash tests/scripts/verify-ready-queue-contract.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/tools/commands/verify-ready-queue-contract.sh"
MANIFEST="$ROOT/tools/contracts/ready-queue-service.manifest"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$SCRIPT" ]] || fail "verify-ready-queue-contract.sh missing"
[[ -f "$MANIFEST" ]] || fail "ready-queue-service.manifest missing"

bash -n "$SCRIPT" || fail "verify-ready-queue-contract.sh syntax check failed"
pass "verify-ready-queue-contract.sh syntax valid"

grep -q 'ready-queue-service.manifest' "$SCRIPT" || fail "must read ready-queue-service.manifest"
grep -q 'canViewReadyQueue' "$SCRIPT" || fail "must verify canViewReadyQueue marker"
grep -q 'PERMISSION_READY_QUEUE_VIEW' "$SCRIPT" || fail "must verify permission marker"
grep -q 'Ready Queue' "$SCRIPT" || fail "must verify Ready Queue label marker"

"$SCRIPT" || fail "contract verification failed against current tree"
pass "contract verification passes on current tree"

echo "All verify-ready-queue-contract static checks passed."
