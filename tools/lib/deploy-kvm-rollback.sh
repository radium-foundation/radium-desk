#!/usr/bin/env bash
#
# Authorized KVM application rollback release validation (P-22-06).
#
# Sourced by deploy-kvm.sh. Normal forward releases keep latest-tag validation.
# Rollback redeploys application/runtime only; never DB or financial reversal.

set -euo pipefail

_DEPLOY_KVM_ROLLBACK_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="${KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON:-$_DEPLOY_KVM_ROLLBACK_LIB_DIR/kvm-authorized-rollback-targets.json}"

deploy_kvm_rollback_mode_enabled() {
    [[ "${DEPLOY_KVM_AUTHORIZED_ROLLBACK:-0}" == "1" ]]
}

_deploy_kvm_rollback_lookup_target() {
    local target_version="$1"
    python3 - "$KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON" "$target_version" <<'PY'
import json
import sys

path, version = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
for item in data.get("targets") or []:
    if item.get("version") == version:
        build = item.get("build") or ""
        if not build:
            sys.exit(3)
        print(build)
        sys.exit(0)
sys.exit(2)
PY
}

ensure_rollback_deploy_branch() {
    local current_branch

    current_branch="$(git -C "$PROJECT_ROOT" rev-parse --abbrev-ref HEAD)"
    case "$current_branch" in
        main)
            print_success "Rollback deploy branch: main"
            ;;
        HEAD)
            print_warning "Rollback deploy on detached HEAD (authorized when build matches target)"
            ;;
        rollback/*)
            print_success "Rollback deploy branch: ${current_branch}"
            ;;
        *)
            print_error "Rollback deploy requires branch main, rollback/*, or detached HEAD at known-good build (currently '${current_branch}')."
            exit 1
            ;;
    esac
}

validate_authorized_rollback_release_metadata() {
    local target_version expected_build head_full head_short ledger_id changelog_pattern

    if ! deploy_kvm_rollback_mode_enabled; then
        print_error "validate_authorized_rollback_release_metadata called without DEPLOY_KVM_AUTHORIZED_ROLLBACK=1"
        exit 1
    fi

    ledger_id="${DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID:-}"
    if [[ -z "$ledger_id" ]]; then
        print_error "DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID is required for authorized KVM rollback."
        exit 1
    fi

    target_version="${DEPLOY_KVM_ROLLBACK_TARGET_VERSION:-}"
    if [[ ! "$target_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "DEPLOY_KVM_ROLLBACK_TARGET_VERSION must be semver (e.g. 4.1.110); got '${target_version:-<unset>}'."
        exit 1
    fi

    if [[ ! -f "$KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON" ]]; then
        print_error "Rollback allowlist missing: ${KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON}"
        exit 1
    fi

    if ! expected_build="$(_deploy_kvm_rollback_lookup_target "$target_version")"; then
        print_error "Rollback target version ${target_version} is not in the authorized allowlist (${KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON})."
        exit 1
    fi

    head_full="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
    head_short="$(git -C "$PROJECT_ROOT" rev-parse --short=9 HEAD)"
    if [[ "$head_full" != "$expected_build" && "$head_short" != "${expected_build:0:9}" ]]; then
        print_error "HEAD (${head_short}) does not match authorized rollback build ${expected_build} for version ${target_version}."
        exit 1
    fi

    changelog_pattern="^##[[:space:]]${target_version}[[:space:]]"
    if ! grep -Eq "$changelog_pattern" "$PROJECT_ROOT/CHANGELOG.md"; then
        print_error "CHANGELOG.md is missing an entry for rollback version ${target_version}."
        exit 1
    fi

    print_warning "AUTHORIZED KVM APPLICATION ROLLBACK — runtime/code rsync only."
    print_warning "Database state, migration history, wallet ledger, and financial activity are NOT automatically reversed."
    print_warning "Rollback ledger=${ledger_id} target_version=${target_version} build=${expected_build} head=${head_short}"
    print_success "Authorized rollback release metadata validated (version=${target_version}, build=${expected_build})"
}