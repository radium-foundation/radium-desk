#!/usr/bin/env bash
#
# Deploy the Laravel application to the Hostinger KVM via local rsync.
#
# KVM-only: no remote git pull/reset, no shared-hosting public_html logic.
#
# Usage:
#   ./tools/commands/deploy-kvm.sh [--dry-run] [--yes]
#
# Options:
#   --dry-run   Run rsync --dry-run only; no remote mutations.
#   --yes       Skip interactive confirmation (non-interactive use only).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ALLOWED_UNTRACKED="docs/redis-vps-preinstall-inspection.md"

DRY_RUN=0
SKIP_CONFIRM=0

usage() {
    cat <<EOF
Usage: $(basename "$0") [--dry-run] [--yes]

Deploy the local Git working tree to the Hostinger KVM (${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}).

  --dry-run   Preview rsync only; no remote mutations.
  --yes       Skip interactive confirmation prompt.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=1
            ;;
        --yes)
            SKIP_CONFIRM=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            print_error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
    shift
done

if [[ "${DEPLOY_MODE:-}" != "kvm" ]]; then
    print_error "DEPLOY_MODE must be 'kvm' (got: ${DEPLOY_MODE:-unset})"
    exit 1
fi

rsync_ssh() {
    printf 'ssh -p %s -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new' "$SSH_PORT"
}

ensure_clean_working_tree() {
    local line status path

    if [[ -z "$(git -C "$PROJECT_ROOT" status --porcelain)" ]]; then
        print_success "Git working tree is clean"
        return 0
    fi

    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        status="${line:0:2}"
        path="${line:3}"

        if [[ "$status" == "??" && "$path" == "$ALLOWED_UNTRACKED" ]]; then
            continue
        fi

        print_error "Working tree is not clean: ${line}"
        print_error "Only allowed untracked file: ${ALLOWED_UNTRACKED}"
        exit 1
    done < <(git -C "$PROJECT_ROOT" status --porcelain)

    print_success "Git working tree is clean (except allowed untracked: ${ALLOWED_UNTRACKED})"
}

ensure_release_branch() {
    local current_branch

    current_branch="$(git -C "$PROJECT_ROOT" rev-parse --abbrev-ref HEAD)"
    if [[ "$current_branch" != "$DEFAULT_BRANCH" ]]; then
        print_error "Must be on branch '${DEFAULT_BRANCH}' (currently on '${current_branch}')."
        exit 1
    fi

    print_success "On release branch: ${DEFAULT_BRANCH}"
}

validate_release_metadata() {
    local latest_tag version changelog_pattern

    latest_tag="$(git -C "$PROJECT_ROOT" tag -l 'v*' --sort=-v:refname | head -n 1)"
    if [[ -z "$latest_tag" ]]; then
        print_error "No semver Git tag found (expected v*.*.*)."
        exit 1
    fi

    version="${latest_tag#v}"
    if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "Latest tag is not semver: ${latest_tag}"
        exit 1
    fi

    if ! git -C "$PROJECT_ROOT" describe --exact-match --tags HEAD >/dev/null 2>&1; then
        print_error "HEAD is not exactly tagged. Create and checkout tag ${latest_tag} before deploying."
        exit 1
    fi

    if [[ "$(git -C "$PROJECT_ROOT" describe --tags --exact-match HEAD 2>/dev/null)" != "$latest_tag" ]]; then
        print_error "HEAD tag does not match latest release tag (${latest_tag})."
        exit 1
    fi

    changelog_pattern="^##[[:space:]]${version}[[:space:]]"
    if ! grep -Eq "$changelog_pattern" "$PROJECT_ROOT/CHANGELOG.md"; then
        print_error "CHANGELOG.md is missing an entry for version ${version} (expected '## ${version} ...')."
        exit 1
    fi

    print_success "Release metadata validated (tag=${latest_tag}, CHANGELOG entry present)"
}

ensure_local_build_manifest() {
    if [[ -f "$PROJECT_ROOT/public/build/manifest.json" ]]; then
        print_success "Local Vite manifest present"
        return 0
    fi

    print_error "Local public/build/manifest.json is missing. Run 'npm run build' first."
    exit 1
}

build_frontend_assets() {
    print_warning "Building frontend assets (npm run build)..."
    (cd "$PROJECT_ROOT" && npm run build)
    print_success "Frontend build completed"
}

write_local_release_snapshot() {
    print_warning "Writing local release snapshot (release:snapshot)..."
    if ! command -v php >/dev/null 2>&1; then
        print_error "Local PHP is required to run release:snapshot before deploy."
        exit 1
    fi

    (cd "$PROJECT_ROOT" && php artisan release:snapshot)
    print_success "Local release snapshot written"
}

confirm_deploy() {
    local answer

    if [[ "$SKIP_CONFIRM" -eq 1 ]]; then
        return 0
    fi

    print_warning "About to deploy to KVM: ${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}"
    print_warning "Type 'deploy' to continue:"
    read -r answer
    if [[ "$answer" != "deploy" ]]; then
        print_error "Deployment cancelled."
        exit 1
    fi
}

rsync_application_to_kvm() {
    local -a rsync_args=(rsync -avz)
    local remote="${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}/"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        rsync_args+=(--dry-run)
    fi

    rsync_args+=(
        -e "$(rsync_ssh)"
        --delete
        --exclude '.git/'
        --exclude '.env'
        --exclude 'node_modules/'
        --exclude 'vendor/'
        --exclude 'storage/logs/'
        --exclude 'storage/framework/'
        --exclude 'bootstrap/cache/'
        --exclude 'tests/'
        --include 'storage/'
        --include 'storage/app/'
        --include 'storage/app/private/'
        --include 'storage/app/private/release.json'
        --exclude 'storage/app/private/*'
        --exclude 'storage/app/*'
        --exclude 'storage/*'
        --exclude 'public/build/'
        "${PROJECT_ROOT}/"
        "$remote"
    )

    print_warning "Synchronizing application source to KVM..."
    "${rsync_args[@]}"
    print_success "Application source rsync completed"
}

fix_remote_ownership() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    return 0
  fi

  print_warning "Applying ownership ${SSH_USER}:${SSH_USER} to deployed application tree..."
  ssh_exec "find '$REMOTE_PROJECT' -path '$REMOTE_PROJECT/storage/logs' -prune -o -exec chown ${SSH_USER}:${SSH_USER} {} +"
  ssh_exec "find '$REMOTE_PROJECT/bin' -maxdepth 1 -type f -name '*.sh' -exec chmod 755 {} +"
  print_success "Ownership and bin permissions applied"
}

run_remote_post_sync() {
    print_warning "Verifying artisan exists on KVM..."
    ssh_exec "test -x '$REMOTE_PROJECT/artisan'"
    print_success "artisan present"

    print_warning "Installing Composer dependencies (production)..."
    composer_exec install --no-dev --optimize-autoloader
    print_success "Composer install completed"

    print_warning "Running database migrations..."
    php_exec migrate --force
    print_success "Migrations completed"

    print_warning "Seeding role permissions..."
    php_exec db:seed --class=RolePermissionSeeder --force
    php_exec permission:cache-reset
    print_success "Role permissions seeded"

    print_warning "Clearing and rebuilding Laravel caches..."
    php_exec optimize:clear
    php_exec optimize
    print_success "Laravel caches rebuilt"

    kvm_restart_supervisor_worker
}

main() {
    cd "$PROJECT_ROOT"

    print_warning "KVM deploy preflight (${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT})..."

    ensure_clean_working_tree
    ensure_release_branch
    validate_release_metadata

    if [[ "$DRY_RUN" -eq 1 ]]; then
        ensure_local_build_manifest
        print_warning "Dry-run mode: rsync preview only (no remote mutations)."
        rsync_application_to_kvm
        print_warning "Dry-run: public/build preview..."
        rsync -avz --dry-run \
            -e "$(rsync_ssh)" \
            --delete \
            "${PROJECT_ROOT}/public/build/" \
            "${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}/public/build/"
        print_success "Dry-run completed (no changes made on KVM)"
        exit 0
    fi

    confirm_deploy
    build_frontend_assets
    write_local_release_snapshot
    rsync_application_to_kvm
    sync_kvm_public_build "$PROJECT_ROOT"
    fix_remote_ownership
    run_remote_post_sync

    if ! kvm_health_check; then
        print_error "Deployment completed but KVM health check failed"
        exit 1
    fi

    if ! kvm_verify_vite_assets; then
        print_error "Deployment completed but Vite verification failed"
        exit 1
    fi

    print_success "KVM deployment finished successfully"
}

main "$@"
