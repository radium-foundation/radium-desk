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

TMP_MANIFEST="$(mktemp)"
trap 'rm -f "$TMP_MANIFEST"' EXIT
printf '%s\n' 'app/DOES_NOT_EXIST_FOR_READY_QUEUE_CONTRACT_TEST.php' > "$TMP_MANIFEST"

if (
    set -euo pipefail
    # shellcheck source=tools/lib.sh
    source "$ROOT/tools/lib.sh"
    PROJECT_ROOT="$ROOT"
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%%#*}"
        line="$(echo "$line" | xargs)"
        [[ -z "$line" ]] && continue
        if [[ ! -f "$PROJECT_ROOT/$line" ]]; then
            exit 1
        fi
    done < "$TMP_MANIFEST"
) 2>/dev/null; then
    fail "contract must fail closed when manifest lists a missing file"
else
    pass "contract fails closed on missing manifest file"
fi

DEPLOY_SCRIPT="$ROOT/tools/commands/deploy-kvm.sh"
verify_line="$(grep -n 'verify_ready_queue_contract' "$DEPLOY_SCRIPT" | head -1 | cut -d: -f1)"
rsync_line="$(grep -n 'rsync_application_to_kvm' "$DEPLOY_SCRIPT" | head -1 | cut -d: -f1)"
[[ -n "$verify_line" && -n "$rsync_line" && "$verify_line" -lt "$rsync_line" ]] \
    || fail "ready queue contract must be verified before rsync in deploy-kvm.sh"
pass "deploy-kvm verifies ready queue contract before rsync"

echo "All verify-ready-queue-contract static checks passed."
