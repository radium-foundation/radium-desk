#!/usr/bin/env bash
#
# Roll back the remote repository one commit and refresh dependencies.
#
# Usage:
#   desk rollback        Reset remote to previous commit (HEAD~1)
#   desk rollback 3      Reset remote back 3 commits
#
# WARNING: This performs a hard reset on the remote server.
# Database migrations are NOT automatically reversed.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

steps="${1:-1}"

if ! [[ "$steps" =~ ^[0-9]+$ ]] || [[ "$steps" -lt 1 ]]; then
    print_error "Usage: desk rollback [number-of-commits]"
    exit 1
fi

print_warning "Remote current commit:"
ssh_exec "cd '$REMOTE_PROJECT' && git log -1 --oneline"

target="HEAD~${steps}"
print_warning "Rolling back ${steps} commit(s) to ${target}..."

ssh_exec "cd '$REMOTE_PROJECT' && git reset --hard '$target'"
print_success "Remote repository rolled back"

print_warning "Reinstalling Composer dependencies..."
composer_exec install --no-dev --optimize-autoloader
print_success "Composer install completed"

print_warning "Running pending migrations (if any)..."
php_exec migrate --force
print_success "Migrations completed"

print_warning "Rebuilding caches..."
php_exec optimize:clear
php_exec optimize
print_success "Caches rebuilt"

print_warning "Remote commit after rollback:"
ssh_exec "cd '$REMOTE_PROJECT' && git log -1 --oneline"

if health_check; then
    print_success "Rollback completed successfully"
    exit 0
fi

print_error "Rollback completed but health check failed"
exit 1
