#!/usr/bin/env bash
#
# Local RIP pre-deploy gate behavior (mocked SSH; no production).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE_LIB="${ROOT}/tools/lib/rip-pre-deploy-gate.sh"
DEPLOY_KVM="${ROOT}/tools/commands/deploy-kvm.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

bash -n "${GATE_LIB}" || fail "rip-pre-deploy-gate.sh syntax"

print_warning() { WARNINGS+=("$*"); }
print_error() { ERRORS+=("$*"); }
print_success() { SUCCESSES+=("$*"); }
WARNINGS=()
ERRORS=()
SUCCESSES=()
SSH_HOST="test-host"
REMOTE_PROJECT="/var/www/radium-desk"
PHP_BIN="php"
DEPLOY_MODE="kvm"
RIP_KVM_PRODUCTION_SSH_HOST="187.127.129.16"
RIP_KVM_PRODUCTION_REMOTE_PROJECT="/var/www/radium-desk"

SSH_CALLS=()
ssh_exec() {
    SSH_CALLS+=("$*")
    case "$*" in
        *"test -d"*)
            return 0
            ;;
        *rip:verify-checkpoint*)
            return 0
            ;;
        *release.json*)
            echo -n "7441de0"
            return 0
            ;;
    esac
    return 1
}

# shellcheck source=tools/lib/rip-pre-deploy-gate.sh
source "${GATE_LIB}"

unset RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT
unset RIP_PRE_DEPLOY_CHECKPOINT_ID
WARNINGS=()
if ! rip_verify_pre_deploy_gate_if_required; then
    fail "gate disabled should return 0"
fi
[[ ${#WARNINGS[@]} -ge 1 ]] || fail "gate disabled should emit skip warning"
pass "gate skipped when RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT unset"

export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
unset RIP_PRE_DEPLOY_CHECKPOINT_ID
ERRORS=()
if rip_verify_pre_deploy_gate_if_required; then
    fail "gate enabled without checkpoint id must fail"
fi
pass "gate fails when checkpoint id missing"

export RIP_PRE_DEPLOY_CHECKPOINT_ID="RIP-211026-1200-radium-desk-pre-deploy-gate-7441de0"
SSH_CALLS=()
if ! rip_verify_pre_deploy_gate_if_required; then
    fail "gate should pass with matching production build"
fi
pass "gate passes when production build matches checkpoint suffix"

export RIP_PRE_DEPLOY_CHECKPOINT_ID="RIP-211026-1200-radium-desk-pre-deploy-gate-aaaaaaaa"
SSH_CALLS=()
if rip_verify_pre_deploy_gate_if_required; then
    fail "gate must fail when production build mismatches checkpoint suffix"
fi
pass "gate fails on production build mismatch"

grep -q 'Dry-run: RIP production-baseline gate is not exercised' "${DEPLOY_KVM}" \
    || fail "deploy-kvm dry-run must document that RIP gate is not exercised"
pass "deploy dry-run documents RIP gate not exercised"

# --- Production deploy warning (non-blocking; no secrets) ---
grep -q 'rip_warn_if_production_deploy_without_rip_gate' "${DEPLOY_KVM}" \
    || fail "deploy-kvm must call production RIP gate-disabled warning"

SSH_HOST="187.127.129.16"
REMOTE_PROJECT="/var/www/radium-desk"
DEPLOY_MODE="kvm"
unset RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT
WARNINGS=()
rip_warn_if_production_deploy_without_rip_gate || fail "warning helper must not fail"
grep -q 'DISABLED' <<<"${WARNINGS[*]}" || fail "production + gate unset must warn gate disabled"
grep -q 'Pre-deploy operator checklist' <<<"${WARNINGS[*]}" || fail "must reference checklist doc"
grep -qE '(password|secret|\.env)' <<<"${WARNINGS[*]}" && fail "warning must not emit secrets"
pass "production target + gate unset emits non-blocking checklist warning"

export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=0
WARNINGS=()
rip_warn_if_production_deploy_without_rip_gate
grep -q 'DISABLED' <<<"${WARNINGS[*]}" || fail "production + gate=0 must warn"
pass "production target + gate explicitly disabled warns"

export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
export RIP_PRE_DEPLOY_CHECKPOINT_ID="RIP-211026-1200-radium-desk-pre-deploy-gate-7441de0"
WARNINGS=()
rip_warn_if_production_deploy_without_rip_gate
[[ ${#WARNINGS[@]} -eq 0 ]] || fail "production + gate enabled must not emit gate-disabled warning"
pass "production target + gate enabled suppresses gate-disabled warning"

SSH_HOST="staging.example.test"
WARNINGS=()
unset RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT
rip_warn_if_production_deploy_without_rip_gate
[[ ${#WARNINGS[@]} -eq 0 ]] || fail "non-production host must not emit production warning"
pass "non-production target + gate disabled does not warn"

SSH_HOST="187.127.129.16"
unset RIP_KVM_PRODUCTION_SSH_HOST
rip_is_verified_kvm_production_deploy_target && fail "missing canonical pin must not identify production"
RIP_KVM_PRODUCTION_SSH_HOST="187.127.129.16"
pass "production identity requires canonical pins"

# Gate enforcement unchanged when enabled
export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
export RIP_PRE_DEPLOY_CHECKPOINT_ID="RIP-211026-1200-radium-desk-pre-deploy-gate-7441de0"
SSH_CALLS=()
if ! rip_verify_pre_deploy_gate_if_required; then
    fail "gate enforcement must still pass when enabled"
fi
pass "gate enforcement unchanged when enabled"

pass "rip-pre-deploy-gate.test.sh complete"
