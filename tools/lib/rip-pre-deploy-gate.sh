#!/usr/bin/env bash
#
# RIP pre-deploy gate for KVM deploy. Sourced by deploy-kvm.sh.
#
# When RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1:
#   - Requires RIP_PRE_DEPLOY_CHECKPOINT_ID
#   - Verifies checkpoint on production via SSH (manifest + SHA256SUMS)
#   - Confirms checkpoint commit matches live release.json build (current production — not the next deploy commit)
#
# Does not capture checkpoints or deploy.

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "rip-pre-deploy-gate.sh must be sourced, not executed directly." >&2
    exit 1
fi

rip_verify_pre_deploy_gate_if_required() {
    if [[ "${RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT:-0}" != "1" ]]; then
        print_warning "RIP pre-deploy gate skipped (set RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1 to enforce)"
        return 0
    fi

    local checkpoint_id="${RIP_PRE_DEPLOY_CHECKPOINT_ID:-}"

    if [[ -z "$checkpoint_id" ]]; then
        print_error "RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1 but RIP_PRE_DEPLOY_CHECKPOINT_ID is unset"
        return 1
    fi

    local remote_path="${REMOTE_PROJECT}/storage/app/private/release-checkpoints/${checkpoint_id}"

    print_warning "RIP pre-deploy gate: verifying ${checkpoint_id} on ${SSH_HOST}..."

    if ! ssh_exec "test -d '${remote_path}'"; then
        print_error "Pre-deploy checkpoint directory not found on production: ${remote_path}"
        return 1
    fi

    if ! ssh_exec "cd '${REMOTE_PROJECT}' && ${PHP_BIN} artisan rip:verify-checkpoint 'storage/app/private/release-checkpoints/${checkpoint_id}'"; then
        print_error "RIP checkpoint verification failed on production"
        return 1
    fi

    local live_build
    live_build="$(ssh_exec "cd '${REMOTE_PROJECT}' && ${PHP_BIN} -r 'echo json_decode(file_get_contents(\"storage/app/private/release.json\"), true)[\"build\"] ?? \"\";'" 2>/dev/null || true)"

    if [[ -z "$live_build" ]]; then
        print_error "Could not read release.json build from production for RIP baseline match"
        return 1
    fi

    local checkpoint_short="${checkpoint_id##*-}"

    if [[ "$(echo "$live_build" | tr '[:upper:]' '[:lower:]')" != "$(echo "$checkpoint_short" | tr '[:upper:]' '[:lower:]')" ]]; then
        print_error "Pre-deploy checkpoint suffix (${checkpoint_short}) does not match production release.json build (${live_build})"
        print_error "Capture a fresh pre-deploy RIP for the current production commit before deploying."
        return 1
    fi

    print_success "RIP pre-deploy gate passed (${checkpoint_id} matches production build ${live_build})"
    return 0
}

rip_post_deploy_capture_reminder() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        return 0
    fi

    local short_sha
    short_sha="$(git -C "$PROJECT_ROOT" rev-parse --short=7 HEAD 2>/dev/null || echo unknown)"

    print_warning "Post-deploy: capture post-RIP on production after smoke checks:"
    print_warning "  ./tools/commands/capture-release-recovery-checkpoint.sh --change <post-label> --git-sha ${short_sha}"
    print_warning "Record status smoke-verified / known-good only with evidence (see docs/rip-release-checkpoint.md)."
}
