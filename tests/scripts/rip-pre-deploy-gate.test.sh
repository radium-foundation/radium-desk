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

pass "rip-pre-deploy-gate.test.sh complete"
