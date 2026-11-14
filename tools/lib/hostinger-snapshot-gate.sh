#!/usr/bin/env bash
#
# Hostinger snapshot verify-only deployment gate (P-14-17).
# Sourced by deploy-kvm.sh — GET only via hostinger_vps_snapshot_gate.py
#

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "hostinger-snapshot-gate.sh must be sourced, not executed directly." >&2
    exit 1
fi

_HOSTINGER_SNAPSHOT_GATE_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOSTINGER_SNAPSHOT_GATE_PY="${HOSTINGER_SNAPSHOT_GATE_PY:-$_HOSTINGER_SNAPSHOT_GATE_LIB_DIR/hostinger_vps_snapshot_gate.py}"
HOSTINGER_SNAPSHOT_GATE_LOCK="${HOSTINGER_SNAPSHOT_GATE_LOCK:-/tmp/radium-desk-kvm-deploy.lock}"
HOSTINGER_SNAPSHOT_GATE_STATE="${HOSTINGER_SNAPSHOT_GATE_STATE:-}"

_hostinger_deploy_tier_upper() {
    printf '%s' "${1:-}" | tr '[:lower:]' '[:upper:]'
}

hostinger_is_verified_kvm_production_deploy_target() {
    if [[ "${DEPLOY_MODE:-}" != "kvm" ]]; then
        return 1
    fi
    if [[ -z "${RIP_KVM_PRODUCTION_SSH_HOST:-}" || -z "${RIP_KVM_PRODUCTION_REMOTE_PROJECT:-}" ]]; then
        return 1
    fi
    if [[ -z "${SSH_HOST:-}" || -z "${REMOTE_PROJECT:-}" ]]; then
        return 1
    fi
    [[ "${SSH_HOST}" == "${RIP_KVM_PRODUCTION_SSH_HOST}" ]] \
        && [[ "${REMOTE_PROJECT}" == "${RIP_KVM_PRODUCTION_REMOTE_PROJECT}" ]]
}

hostinger_snapshot_gate_tier_exempt() {
    local tier
    tier="$(_hostinger_deploy_tier_upper "${DEPLOY_TIER:-}")"
    [[ "$tier" == "C" ]]
}

hostinger_warn_if_unprotected_tier() {
    if ! hostinger_is_verified_kvm_production_deploy_target; then
        return 0
    fi
    local tier
    tier="$(_hostinger_deploy_tier_upper "${DEPLOY_TIER:-}")"
    if [[ "${HOSTINGER_SNAPSHOT_GATE:-0}" == "1" ]]; then
        return 0
    fi
    if [[ "$tier" == "A" || "$tier" == "B" ]]; then
        print_warning "Hostinger snapshot gate DISABLED — Tier ${tier} is NOT recovery-protected at VPS layer."
        print_warning "Default-off HOSTINGER_SNAPSHOT_GATE does not constitute production protection."
        print_warning "Enable HOSTINGER_SNAPSHOT_GATE=1 only after Owner acceptance (see docs/P-14-16)."
    fi
    return 0
}

hostinger_snapshot_gate_acquire_lock() {
    if [[ -z "${HOSTINGER_SNAPSHOT_GATE_LOCK:-}" ]]; then
        return 0
    fi
    if command -v flock >/dev/null 2>&1; then
        exec {HOSTINGER_SNAPSHOT_GATE_LOCK_FD}>"$HOSTINGER_SNAPSHOT_GATE_LOCK"
        if ! flock -n "$HOSTINGER_SNAPSHOT_GATE_LOCK_FD"; then
            print_error "Another KVM deploy holds the Hostinger gate lock (${HOSTINGER_SNAPSHOT_GATE_LOCK})."
            return 1
        fi
        HOSTINGER_SNAPSHOT_GATE_LOCK_HELD=1
        export HOSTINGER_SNAPSHOT_GATE_LOCK_HELD
        return 0
    fi
    HOSTINGER_SNAPSHOT_GATE_LOCK_DIR="${HOSTINGER_SNAPSHOT_GATE_LOCK}.held"
    if ! mkdir "$HOSTINGER_SNAPSHOT_GATE_LOCK_DIR" 2>/dev/null; then
        print_error "Another KVM deploy holds the Hostinger gate lock (${HOSTINGER_SNAPSHOT_GATE_LOCK_DIR})."
        return 1
    fi
    HOSTINGER_SNAPSHOT_GATE_LOCK_HELD=1
    export HOSTINGER_SNAPSHOT_GATE_LOCK_HELD
    return 0
}

hostinger_snapshot_gate_release_lock() {
    if [[ "${HOSTINGER_SNAPSHOT_GATE_LOCK_HELD:-}" != "1" ]]; then
        return 0
    fi
    if [[ -n "${HOSTINGER_SNAPSHOT_GATE_LOCK_FD:-}" ]]; then
        flock -u "$HOSTINGER_SNAPSHOT_GATE_LOCK_FD" 2>/dev/null || true
        exec {HOSTINGER_SNAPSHOT_GATE_LOCK_FD}>&-
        unset HOSTINGER_SNAPSHOT_GATE_LOCK_FD
    fi
    if [[ -n "${HOSTINGER_SNAPSHOT_GATE_LOCK_DIR:-}" && -d "$HOSTINGER_SNAPSHOT_GATE_LOCK_DIR" ]]; then
        rmdir "$HOSTINGER_SNAPSHOT_GATE_LOCK_DIR" 2>/dev/null || true
        unset HOSTINGER_SNAPSHOT_GATE_LOCK_DIR
    fi
    unset HOSTINGER_SNAPSHOT_GATE_LOCK_HELD
}

hostinger_run_snapshot_gate_python() {
    local phase="$1"
    export HOSTINGER_SNAPSHOT_GATE_PHASE="$phase"
    if [[ "$phase" == "final" && -n "${HOSTINGER_SNAPSHOT_GATE_BASELINE_ID:-}" ]]; then
        export HOSTINGER_SNAPSHOT_GATE_BASELINE_ID
    else
        unset HOSTINGER_SNAPSHOT_GATE_BASELINE_ID
    fi
    local outfile
    outfile="$(mktemp "${TMPDIR:-/tmp}/hostinger-snapshot-gate.XXXXXX")"
    if ! python3 "$HOSTINGER_SNAPSHOT_GATE_PY" --json >"$outfile" 2>&1; then
        local ec=$?
        cat "$outfile" >&2
        rm -f "$outfile"
        return "$ec"
    fi
    cat "$outfile"
    if [[ "$phase" == "initial" ]]; then
        HOSTINGER_SNAPSHOT_GATE_BASELINE_ID="$(
            python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("snapshot_id") or "")' "$outfile"
        )"
        if [[ -n "$HOSTINGER_SNAPSHOT_GATE_BASELINE_ID" ]]; then
            export HOSTINGER_SNAPSHOT_GATE_BASELINE_ID
        fi
    fi
    rm -f "$outfile"
    return 0
}

hostinger_verify_snapshot_gate_if_required() {
    if ! hostinger_is_verified_kvm_production_deploy_target; then
        return 0
    fi
    hostinger_warn_if_unprotected_tier
    if hostinger_snapshot_gate_tier_exempt; then
        return 0
    fi
    if [[ "${HOSTINGER_SNAPSHOT_GATE:-0}" != "1" ]]; then
        return 0
    fi
    if ! hostinger_snapshot_gate_acquire_lock; then
        return 1
    fi
    print_warning "Hostinger snapshot gate (initial): live GET verification..."
    local report
    if ! report="$(hostinger_run_snapshot_gate_python initial)"; then
        print_error "Hostinger snapshot initial gate failed"
        hostinger_snapshot_gate_release_lock
        return 1
    fi
    if ! python3 -c 'import json,sys; d=json.loads(sys.argv[1]); sys.exit(0 if d.get("ok") else 1)' "$report"; then
        print_error "Hostinger snapshot initial gate rejected prerequisites"
        echo "$report" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("abort_reason") or d, file=sys.stderr)' 2>/dev/null || true
        hostinger_snapshot_gate_release_lock
        return 1
    fi
    print_success "Hostinger snapshot initial gate passed"
    return 0
}

hostinger_reverify_snapshot_gate_before_rsync() {
    if ! hostinger_is_verified_kvm_production_deploy_target; then
        return 0
    fi
    if hostinger_snapshot_gate_tier_exempt; then
        return 0
    fi
    if [[ "${HOSTINGER_SNAPSHOT_GATE:-0}" != "1" ]]; then
        return 0
    fi
    if [[ -z "${HOSTINGER_SNAPSHOT_GATE_BASELINE_ID:-}" ]]; then
        print_error "Hostinger snapshot final gate missing baseline id from initial check"
        hostinger_snapshot_gate_release_lock
        return 1
    fi
    print_warning "Hostinger snapshot gate (final): re-verify before first production mutation..."
    local report
    if ! report="$(hostinger_run_snapshot_gate_python final)"; then
        print_error "Hostinger snapshot final gate failed"
        hostinger_snapshot_gate_release_lock
        return 1
    fi
    if ! python3 -c 'import json,sys; d=json.loads(sys.argv[1]); sys.exit(0 if d.get("ok") else 1)' "$report"; then
        print_error "Hostinger snapshot final gate rejected (race, expiry, or identity change)"
        echo "$report" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("abort_reason") or d, file=sys.stderr)' 2>/dev/null || true
        hostinger_snapshot_gate_release_lock
        return 1
    fi
    print_success "Hostinger snapshot final gate passed (snapshot id ${HOSTINGER_SNAPSHOT_GATE_BASELINE_ID})"
    print_warning "Deploy lock held until kvm deploy script exits (blocks concurrent gated deploy; does not block external snapshot API POST)."
    return 0
}