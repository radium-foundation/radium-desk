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

# TEST F: persistent/runtime paths are protected; public/build churn is expected on deploy
test_f_protected_paths() {
    local protected_path classification

    for protected_path in \
        '.env' \
        '.env.bak-p-12-10-026-20261013T144016Z' \
        'vendor/autoload.php' \
        'storage/logs/laravel.log' \
        'storage/framework/cache/data/foo' \
        'storage/app/backups/pre-deploy/release.json' \
        'storage/app/private/release.json' \
        'storage/app/private/cw-migration-manifest.json' \
        'node_modules/laravel/framework/package.json'; do
        classification="$(deploy_rsync_classify_deletion_path "$protected_path" HEAD "")"
        [[ "$classification" == "protected" ]] \
            || fail "TEST F ${protected_path} should be protected, got ${classification}"
    done

    classification="$(deploy_rsync_classify_deletion_path "public/build/manifest.json" HEAD "")"
    [[ "$classification" == "expected" ]] \
        || fail "TEST F public/build/manifest.json should be expected (rebuilt asset tree), got ${classification}"

    pass "TEST F protected paths and public/build deploy semantics"
}

# TEST H: aligned trees with local release.json snapshot must not delete runtime identity
test_h_release_json_not_deleted_when_present_in_source() {
    local src="${TMP}/test-h-src" dest="${TMP}/test-h-dest" log="${TMP}/test-h.log"
    local path classification

    init_fixture_source "$src"
    echo '{"version":"4.1.61","build":"candidate"}' >"${src}/storage/app/private/release.json"
    mkdir -p "$dest"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"
    paths="$(deploy_rsync_parse_deletion_paths "$log")"
  if echo "$paths" | grep -q 'storage/app/private/release.json'; then
        fail "TEST H release.json must not appear in deletion paths when source carries snapshot"
    fi

    classification="$(deploy_rsync_classify_deletion_path "storage/app/private/release.json" HEAD "")"
    [[ "$classification" == "protected" ]] \
        || fail "TEST H release.json must classify as protected, got ${classification}"

    pass "TEST H release.json snapshot parity and protected classification"
}

test_a_no_unexpected_deletions
test_b_detect_production_only_file
test_c_explicit_approval_hook
test_d_backup_failure_blocks
test_e_inventory_persisted
test_f_protected_paths
test_h_release_json_not_deleted_when_present_in_source

# TEST G: local dry-run must not mutate destination (P-04-10-50 guard)
test_g_local_dry_run_is_non_mutating() {
    local src="${TMP}/test-g-src" dest="${TMP}/test-g-dest" log="${TMP}/test-g.log"

    mkdir -p "${src}/app" "${dest}/app/Extra"
    echo 'src' >"${src}/app/keep.php"
    echo 'dest-only' >"${dest}/app/Extra/OnlyOnDest.php"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"

    [[ -f "${dest}/app/Extra/OnlyOnDest.php" ]] \
        || fail "TEST G dry-run must not delete destination-only files"

    grep -Fq 'app/Extra/OnlyOnDest.php' "$log" \
        || grep -Fq '*deleting app/Extra/OnlyOnDest.php' "$log" \
        || grep -Fq '*deleting   app/Extra/OnlyOnDest.php' "$log" \
        || fail "TEST G dry-run log should mention would-delete path"

    pass "TEST G local dry-run is non-mutating"
}

test_g_local_dry_run_is_non_mutating

# TEST J (P-11-01): anchored excludes prevent vendor symlinks and tmp/ entering the candidate tree.
test_j_anchored_vendor_tmp_excludes() {
    local excludes
    excludes="$(deploy_kvm_rsync_application_filters | awk '/^--exclude$/ {getline; print}')"
    echo "$excludes" | grep -Fx '/vendor' >/dev/null \
        || fail "TEST J must end with anchored --exclude /vendor"
    echo "$excludes" | grep -Fx '/tmp/' >/dev/null \
        || fail "TEST J must end with anchored --exclude /tmp/"
    pass "TEST J anchored /vendor and /tmp/ rsync excludes"
}

test_k_vendor_symlink_not_staged() {
    local src="${TMP}/test-k-src" dest="${TMP}/test-k-stg" log="${TMP}/test-k.log"
    mkdir -p "${src}/app"
    echo 'x' >"${src}/app/keep.php"
    ln -s /tmp/nonexistent-vendor "${src}/vendor"
    mkdir -p "$dest"
    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"
    [[ -e "${dest}/vendor" ]] && fail "TEST K must not copy excluded vendor symlink"
    pass "TEST K vendor symlink excluded from local candidate tree"
}

test_j_anchored_vendor_tmp_excludes
test_k_vendor_symlink_not_staged

# TEST H: KVM Linux staging cleanup RETURN trap must not trip nounset (P-08-07).
test_h_kvm_staging_return_trap_under_nounset() {
    local log="${TMP}/test-h.log" call found_rm=0

    SSH_CALLS=()
    ssh_exec() {
        SSH_CALLS+=("$*")
        return 0
    }
    deploy_rsync_stage_release_candidate_on_remote() { return 0; }
    deploy_kvm_rsync_application_filters() { echo '--exclude=.git/'; }

    DEPLOY_RSYNC_STAMP='TESTSTAMP'
    deploy_rsync_run_application_dry_run_on_kvm_linux "$log"

    for call in "${SSH_CALLS[@]}"; do
        [[ "$call" == rm\ -rf\ \'/tmp/desk-rsync-dryrun-TESTSTAMP\' ]] && found_rm=1
    done
    [[ "$found_rm" -eq 1 ]] || fail "TEST H expected staging cleanup via RETURN trap, got: ${SSH_CALLS[*]}"
    pass "TEST H KVM staging RETURN trap under nounset"
}

test_i_kvm_remote_rsync_filter_argv_quoting() {
    local -a ssh_capture=() filter_line lc_script

    ssh_exec() {
        ssh_capture=("$@")
        return 0
    }

    deploy_rsync_ssh_exec_argv rsync --dry-run --filter 'P vendor/**' --filter 'P node_modules/**' /tmp/a/ /tmp/b/

    [[ "${ssh_capture[0]}" == "bash" ]] || fail "TEST I expected bash wrapper, got: ${ssh_capture[*]}"
    [[ "${ssh_capture[1]}" == "-lc" ]] || fail "TEST I expected -lc, got: ${ssh_capture[*]}"
    lc_script="${ssh_capture[2]}"
    [[ "$lc_script" == *'--filter'* ]] || fail "TEST I missing --filter in remote script"
    [[ "$lc_script" == *vendor* && "$lc_script" == *node_modules* ]] \
        || fail "TEST I protect filter rules must stay quoted in remote script, got: ${lc_script}"

    pass "TEST I KVM remote rsync argv uses bash -lc quoting"
}

test_h_kvm_staging_return_trap_under_nounset
test_i_kvm_remote_rsync_filter_argv_quoting

echo "All deploy-rsync-safety local tests passed."
