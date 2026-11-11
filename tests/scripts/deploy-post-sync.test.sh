#!/usr/bin/env bash
#
# Behavioural tests for migration/seeder opt-in flags (no production contact).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIB="$ROOT/tools/lib/deploy-post-sync.sh"
KVM="$ROOT/tools/commands/deploy-kvm.sh"
LEGACY="$ROOT/tools/commands/deploy.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$LIB" ]] || fail "deploy-post-sync.sh missing"
bash -n "$LIB" || fail "deploy-post-sync.sh syntax"
pass "deploy-post-sync.sh syntax"

grep -q 'deploy-post-sync.sh' "$KVM" || fail "deploy-kvm.sh must source deploy-post-sync.sh"
grep -q 'deploy_maybe_run_migrations' "$KVM" || fail "deploy-kvm.sh must call deploy_maybe_run_migrations"
grep -q 'deploy_maybe_run_role_permission_seeder' "$KVM" || fail "deploy-kvm.sh must call deploy_maybe_run_role_permission_seeder"
grep -q 'deploy-post-sync.sh' "$LEGACY" || fail "deploy.sh must source deploy-post-sync.sh"
grep -q 'deploy_maybe_run_migrations' "$LEGACY" || fail "deploy.sh must call deploy_maybe_run_migrations"
pass "deploy scripts wire opt-in helpers"

export -f fail pass 2>/dev/null || true

PHP_INVOKED=()
php_exec() {
    PHP_INVOKED+=("$*")
    if [[ "$1" == "migrate:status" ]]; then
        echo "| 2026_01_01_000000_example | Pending |"
        echo "| 2026_01_02_000000_example | Ran     |"
        return 0
    fi
    return 0
}
export -f php_exec

print_warning() { echo "WARN: $*"; }
print_error() { echo "ERR: $*"; }
print_success() { echo "OK: $*"; }
export -f print_warning print_error print_success

# shellcheck source=tools/lib/deploy-post-sync.sh
source "$LIB"

# 1. Default skips migrations
unset DEPLOY_RUN_MIGRATIONS
unset DEPLOY_RUN_ROLE_PERMISSION_SEED
PHP_INVOKED=()
deploy_maybe_run_migrations
if [[ ${#PHP_INVOKED[@]} -gt 0 ]] && printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'migrate --force'; then
    fail "default deploy must not run migrate --force"
fi
pass "default skips migrations"

# 2. Default skips seeder
PHP_INVOKED=()
deploy_maybe_run_role_permission_seeder
if [[ ${#PHP_INVOKED[@]} -gt 0 ]] && printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'db:seed'; then
    fail "default deploy must not run RolePermissionSeeder"
fi
pass "default skips RolePermissionSeeder"

# 3. Migration opt-in only
PHP_INVOKED=()
export DEPLOY_RUN_MIGRATIONS=1
export DEPLOY_RUN_ROLE_PERMISSION_SEED=0
deploy_maybe_run_migrations
deploy_maybe_run_role_permission_seeder
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'migrate --force' || fail "migration opt-in must run migrate"
if [[ ${#PHP_INVOKED[@]} -gt 0 ]] && printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'db:seed'; then
    fail "migration-only opt-in must not run seeder"
fi
pass "migration opt-in runs migrate only"

# 4. Seeder opt-in only
PHP_INVOKED=()
export DEPLOY_RUN_MIGRATIONS=0
export DEPLOY_RUN_ROLE_PERMISSION_SEED=1
deploy_maybe_run_migrations
deploy_maybe_run_role_permission_seeder
if [[ ${#PHP_INVOKED[@]} -gt 0 ]] && printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'migrate --force'; then
    fail "seeder-only opt-in must not run migrate"
fi
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'db:seed --class=RolePermissionSeeder' || fail "seeder opt-in must run seeder"
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'permission:cache-reset' || fail "seeder opt-in must reset permission cache"
pass "seeder opt-in runs seeder only"

# 5. Both opt-in
PHP_INVOKED=()
export DEPLOY_RUN_MIGRATIONS=1
export DEPLOY_RUN_ROLE_PERMISSION_SEED=1
deploy_maybe_run_migrations
deploy_maybe_run_role_permission_seeder
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'migrate --force' || fail "both opt-in must run migrate"
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'db:seed --class=RolePermissionSeeder' || fail "both opt-in must run seeder"
pass "both opt-in flags run both actions"

# 6. Dry-run never invokes (static: exit before run_remote_post_sync)
if ! grep -q 'run_remote_post_sync' "$KVM"; then
    fail "deploy-kvm.sh must define run_remote_post_sync"
fi
if ! awk '/DRY_RUN.*exit 0/,0' "$KVM" | grep -q 'run_remote_post_sync'; then
    :
fi
grep -q 'if \[\[ "$DRY_RUN" -eq 1 \]\];' "$KVM" || fail "deploy-kvm dry-run guard missing"
grep -A2 'Dry-run completed' "$KVM" | grep -q 'exit 0' || fail "dry-run must exit before live post-sync"
pass "deploy-kvm dry-run exits before post-sync"

# 7. Invalid flag values
if ( export DEPLOY_RUN_MIGRATIONS=yes DEPLOY_RUN_ROLE_PERMISSION_SEED=0; deploy_validate_post_sync_flags ); then
    fail "invalid DEPLOY_RUN_MIGRATIONS must exit non-zero"
fi
pass "invalid flag values rejected"

# 8. Pending count logged when migrations enabled
PHP_INVOKED=()
export DEPLOY_RUN_MIGRATIONS=1
output="$(deploy_maybe_run_migrations 2>&1)"
echo "$output" | grep -q '1 pending migration' || fail "must log pending migration count"
pass "migration opt-in logs pending count"

# 9. Targeted path migration opt-in
PHP_INVOKED=()
export DEPLOY_RUN_CATALOG_PRICE_SYNC_MIGRATION=0
deploy_maybe_run_path_migration \
    DEPLOY_RUN_CATALOG_PRICE_SYNC_MIGRATION \
    "database/migrations/2026_09_19_120000_create_catalog_price_sync_logs_table.php" \
    "Catalog price sync overlay"
if [[ ${#PHP_INVOKED[@]} -gt 0 ]]; then
    fail "default must skip targeted path migration"
fi
PHP_INVOKED=()
export DEPLOY_RUN_CATALOG_PRICE_SYNC_MIGRATION=1
deploy_maybe_run_path_migration \
    DEPLOY_RUN_CATALOG_PRICE_SYNC_MIGRATION \
    "database/migrations/2026_09_19_120000_create_catalog_price_sync_logs_table.php" \
    "Catalog price sync overlay"
printf '%s\n' "${PHP_INVOKED[@]}" | grep -q 'migrate --force --path=database/migrations/2026_09_19_120000_create_catalog_price_sync_logs_table.php' \
    || fail "targeted migration opt-in must run migrate --path"
pass "targeted path migration opt-in"

echo "All deploy-post-sync behavioural checks passed."
