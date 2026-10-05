#!/usr/bin/env bash
#
# Preflight separation: dry-run vs live release validation.
#
# Run: bash tests/scripts/deploy-kvm-preflight.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_KVM="$ROOT/tools/commands/deploy-kvm.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DEPLOY_KVM" ]] || fail "deploy-kvm.sh missing"
bash -n "$DEPLOY_KVM" || fail "deploy-kvm.sh syntax invalid"

# --- Static structure checks ---

grep -q 'validate_dry_run_candidate' "$DEPLOY_KVM" \
    || fail "must define validate_dry_run_candidate for read-only dry-run"
grep -q 'Dry-run analyzes the current working tree; exact semver tag is not required' "$DEPLOY_KVM" \
    || fail "dry-run candidate validation must document tag exemption"

MAIN_BODY="$(awk '/^main\(\)/,/^}/' "$DEPLOY_KVM")"
[[ -n "$MAIN_BODY" ]] || fail "could not extract main() from deploy-kvm.sh"

echo "$MAIN_BODY" | grep -q 'if \[\[ "\$DRY_RUN" -eq 1 \]\]' \
    || fail "main must branch explicitly on DRY_RUN"
echo "$MAIN_BODY" | grep -q 'validate_dry_run_candidate' \
    || fail "dry-run branch must call validate_dry_run_candidate"

# validate_release_metadata must appear after the dry-run early-exit block.
DRY_RUN_EXIT_LINE="$(grep -n 'Dry-run completed (no changes made on KVM)' "$DEPLOY_KVM" | cut -d: -f1)"
RELEASE_META_LINE="$(grep -n '^[[:space:]]*validate_release_metadata$' "$DEPLOY_KVM" | head -n1 | cut -d: -f1)"
[[ -n "$DRY_RUN_EXIT_LINE" && -n "$RELEASE_META_LINE" ]] \
    || fail "could not locate dry-run exit and validate_release_metadata"
[[ "$RELEASE_META_LINE" -gt "$DRY_RUN_EXIT_LINE" ]] \
    || fail "validate_release_metadata must run only on live deploy path"

pass "static preflight separation structure"

# --- Temp git fixture: HEAD ahead of latest tag ---

init_ahead_of_tag_repo() {
    local repo="$1"

    git init -q "$repo"
    git -C "$repo" config user.email "deploy-kvm-preflight@test"
    git -C "$repo" config user.name "deploy-kvm-preflight"

    mkdir -p "$repo/public/build"
    echo '{}' >"$repo/public/build/manifest.json"
    echo 'init' >"$repo/README.md"
    git -C "$repo" add README.md public/build/manifest.json
    git -C "$repo" checkout -q -B main
    git -C "$repo" commit -q -m "init"
    git -C "$repo" tag v0.0.1
    echo 'second' >>"$repo/README.md"
    git -C "$repo" commit -q -am "ahead of tag"
}

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
REPO="$TMP/repo"
init_ahead_of_tag_repo "$REPO"

# B. validate_release_metadata rejects untagged HEAD (live path guard)
if (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_release_metadata >/dev/null 2>&1
); then
    fail "validate_release_metadata must reject HEAD ahead of latest tag"
fi
pass "live path rejects HEAD ahead of latest semver tag"

# A/G. dry-run preflight allows untagged HEAD and reaches analysis hooks
if ! (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    set -- --dry-run
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"

    verify_hardware_dashboard_contract() { :; }
    verify_ready_queue_contract() { :; }
    deploy_rsync_analyze_deletions() { return 0; }
    rsync_application_to_kvm() { :; }
    rsync() { :; }

    output="$(main 2>&1)"
    [[ "$output" == *"Dry-run read-only candidate validated"* ]]
    [[ "$output" == *"Dry-run mode: deletion inventory and rsync preview only"* ]]
    [[ "$output" == *"Dry-run completed (no changes made on KVM)"* ]]
    [[ "$output" != *"HEAD is not exactly tagged"* ]]
); then
    fail "dry-run must succeed when HEAD is ahead of latest tag"
fi
pass "dry-run allowed when HEAD is ahead of latest tag"

# B. dirty worktree still blocks dry-run
echo dirty >"$REPO/dirty.txt"
if (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    set -- --dry-run
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    verify_hardware_dashboard_contract() { :; }
    verify_ready_queue_contract() { :; }
    main >/dev/null 2>&1
); then
    rm -f "$REPO/dirty.txt"
    fail "dry-run must reject dirty worktree"
fi
rm -f "$REPO/dirty.txt"
pass "dry-run still requires clean worktree"

# I. validate_dry_run_candidate on valid fixture
if ! (
    DEPLOY_KVM_PROJECT_ROOT="$REPO"
    # shellcheck source=tools/commands/deploy-kvm.sh
    source "$DEPLOY_KVM"
    validate_dry_run_candidate >/dev/null
); then
    fail "validate_dry_run_candidate must accept ahead-of-tag fixture"
fi
pass "validate_dry_run_candidate accepts ahead-of-tag fixture"

echo "All deploy-kvm-preflight tests passed."
