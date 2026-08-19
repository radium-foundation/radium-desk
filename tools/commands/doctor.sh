#!/usr/bin/env bash
#
# Verify deployment prerequisites on the remote server.
#
# Checks SSH connectivity, PHP, Composer, Laravel paths,
# writable directories, Vite build manifest, APP_ENV, and database.
#
# When DEPLOY_MODE=kvm, runs KVM-specific health checks instead of
# legacy shared-hosting public_html validation.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DEPLOY_MODE="${DEPLOY_MODE:-legacy}"

failures=0

check() {
    local description="$1"
    shift

    if "$@"; then
        print_success "$description"
    else
        print_error "$description"
        failures=$((failures + 1))
    fi
}

print_warning "Running desk doctor against ${SSH_USER}@${SSH_HOST} (${DEPLOY_MODE} mode)..."
echo

# --- SSH connectivity ---

check "SSH connectivity" ssh_exec "echo ok" >/dev/null

# --- PHP version ---

check "PHP is available" ssh_exec "test -x '$PHP_BIN' && '$PHP_BIN' -v" >/dev/null

php_version="$(ssh_exec "$PHP_BIN -r 'echo PHP_VERSION;'")"
print_success "PHP version: ${php_version}"

# --- Composer ---

check "Composer is available" ssh_exec "test -x '$COMPOSER_BIN' && '$PHP_BIN' '$COMPOSER_BIN' --version" >/dev/null

# --- Laravel project directory ---

check "Laravel project directory exists" ssh_exec "test -d '$REMOTE_PROJECT' && test -f '$REMOTE_PROJECT/artisan'"

# --- Writable directories ---

check "storage/ is writable" \
    ssh_exec "test -w '$REMOTE_PROJECT/storage' && test -w '$REMOTE_PROJECT/storage/logs'"

check "bootstrap/cache/ is writable" \
    ssh_exec "test -w '$REMOTE_PROJECT/bootstrap/cache'"

# --- Vite build manifest (local) ---

check "Local Vite build manifest exists" test -f "$PROJECT_ROOT/public/build/manifest.json"

# --- Mode-specific remote checks ---

if [[ "$DEPLOY_MODE" == "kvm" ]]; then
    check "KVM Laravel public directory (${REMOTE_PROJECT}/public)" kvm_doctor_check_laravel_public
    check "Remote KVM Vite manifest exists" kvm_doctor_check_remote_manifest
    check "APP_URL /up health endpoint (HTTP 200)" kvm_doctor_check_up_endpoint
    check "Supervisor program is RUNNING (${SUPERVISOR_PROGRAM})" kvm_doctor_check_supervisor_program
    check "Redis connectivity" kvm_doctor_check_redis
else
    parity_status=0
    if legacy_doctor_check_vite_manifest_parity; then
        print_success "Remote Vite manifests match (Laravel and public_html)"
    else
        parity_status=$?
        if [[ "$parity_status" -eq 2 ]]; then
            print_warning "Remote Vite manifests not found on server (run desk deploy)"
        else
            print_error "Remote Vite manifests differ between Laravel and public_html"
            failures=$((failures + 1))
        fi
    fi

    if legacy_doctor_check_shared_hosting_index; then
        print_success "Shared-hosting index.php references configured bootstrap paths"
    else
        print_error "Shared-hosting index.php validation failed"
        failures=$((failures + 1))
    fi
fi

# --- APP_ENV ---

remote_app_env="$(ssh_exec "grep '^APP_ENV=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
if [[ -n "$remote_app_env" ]]; then
    print_success "APP_ENV=${remote_app_env}"
else
    print_error "APP_ENV is not set in remote .env"
    failures=$((failures + 1))
fi

# --- Database connection ---

if php_exec db:show >/dev/null 2>&1; then
    print_success "Database connection"
else
    print_error "Database connection"
    failures=$((failures + 1))
fi

echo
if [[ "$failures" -eq 0 ]]; then
    print_success "All checks passed (${failures} failures)"
    exit 0
fi

print_error "${failures} check(s) failed"
exit 1
