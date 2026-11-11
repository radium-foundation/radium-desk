#!/usr/bin/env bash
#
# Shared post-sync migration and RolePermissionSeeder opt-in gates.
# Sourced by deploy-kvm.sh and deploy.sh (legacy shared hosting).
#
# Defaults: skip both. Set DEPLOY_RUN_MIGRATIONS=1 and/or
# DEPLOY_RUN_ROLE_PERMISSION_SEED=1 immediately before a deliberate deploy.
#
# Requires: print_warning, print_error, print_success, php_exec (from lib.sh).

if [[ -n "${RADIUM_DESK_DEPLOY_POST_SYNC_SH_LOADED:-}" ]]; then
    return 0 2>/dev/null || exit 0
fi
RADIUM_DESK_DEPLOY_POST_SYNC_SH_LOADED=1

deploy_normalize_opt_in_flag() {
    local flag_name="$1"
    local raw="${!flag_name:-0}"

    case "$raw" in
        0|1)
            printf '%s' "$raw"
            ;;
        '')
            printf '0'
            ;;
        *)
            print_error "Invalid ${flag_name}=${raw} (allowed: 0 or 1)"
            exit 1
            ;;
    esac
}

deploy_validate_post_sync_flags() {
    deploy_normalize_opt_in_flag DEPLOY_RUN_MIGRATIONS >/dev/null
    deploy_normalize_opt_in_flag DEPLOY_RUN_ROLE_PERMISSION_SEED >/dev/null
}

deploy_count_pending_migrations() {
    local status_output pending

    if ! status_output="$(php_exec migrate:status 2>&1)"; then
        print_error "Unable to read migration status for pending count."
        exit 1
    fi

    pending="$(printf '%s\n' "$status_output" | awk '/\| Pending[[:space:]]+\|/ { c++ } END { print c + 0 }')"
    printf '%s' "$pending"
}

deploy_maybe_run_migrations() {
    local enabled pending

    enabled="$(deploy_normalize_opt_in_flag DEPLOY_RUN_MIGRATIONS)"
    if [[ "$enabled" != "1" ]]; then
        print_warning "Migrations: SKIPPED (DEPLOY_RUN_MIGRATIONS=${DEPLOY_RUN_MIGRATIONS:-0}; set to 1 to run)"
        return 0
    fi

    pending="$(deploy_count_pending_migrations)"
    print_warning "Migrations: ENABLED — ${pending} pending migration(s) before run"
    print_warning "Running database migrations..."
    php_exec migrate --force
    print_success "Migrations completed (${pending} were pending before run)"
}

deploy_maybe_run_role_permission_seeder() {
    local enabled

    enabled="$(deploy_normalize_opt_in_flag DEPLOY_RUN_ROLE_PERMISSION_SEED)"
    if [[ "$enabled" != "1" ]]; then
        print_warning "RolePermissionSeeder: SKIPPED (DEPLOY_RUN_ROLE_PERMISSION_SEED=${DEPLOY_RUN_ROLE_PERMISSION_SEED:-0}; set to 1 to run)"
        return 0
    fi

    print_warning "RolePermissionSeeder: ENABLED — seeding role permissions..."
    php_exec db:seed --class=RolePermissionSeeder --force
    php_exec permission:cache-reset
    print_success "Role permissions seeded"
}
