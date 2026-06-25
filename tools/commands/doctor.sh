#!/usr/bin/env bash
#
# Verify deployment prerequisites on the remote server.
#
# Checks SSH connectivity, PHP, Composer, Laravel paths,
# writable directories, Vite build manifest, APP_ENV, and database.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

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

print_warning "Running desk doctor against ${SSH_USER}@${SSH_HOST}..."
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
