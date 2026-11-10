#!/usr/bin/env bash
#
# lib.sh must tolerate repeated sourcing (deploy script stacks).
#
# Run: bash tests/scripts/lib-sh-sourcing.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIB="$ROOT/tools/lib.sh"
DEPLOY_KVM="$ROOT/tools/commands/deploy-kvm.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$LIB" ]] || fail "lib.sh missing"

if ! bash -c "source '$LIB'; source '$LIB'; print_success 'double-source ok'" 2>/dev/null; then
    fail "lib.sh must tolerate double sourcing"
fi
pass "lib.sh double sourcing"

if ! bash -c "source '$LIB'; source '$DEPLOY_KVM'; type -t validate_release_metadata" | grep -q function; then
    fail "lib then deploy-kvm must load without readonly errors"
fi
pass "lib then deploy-kvm sourcing"

if [[ -f "$ROOT/CHANGELOG.md" ]] && git -C "$ROOT" describe --exact-match --tags HEAD 2>/dev/null | grep -qx v4.1.61; then
    out="$(bash -c "export DEPLOY_KVM_PROJECT_ROOT='$ROOT'; source '$LIB'; source '$DEPLOY_KVM'; validate_release_metadata" 2>&1)" || fail "validate_release_metadata on tagged HEAD: $out"
    pass "validate_release_metadata on v4.1.61 checkout"
fi

echo "All lib-sh-sourcing tests passed."
