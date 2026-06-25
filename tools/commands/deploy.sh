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
#   1. Build frontend assets
#   2. Upload public/build and sync public/ to remote
#   3. Pull latest code, install dependencies, migrate, optimize
#   4. Run health check

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

# --- Upload assets ---

print_warning "Uploading public/build to remote..."
rsync -avz \
    -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=15" \
    "${PROJECT_ROOT}/public/build/" \
    "${SSH_USER}@${SSH_HOST}:${REMOTE_PUBLIC}/build/"
print_success "Uploaded public/build"

print_warning "Synchronizing public/ to remote public_html..."
copy_public "$PROJECT_ROOT"
print_success "Synchronized public/ directory"

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

print_warning "Optimizing Laravel..."
php_exec optimize
print_success "Laravel optimize completed"

# --- Post-deploy verification ---

if health_check; then
    print_success "Deployment finished successfully"
    exit 0
fi

print_error "Deployment completed but health check failed"
exit 1
