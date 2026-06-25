#!/usr/bin/env bash
#
# Shared helpers for the desk deployment toolkit.
# Sourced by command scripts — do not execute directly.

set -euo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/config.sh
source "$LIB_DIR/config.sh"

# ANSI color codes for terminal output
readonly COLOR_RED='\033[0;31m'
readonly COLOR_GREEN='\033[0;32m'
readonly COLOR_YELLOW='\033[1;33m'
readonly COLOR_RESET='\033[0m'

# Run a command on the remote server over SSH (non-interactive).
ssh_exec() {
    ssh -p "$SSH_PORT" \
        -o BatchMode=yes \
        -o ConnectTimeout=15 \
        -o StrictHostKeyChecking=accept-new \
        "$SSH_USER@$SSH_HOST" "$@"
}

# Run a Laravel artisan command on the remote server.
php_exec() {
    ssh_exec "cd '$REMOTE_PROJECT' && $PHP_BIN artisan $*"
}

# Run Composer on the remote server using the configured PHP binary.
composer_exec() {
    ssh_exec "cd '$REMOTE_PROJECT' && $PHP_BIN $COMPOSER_BIN $*"
}

# Synchronize the entire local public/ directory to remote public_html.
# index.php is excluded; use generate_shared_hosting_index() instead.
copy_public() {
    local project_root="$1"

    rsync -avz --delete \
        -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=15" \
        --exclude '.gitkeep' \
        --exclude 'index.php' \
        "${project_root}/public/" \
        "${SSH_USER}@${SSH_HOST}:${REMOTE_PUBLIC}/"
}

# Generate public_html/index.php for shared-hosting layouts where the Laravel
# app root lives outside the web-accessible directory.
generate_shared_hosting_index() {
    local template="$LIB_DIR/templates/index.shared-hosting.php"
    local index_remote="${REMOTE_PUBLIC}/index.php"
    local generated
    local backup_suffix

    if [[ ! -f "$template" ]]; then
        print_error "Shared-hosting index template not found: ${template}"
        return 1
    fi

    print_warning "Backing up existing index.php (if present)..."
    backup_suffix="$(date +%Y%m%d-%H%M%S)"
    if ! ssh_exec "if [ -f '$index_remote' ]; then cp '$index_remote' '${index_remote}.bak-${backup_suffix}'; fi"; then
        print_error "Failed to back up existing index.php"
        return 1
    fi

    print_warning "Generating shared-hosting index.php from template..."
    generated="$(mktemp)"
    sed \
        -e "s|{{VENDOR_PATH}}|${INDEX_VENDOR_PATH}|g" \
        -e "s|{{BOOTSTRAP_PATH}}|${INDEX_BOOTSTRAP_PATH}|g" \
        "$template" > "$generated"

    if ! rsync -avz \
        -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=15" \
        "$generated" \
        "${SSH_USER}@${SSH_HOST}:${index_remote}"; then
        rm -f "$generated"
        print_error "Failed to upload generated index.php"
        return 1
    fi
    rm -f "$generated"

    print_warning "Validating Laravel bootstrap paths on remote..."
    if ! ssh_exec "test -f '$INDEX_VENDOR_PATH'"; then
        print_error "vendor/autoload.php not found at ${INDEX_VENDOR_PATH}"
        return 1
    fi

    if ! ssh_exec "test -f '$INDEX_BOOTSTRAP_PATH'"; then
        print_error "bootstrap/app.php not found at ${INDEX_BOOTSTRAP_PATH}"
        return 1
    fi

    print_success "Generated index.php with configured paths"
    return 0
}

# Verify the generated index.php references the configured bootstrap paths.
verify_shared_hosting_index() {
    local index_remote="${REMOTE_PUBLIC}/index.php"

    if ! ssh_exec "test -f '$index_remote'"; then
        print_error "Generated index.php not found at ${index_remote}"
        return 1
    fi

    if ! ssh_exec "grep -Fq '$INDEX_VENDOR_PATH' '$index_remote'"; then
        print_error "index.php does not reference INDEX_VENDOR_PATH (${INDEX_VENDOR_PATH})"
        return 1
    fi

    if ! ssh_exec "grep -Fq '$INDEX_BOOTSTRAP_PATH' '$index_remote'"; then
        print_error "index.php does not reference INDEX_BOOTSTRAP_PATH (${INDEX_BOOTSTRAP_PATH})"
        return 1
    fi

    return 0
}

print_success() {
    printf '%b✔ %s%b\n' "$COLOR_GREEN" "$1" "$COLOR_RESET"
}

print_error() {
    printf '%b✖ %s%b\n' "$COLOR_RED" "$1" "$COLOR_RESET" >&2
}

print_warning() {
    printf '%b⚠ %s%b\n' "$COLOR_YELLOW" "$1" "$COLOR_RESET"
}

# Verify the deployed application responds at APP_URL/ on the remote server.
health_check() {
    print_warning "Running health check..."

    local app_url http_status

    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        print_error "Could not determine APP_URL from remote .env"
        return 1
    fi

    http_status="$(ssh_exec "curl -s -o /dev/null -w '%{http_code}' --max-time 30 '${app_url}/'" || true)"

    if [[ "$http_status" != "200" && "$http_status" != "302" ]]; then
        print_error "Health check failed (${app_url}/, HTTP ${http_status:-unknown})"
        return 1
    fi

    if ! verify_shared_hosting_index; then
        print_error "Health check failed: HTTP ${http_status} but index.php validation failed"
        return 1
    fi

    print_success "Health check passed (${app_url}/, HTTP ${http_status})"
    return 0
}
