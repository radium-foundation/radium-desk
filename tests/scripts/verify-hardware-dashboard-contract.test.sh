#!/usr/bin/env bash
#
# Static checks for tools/commands/verify-hardware-dashboard-contract.sh.
#
# Run: bash tests/scripts/verify-hardware-dashboard-contract.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/tools/commands/verify-hardware-dashboard-contract.sh"
MANIFEST="$ROOT/tools/contracts/hardware-dashboard-p302.manifest"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$SCRIPT" ]] || fail "verify-hardware-dashboard-contract.sh missing"
[[ -f "$MANIFEST" ]] || fail "hardware-dashboard-p302.manifest missing"
bash -n "$SCRIPT" || fail "verify-hardware-dashboard-contract.sh syntax check failed"
pass "verify-hardware-dashboard-contract.sh syntax valid"

grep -q 'hardware-dashboard-p302.manifest' "$SCRIPT" \
    || fail "verify script must read the P-302 manifest"
grep -q 'NeedsAction' "$SCRIPT" \
    || fail "verify script must check Needs Action marker"
grep -q 'ReadyForPickup' "$SCRIPT" \
    || fail "verify script must check Ready for Pickup marker"
grep -q 'OutForPickup' "$SCRIPT" \
    || fail "verify script must check Out for Pickup marker"
grep -q 'dashboard.live.hardware' "$SCRIPT" \
    || fail "verify script must check live hardware route marker"

bash "$SCRIPT" || fail "contract verification must pass on current tree"
pass "contract verification passes on current tree"

echo "All verify-hardware-dashboard-contract static checks passed."
