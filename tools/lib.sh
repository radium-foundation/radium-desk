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

# --- Legacy shared-hosting helpers (desk deploy / doctor / rollback) ---

# Synchronize the Vite build output to both web-facing and Laravel-readable paths.
# On shared hosting, public_html (LEGACY_REMOTE_PUBLIC) serves static assets while Laravel
# reads manifest.json from REMOTE_PROJECT/public/build at runtime.
sync_vite_build() {
    local project_root="$1"
    local rsync_ssh=(ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15)

    rsync -avz --delete \
        -e "${rsync_ssh[*]}" \
        "${project_root}/public/build/" \
        "${SSH_USER}@${SSH_HOST}:${LEGACY_REMOTE_PUBLIC}/build/"

    rsync -avz --delete \
        -e "${rsync_ssh[*]}" \
        "${project_root}/public/build/" \
        "${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}/public/build/"
}

# Synchronize static public/ assets to remote public_html.
# build/ and index.php are excluded; use sync_vite_build() and generate_shared_hosting_index().
copy_public() {
    local project_root="$1"

    rsync -avz --delete \
        -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=15" \
        --exclude '.gitkeep' \
        --exclude 'index.php' \
        --exclude 'build/' \
        "${project_root}/public/" \
        "${SSH_USER}@${SSH_HOST}:${LEGACY_REMOTE_PUBLIC}/"
}

# Verify deployed Vite build: manifest exists, all manifest assets are on disk,
# and /login responds with at least one current manifest asset referenced.
verify_vite_assets() {
    local app_url manifest_remote manifest_assets asset missing_count http_status html_assets overlap

    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        print_error "Could not determine APP_URL from remote .env"
        return 1
    fi

    manifest_remote="${REMOTE_PROJECT}/public/build/manifest.json"
    if ! ssh_exec "test -f '$manifest_remote'"; then
        print_error "Laravel manifest not found at ${manifest_remote}"
        return 1
    fi

    manifest_assets="$(ssh_exec "grep -oE '\"file\": \"assets/[^\"]+\"' '$manifest_remote'" \
        | sed -E 's/"file": "//;s/"$//' \
        | sort -u \
        || true)"

    if [[ -z "$manifest_assets" ]]; then
        print_error "Could not extract asset paths from ${manifest_remote}"
        return 1
    fi

    missing_count=0
    while IFS= read -r asset; do
        [[ -z "$asset" ]] && continue
        if ! ssh_exec "test -f '${LEGACY_REMOTE_PUBLIC}/build/${asset}'"; then
            print_error "Missing Vite asset on public_html: build/${asset}"
            missing_count=$((missing_count + 1))
        fi
    done <<< "$manifest_assets"

    if [[ "$missing_count" -gt 0 ]]; then
        print_error "Vite asset verification failed (${missing_count} missing file(s) in ${LEGACY_REMOTE_PUBLIC}/build/)"
        return 1
    fi

    print_success "Vite manifest assets verified on ${LEGACY_REMOTE_PUBLIC}/build/"

    http_status="$(ssh_exec "curl -s -o /dev/null -w '%{http_code}' --max-time 30 '${app_url}/login'" || true)"
    if [[ "$http_status" != "200" && "$http_status" != "302" ]]; then
        print_error "Login page check failed (${app_url}/login, HTTP ${http_status:-unknown})"
        return 1
    fi

    html_assets="$(ssh_exec "curl -s --max-time 30 '${app_url}/login' | grep -oE 'build/assets/[^\"]+' | sed 's|^build/||' | sort -u" || true)"
    overlap=0
    while IFS= read -r asset; do
        [[ -z "$asset" ]] && continue
        if echo "$html_assets" | grep -Fxq "$asset"; then
            overlap=1
            break
        fi
    done <<< "$manifest_assets"

    if [[ "$overlap" -ne 1 ]]; then
        print_error "Login page does not reference any current Vite manifest assets (${app_url}/login)"
        return 1
    fi

    print_success "Login page smoke check passed (${app_url}/login, HTTP ${http_status})"
    return 0
}

# Generate public_html/index.php for shared-hosting layouts where the Laravel
# app root lives outside the web-accessible directory.
generate_shared_hosting_index() {
    local template="$LIB_DIR/templates/index.shared-hosting.php"
    local index_remote="${LEGACY_REMOTE_PUBLIC}/index.php"
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
    local index_remote="${LEGACY_REMOTE_PUBLIC}/index.php"

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

    print_warning "Validating Cashfree configuration..."
    if ! php_exec cashfree:validate-config; then
        print_error "Health check failed: Cashfree configuration is invalid"
        return 1
    fi

    print_success "Health check passed (${app_url}/, HTTP ${http_status})"
    return 0
}

# --- KVM deployment helpers (Phase 2.2; not wired into desk deploy yet) ---

# Synchronize Vite build output to the KVM Laravel public/ directory.
sync_kvm_public_build() {
    local project_root="$1"
    local kvm_public="${REMOTE_PROJECT}/public"
    local rsync_ssh=(ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15)

    rsync -avz --delete \
        -e "${rsync_ssh[*]}" \
        "${project_root}/public/build/" \
        "${SSH_USER}@${SSH_HOST}:${kvm_public}/build/"
}

# Verify KVM Vite build: manifest exists under public/build/, assets on disk, /login smoke.
kvm_verify_vite_assets() {
    local app_url manifest_remote manifest_assets asset missing_count http_status html_assets overlap
    local kvm_public="${REMOTE_PROJECT}/public"

    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        print_error "Could not determine APP_URL from remote .env"
        return 1
    fi

    manifest_remote="${kvm_public}/build/manifest.json"
    if ! ssh_exec "test -f '$manifest_remote'"; then
        print_error "KVM manifest not found at ${manifest_remote}"
        return 1
    fi

    manifest_assets="$(ssh_exec "grep -oE '\"file\": \"assets/[^\"]+\"' '$manifest_remote'" \
        | sed -E 's/"file": "//;s/"$//' \
        | sort -u \
        || true)"

    if [[ -z "$manifest_assets" ]]; then
        print_error "Could not extract asset paths from ${manifest_remote}"
        return 1
    fi

    missing_count=0
    while IFS= read -r asset; do
        [[ -z "$asset" ]] && continue
        if ! ssh_exec "test -f '${kvm_public}/build/${asset}'"; then
            print_error "Missing KVM Vite asset: build/${asset}"
            missing_count=$((missing_count + 1))
        fi
    done <<< "$manifest_assets"

    if [[ "$missing_count" -gt 0 ]]; then
        print_error "KVM Vite asset verification failed (${missing_count} missing file(s) in ${kvm_public}/build/)"
        return 1
    fi

    print_success "KVM Vite manifest assets verified on ${kvm_public}/build/"

    http_status="$(ssh_exec "curl -s -o /dev/null -w '%{http_code}' --max-time 30 '${app_url}/login'" || true)"
    if [[ "$http_status" != "200" && "$http_status" != "302" ]]; then
        print_error "Login page check failed (${app_url}/login, HTTP ${http_status:-unknown})"
        return 1
    fi

    html_assets="$(ssh_exec "curl -s --max-time 30 '${app_url}/login' | grep -oE 'build/assets/[^\"]+' | sed 's|^build/||' | sort -u" || true)"
    overlap=0
    while IFS= read -r asset; do
        [[ -z "$asset" ]] && continue
        if echo "$html_assets" | grep -Fxq "$asset"; then
            overlap=1
            break
        fi
    done <<< "$manifest_assets"

    if [[ "$overlap" -ne 1 ]]; then
        print_error "Login page does not reference any current Vite manifest assets (${app_url}/login)"
        return 1
    fi

    print_success "Login page smoke check passed (${app_url}/login, HTTP ${http_status})"
    return 0
}

# KVM health check: SSH, PHP binary, and Laravel /up endpoint (no shared-hosting index.php).
kvm_health_check() {
    local app_url http_status

    print_warning "Running KVM health check..."

    if ! ssh_exec "echo ok" >/dev/null 2>&1; then
        print_error "SSH connectivity failed"
        return 1
    fi
    print_success "SSH connectivity"

    if ! ssh_exec "test -x '$PHP_BIN' && '$PHP_BIN' -v" >/dev/null 2>&1; then
        print_error "PHP binary not available: ${PHP_BIN}"
        return 1
    fi
    print_success "PHP binary available (${PHP_BIN})"

    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        print_error "Could not determine APP_URL from remote .env"
        return 1
    fi

    http_status="$(ssh_exec "curl -s -o /dev/null -w '%{http_code}' --max-time 30 '${app_url}/up'" || true)"

    if [[ "$http_status" != "200" ]]; then
        print_error "KVM health check failed (${app_url}/up, HTTP ${http_status:-unknown})"
        return 1
    fi

    print_success "KVM health check passed (${app_url}/up, HTTP ${http_status})"
    return 0
}

# Restart the configured Supervisor queue worker on the KVM.
kvm_restart_supervisor_worker() {
    local program="${SUPERVISOR_PROGRAM:-radium-desk-queue-worker}"

    print_warning "Restarting Supervisor program: ${program}..."

    if ssh_exec "supervisorctl restart '${program}'" >/dev/null 2>&1 \
        || ssh_exec "sudo -n supervisorctl restart '${program}'" >/dev/null 2>&1; then
        print_success "Supervisor program restarted: ${program}"
        return 0
    fi

    print_error "Failed to restart Supervisor program: ${program}"
    return 1
}

# --- KVM doctor helpers (Phase 2.4) ---

kvm_remote_app_url() {
    local app_url

    app_url="$(ssh_exec "grep '^APP_URL=' '$REMOTE_PROJECT/.env' 2>/dev/null | cut -d= -f2- | tr -d '\"'" || true)"
    app_url="${app_url%/}"

    if [[ -z "$app_url" ]]; then
        return 1
    fi

    printf '%s' "$app_url"
}

# Verify the KVM Laravel public directory and configured REMOTE_PUBLIC path.
kvm_doctor_check_laravel_public() {
    local kvm_public="${REMOTE_PROJECT}/public"

    if [[ "$REMOTE_PUBLIC" != "$kvm_public" ]]; then
        return 1
    fi

    ssh_exec "test -d '$kvm_public' && test -f '$kvm_public/index.php' && test -f '$REMOTE_PROJECT/artisan' && grep -Fq '../vendor/autoload.php' '$kvm_public/index.php'"
}

# Verify the deployed Vite manifest exists under the KVM public directory.
kvm_doctor_check_remote_manifest() {
    ssh_exec "test -f '${REMOTE_PROJECT}/public/build/manifest.json'"
}

# Verify APP_URL /up responds with HTTP 200.
kvm_doctor_check_up_endpoint() {
    local app_url http_status

    app_url="$(kvm_remote_app_url)" || return 1

    http_status="$(ssh_exec "curl -s -o /dev/null -w '%{http_code}' --max-time 30 '${app_url}/up'" || true)"

    [[ "$http_status" == "200" ]]
}

# Verify the configured Supervisor program exists and is RUNNING.
kvm_doctor_check_supervisor_program() {
    local program="${SUPERVISOR_PROGRAM:-radium-desk-queue-worker}"
    local output

    output="$(ssh_exec "supervisorctl status '${program}' 2>/dev/null || sudo -n supervisorctl status '${program}' 2>/dev/null" 2>/dev/null || true)"

    if [[ -z "$output" ]]; then
        return 1
    fi

    if [[ "$output" == *"no such process"* ]] || [[ "$output" == *"ERROR"* ]]; then
        return 1
    fi

    [[ "$output" == *RUNNING* ]]
}

# Verify Redis connectivity via Laravel's configured connection (no secret output).
kvm_doctor_check_redis() {
    php_exec tinker --execute="exit(Illuminate\\Support\\Facades\\Redis::connection()->ping() ? 0 : 1);" >/dev/null 2>&1
}

# Legacy shared-hosting doctor check: Laravel and public_html Vite manifests match.
legacy_doctor_check_vite_manifest_parity() {
    local remote_project_manifest="${REMOTE_PROJECT}/public/build/manifest.json"
    local remote_public_manifest="${LEGACY_REMOTE_PUBLIC}/build/manifest.json"
    local project_hash public_hash

    if ! ssh_exec "test -f '$remote_project_manifest' && test -f '$remote_public_manifest'"; then
        return 2
    fi

    project_hash="$(ssh_exec "md5sum '$remote_project_manifest' | awk '{print \$1}'")"
    public_hash="$(ssh_exec "md5sum '$remote_public_manifest' | awk '{print \$1}'")"

    [[ "$project_hash" == "$public_hash" ]]
}

# Legacy shared-hosting doctor check: generated index.php references bootstrap paths.
legacy_doctor_check_shared_hosting_index() {
    verify_shared_hosting_index
}
