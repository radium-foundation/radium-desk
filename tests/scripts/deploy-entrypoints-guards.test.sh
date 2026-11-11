#!/usr/bin/env bash
#
# Static wiring checks for migration/seeder safeguards on all deploy entry points.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

KVM="$ROOT/tools/commands/deploy-kvm.sh"
LEGACY="$ROOT/tools/commands/deploy.sh"
ROLLBACK="$ROOT/tools/commands/rollback.sh"
CATALOG="$ROOT/tools/commands/deploy-catalog-price-sync-kvm.sh"
PHASE_B1="$ROOT/tools/phase-b1-storefront-controls-kvm8.sh"
LIB="$ROOT/tools/lib/deploy-post-sync.sh"

for f in "$KVM" "$LEGACY" "$ROLLBACK" "$CATALOG" "$PHASE_B1" "$LIB"; do
    [[ -f "$f" ]] || fail "missing $f"
    bash -n "$f" || fail "syntax: $f"
done
pass "deploy entrypoint scripts syntax valid"

grep -q 'deploy-post-sync.sh' "$ROLLBACK" || fail "rollback.sh must source deploy-post-sync.sh"
grep -q 'deploy_maybe_run_migrations' "$ROLLBACK" || fail "rollback must use deploy_maybe_run_migrations"
if grep -E 'php_exec migrate --force' "$ROLLBACK" | grep -qv deploy-post-sync; then
    if grep -q 'php_exec migrate --force' "$ROLLBACK"; then
        fail "rollback.sh must not call php_exec migrate --force directly"
    fi
fi
pass "rollback.sh migration opt-in wired"

grep -q 'deploy_maybe_run_path_migration' "$CATALOG" || fail "catalog script must use deploy_maybe_run_path_migration"
if grep -q 'php_exec migrate --force' "$CATALOG"; then
    fail "catalog script must not call migrate directly"
fi
grep -q 'DRY_RUN' "$CATALOG" || fail "catalog script must support DRY_RUN"
pass "deploy-catalog-price-sync-kvm.sh guarded"

grep -q 'DEPLOY_RUN_PHASE_B1_STOREFRONT_MIGRATION' "$PHASE_B1" || fail "phase-b1 must document migration opt-in flag"
grep -q 'DRY_RUN' "$PHASE_B1" || fail "phase-b1 must support DRY_RUN"
pass "phase-b1-storefront-controls kvm8 guarded"

CW_PROD="$ROOT/tools/commands/deploy-central-wallet-prod-gate.sh"
CW_UAT="$ROOT/tools/commands/deploy-central-wallet-uat-gate.sh"
for f in "$CW_PROD" "$CW_UAT"; do
    if grep -qE 'migrate|db:seed' "$f"; then
        fail "$f must not invoke migrate or db:seed"
    fi
done
pass "central-wallet gate overlays omit migrations"

echo "All deploy-entrypoints-guards static checks passed."
