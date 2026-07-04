#!/usr/bin/env bash
#
# Deploy the Laravel application to production.
#
# Prerequisites:
#   - Clean git working tree on DEFAULT_BRANCH
#   - npm dependencies installed locally
#   - SSH access to the remote server
#
# Steps:
#   1. Build frontend assets locally
#   2. Pull latest code, install dependencies, migrate
#   3. Sync Vite build to public_html and Laravel public/build, then other public assets
#   4. Clear and rebuild Laravel caches (after manifest is in place)
#   5. Generate shared-hosting index.php and validate bootstrap paths
#   6. Verify Vite manifest assets on disk, then run health check

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$PROJECT_ROOT"

print_warning "Starting deployment to ${SSH_USER}@${SSH_HOST}..."

# --- Local preflight checks ---

if [[ -n "$(git status --porcelain)" ]]; then
    print_error "Git working tree is not clean. Commit or stash changes before deploying."
    exit 1
fi

current_branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$current_branch" != "$DEFAULT_BRANCH" ]]; then
    print_error "Must be on branch '${DEFAULT_BRANCH}' (currently on '${current_branch}')."
    exit 1
fi

print_success "Git working tree is clean on ${DEFAULT_BRANCH}"

# --- Build frontend assets ---

print_warning "Building frontend assets (npm run build)..."
npm run build
print_success "Frontend build completed"

# --- Remote deployment ---

print_warning "Pulling latest code on remote..."
ssh_exec "cd '$REMOTE_PROJECT' && git pull origin '$DEFAULT_BRANCH'"
print_success "Remote git pull completed"

print_warning "Installing Composer dependencies (production)..."
composer_exec install --no-dev --optimize-autoloader
print_success "Composer install completed"

print_warning "Running database migrations..."
php_exec migrate --force
print_success "Migrations completed"

# --- Upload assets (after code is current, before cache rebuild) ---

print_warning "Syncing Vite build to public_html and Laravel public/build..."
sync_vite_build "$PROJECT_ROOT"
print_success "Vite build synced to both remote paths"

print_warning "Synchronizing public/ to remote public_html..."
copy_public "$PROJECT_ROOT"
print_success "Synchronized public/ directory"

print_warning "Clearing and rebuilding Laravel caches..."
php_exec optimize:clear
php_exec optimize
print_success "Laravel caches rebuilt"

print_warning "Restarting background workers (queue + reverb) when Supervisor is configured..."
ssh_exec "supervisorctl restart radium-reverb radium-queue 2>/dev/null || true"
print_success "Background worker restart attempted"

# --- Shared-hosting index.php ---

if ! generate_shared_hosting_index; then
    print_error "Deployment aborted: index.php generation or validation failed"
    exit 1
fi

# --- Post-deploy verification ---

if ! verify_vite_assets; then
    print_error "Deployment completed but Vite asset verification failed"
    exit 1
fi

if health_check; then
    print_success "Deployment finished successfully"
    exit 0
fi

print_error "Deployment completed but health check failed"
exit 1
