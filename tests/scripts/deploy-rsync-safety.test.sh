#!/usr/bin/env bash
#
# Local rsync deletion safety tests (no production deploy, no SSH).
#
# Run: bash tests/scripts/deploy-rsync-safety.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SAFETY_LIB="$ROOT/tools/lib/deploy-rsync-safety.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

print_warning() { :; }
print_error() { echo "ERROR: $*" >&2; }
print_success() { :; }

[[ -f "$SAFETY_LIB" ]] || fail "deploy-rsync-safety.sh missing"
bash -n "$SAFETY_LIB" || fail "deploy-rsync-safety.sh syntax invalid"

# Minimal config for sourced helpers.
SSH_HOST="127.0.0.1"
SSH_PORT="22"
SSH_USER="test"
REMOTE_PROJECT="/var/www/radium-desk"
PROJECT_ROOT="$ROOT"

# shellcheck source=tools/lib/deploy-rsync-safety.sh
source "$SAFETY_LIB"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

init_fixture_source() {
    local src="$1"
    mkdir -p "${src}/app/Models" "${src}/routes" "${src}/storage/app/private"
    echo '<?php // release' >"${src}/app/Models/User.php"
    echo '<?php // routes' >"${src}/routes/web.php"
    echo '{"version":"1.0.0"}' >"${src}/storage/app/private/release.json"
}

# TEST A: no unexpected deletions when trees align
test_a_no_unexpected_deletions() {
    local src="${TMP}/test-a-src" dest="${TMP}/test-a-dest" log="${TMP}/test-a.log"
    init_fixture_source "$src"
    mkdir -p "$dest"
    rsync -a "${src}/" "${dest}/"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"
    paths="$(deploy_rsync_parse_deletion_paths "$log")"

    [[ -z "$paths" ]] || fail "TEST A expected 0 deletions, got: ${paths}"
    pass "TEST A normal aligned release has no deletions"
}

# TEST B: production-only overlay file is classified unexpected
test_b_detect_production_only_file() {
    local src="${TMP}/test-b-src" dest="${TMP}/test-b-dest" log="${TMP}/test-b.log"
    init_fixture_source "$src"
    mkdir -p "${dest}/app/CentralWallet/Overlay"
    echo 'overlay-only' >"${dest}/app/CentralWallet/Overlay/OnlyOnProduction.php"
    rsync -a "${src}/" "${dest}/" 2>/dev/null || true
    echo 'overlay-only' >"${dest}/app/CentralWallet/Overlay/OnlyOnProduction.php"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"
    paths="$(deploy_rsync_parse_deletion_paths "$log")"

    echo "$paths" | grep -Fq 'app/CentralWallet/Overlay/OnlyOnProduction.php' \
        || fail "TEST B dry-run did not detect production-only file"

    classification="$(deploy_rsync_classify_deletion_path "app/CentralWallet/Overlay/OnlyOnProduction.php" HEAD "")"
    [[ "$classification" == "unexpected" ]] || fail "TEST B expected unexpected, got ${classification}"
    pass "TEST B production-only file detected and classified unexpected"
}

# TEST C: explicit approval path exists (non-interactive test hook)
test_c_explicit_approval_hook() {
    DEPLOY_RSYNC_FORCE_UNEXPECTED=1
    if ! deploy_rsync_confirm_unexpected_deletions 3 "/tmp/inventory.tsv"; then
        unset DEPLOY_RSYNC_FORCE_UNEXPECTED
        fail "TEST C approval hook should succeed in test mode"
    fi
    unset DEPLOY_RSYNC_FORCE_UNEXPECTED

    if deploy_rsync_confirm_unexpected_deletions 1 "/tmp/inventory.tsv" </dev/null 2>/dev/null; then
        fail "TEST C must refuse approval without TTY"
    fi
    pass "TEST C explicit approval gate enforced (test hook + TTY refusal)"
}

# TEST D: backup failure blocks destructive path
test_d_backup_failure_blocks() {
    local src="${TMP}/test-d-src" dest="${TMP}/test-d-dest" tsv="${TMP}/test-d.tsv"
    init_fixture_source "$src"
    mkdir -p "${dest}/app/Overlay"
    echo 'old' >"${dest}/app/Overlay/Stale.php"
    printf 'unexpected\tapp/Overlay/Stale.php\n' >"$tsv"

    touch "${TMP}/not-a-directory"
    if deploy_rsync_local_backup_deletions "${TMP}/not-a-directory" "$dest" "$tsv" 2>/dev/null; then
        fail "TEST D backup to invalid path should fail"
    fi
    pass "TEST D backup failure prevents silent destructive proceed"
}

# TEST E: deletion inventory persistence helpers
test_e_inventory_persisted() {
    local dir="${TMP}/inventory" log="${TMP}/dry.log" tsv="${TMP}/class.tsv"
    mkdir -p "$dir"
    echo '*deleting app/Example.php' >"$log"
    printf 'unexpected\tapp/Example.php\n' >"$tsv"

    deploy_rsync_write_inventory "$dir" "TESTSTAMP" "v9.9.9" "v9.9.8" "$log" "$tsv"

    [[ -f "${dir}/rsync-dry-run-TESTSTAMP.log" ]] || fail "TEST E missing dry-run log"
    [[ -f "${dir}/deletion-classification-TESTSTAMP.tsv" ]] || fail "TEST E missing classification tsv"
    [[ -f "${dir}/manifest-TESTSTAMP.txt" ]] || fail "TEST E missing manifest"
    pass "TEST E deletion inventory artifacts persisted"
}

# TEST F: protected paths never classified as expected/unexpected deletions
test_f_protected_paths() {
    local protected_path classification

    for protected_path in \
        '.env' \
        'vendor/autoload.php' \
        'storage/logs/laravel.log' \
        'storage/framework/cache/data/foo' \
        'storage/app/backups/pre-deploy/release.json' \
        'storage/app/private/cw-migration-manifest.json' \
        'public/build/manifest.json' \
        'node_modules/laravel/framework/package.json'; do
        classification="$(deploy_rsync_classify_deletion_path "$protected_path" HEAD "")"
        [[ "$classification" == "protected" ]] \
            || fail "TEST F ${protected_path} should be protected, got ${classification}"
    done

    pass "TEST F protected paths are never deletable application files"
}

test_a_no_unexpected_deletions
test_b_detect_production_only_file
test_c_explicit_approval_hook
test_d_backup_failure_blocks
test_e_inventory_persisted
test_f_protected_paths

# TEST G: local dry-run must not mutate destination (P-04-10-50 guard)
test_g_local_dry_run_is_non_mutating() {
    local src="${TMP}/test-g-src" dest="${TMP}/test-g-dest" log="${TMP}/test-g.log"

    mkdir -p "${src}/app" "${dest}/app/Extra"
    echo 'src' >"${src}/app/keep.php"
    echo 'dest-only' >"${dest}/app/Extra/OnlyOnDest.php"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"

    [[ -f "${dest}/app/Extra/OnlyOnDest.php" ]] \
        || fail "TEST G dry-run must not delete destination-only files"

    echo "$log" | grep -Fq 'app/Extra/OnlyOnDest.php' \
        || grep -Fq '*deleting app/Extra/OnlyOnDest.php' "$log" \
        || fail "TEST G dry-run log should mention would-delete path"

    pass "TEST G local dry-run is non-mutating"
}

test_g_local_dry_run_is_non_mutating

echo "All deploy-rsync-safety local tests passed."
