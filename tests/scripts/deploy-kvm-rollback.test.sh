#!/usr/bin/env bash
# Authorized KVM rollback metadata validation (P-22-06).
#
# Run: bash tests/scripts/deploy-kvm-rollback.test.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_KVM="$ROOT/tools/commands/deploy-kvm.sh"
ALLOWLIST="$ROOT/tools/lib/kvm-authorized-rollback-targets.json"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DEPLOY_KVM" ]] || fail "deploy-kvm.sh missing"
[[ -f "$ALLOWLIST" ]] || fail "rollback allowlist missing"
bash -n "$DEPLOY_KVM" || fail "deploy-kvm syntax"

grep -q 'deploy-kvm-rollback.sh' "$DEPLOY_KVM" \
    || fail "deploy-kvm must source deploy-kvm-rollback.sh"
grep -q 'deploy_kvm_rollback_mode_enabled' "$DEPLOY_KVM" \
    || fail "deploy-kvm must branch on rollback mode"

init_fixture_repo() {
    local repo="$1"
    git init -q "$repo"
    git -C "$repo" config user.email "rollback-test@test"
    git -C "$repo" config user.name "rollback-test"

    mkdir -p "$repo/public/build"
    echo '{}' >"$repo/public/build/manifest.json"
    cat >"$repo/CHANGELOG.md" <<'EOF'
# Changelog
## 4.1.111 — 2026-11-21 — forward
- Rollback target: **v4.1.110**.
## 4.1.110 — 2026-11-21 — baseline
- baseline release.
EOF
    git -C "$repo" add CHANGELOG.md public/build/manifest.json
    git -C "$repo" checkout -q -B main
    git -C "$repo" commit -q -m "v4.1.110 baseline"
    local baseline_sha
    baseline_sha="$(git -C "$repo" rev-parse HEAD)"

    echo 'forward' >>"$repo/CHANGELOG.md"
    git -C "$repo" commit -q -am "v4.1.111 forward"
    git -C "$repo" tag v4.1.111

    git -C "$repo" checkout -q "$baseline_sha"
    echo "$baseline_sha"
}

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
REPO="$TMP/repo"
BASELINE_SHA="$(init_fixture_repo "$REPO")"

TEST_ALLOWLIST="$TMP/targets.json"
python3 - "$TEST_ALLOWLIST" "$BASELINE_SHA" <<'PY'
import json
import sys

path, build = sys.argv[1], sys.argv[2]
json.dump(
    {
        "schema_version": 1,
        "targets": [{"version": "4.1.110", "build": build, "notes": "fixture"}],
    },
    open(path, "w", encoding="utf-8"),
    indent=2,
)
PY

# Normal deploy rejects baseline (not latest tag).
if (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="$TEST_ALLOWLIST"
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata >/dev/null 2>&1
); then
    fail "normal deploy must reject HEAD below latest semver tag"
fi
pass "normal deploy still requires latest-tag identity"

# Unauthorized rollback flag off with wrong HEAD — already covered.

# Missing ledger rejected.
if (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="$TEST_ALLOWLIST"
    DEPLOY_KVM_AUTHORIZED_ROLLBACK=1
    DEPLOY_KVM_ROLLBACK_TARGET_VERSION=4.1.110
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata >/dev/null 2>&1
); then
    fail "rollback must require DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID"
fi
pass "missing rollback ledger rejected"

# Unknown target version rejected.
if (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="$TEST_ALLOWLIST"
    DEPLOY_KVM_AUTHORIZED_ROLLBACK=1
    DEPLOY_KVM_ROLLBACK_TARGET_VERSION=9.9.9
    DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID=test-ledger
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata >/dev/null 2>&1
); then
    fail "arbitrary rollback target must be rejected"
fi
pass "unknown rollback target rejected"

# Wrong HEAD rejected (stay on baseline but claim wrong allowlist build).
WRONG_REPO="$TMP/wrong"
cp -a "$REPO" "$WRONG_REPO"
git -C "$WRONG_REPO" checkout -q main
if (
    DEPLOY_KVM_PROJECT_ROOT="$WRONG_REPO"
    KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="$TEST_ALLOWLIST"
    DEPLOY_KVM_AUTHORIZED_ROLLBACK=1
    DEPLOY_KVM_ROLLBACK_TARGET_VERSION=4.1.110
    DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID=test-ledger
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata >/dev/null 2>&1
); then
    fail "rollback must reject HEAD that does not match allowlisted build"
fi
pass "wrong HEAD for rollback rejected"

# Authorized rollback accepts exact baseline build.
OUT="$(
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    KVM_AUTHORIZED_ROLLBACK_TARGETS_JSON="$TEST_ALLOWLIST"
    DEPLOY_KVM_AUTHORIZED_ROLLBACK=1
    DEPLOY_KVM_ROLLBACK_TARGET_VERSION=4.1.110
    DEPLOY_KVM_ROLLBACK_OWNER_LEDGER_ID=RadiumDesk-P-22-06-test
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata 2>&1
)"
[[ "$OUT" == *"Database state"* && "$OUT" == *"NOT automatically reversed"* ]] \
    || fail "rollback must warn DB/financial not reversed"
[[ "$OUT" == *"Rollback ledger=RadiumDesk-P-22-06-test"* ]] || fail "rollback must log ledger id"
pass "authorized rollback accepts allowlisted build"

# Production allowlist contains v4.1.110 / 0f089eeeb (static).
if ! grep -q '"version": "4.1.110"' "$ALLOWLIST"; then
    fail "production allowlist missing 4.1.110"
fi
if ! grep -q '0f089eeeb' "$ALLOWLIST"; then
    fail "production allowlist missing 0f089eeeb build"
fi
pass "production allowlist documents v4.1.110 / 0f089eeeb"

echo "All deploy-kvm-rollback tests passed."