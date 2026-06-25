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
copy_public() {
    local project_root="$1"

    rsync -avz --delete \
        -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=15" \
        --exclude '.gitkeep' \
        "${project_root}/public/" \
        "${SSH_USER}@${SSH_HOST}:${REMOTE_PUBLIC}/"
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

# Verify the deployed application responds at APP_URL/up on the remote server.
health_check() {
    print_warning "Running health check..."

    local app_url
    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        print_error "Could not determine APP_URL from remote .env"
        return 1
    fi

    if ssh_exec "curl -sf --max-time 30 '${app_url}/up' > /dev/null"; then
        print_success "Health check passed (${app_url}/up)"
        return 0
    fi

    print_error "Health check failed (${app_url}/up)"
    return 1
}
