#!/usr/bin/env bash
#
# Regression tests: dry-run rsync commands must include --dry-run.
#
# Run: bash tests/scripts/deploy-rsync-dry-run-args.test.sh
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
bash -n "$SAFETY_LIB" || fail "syntax invalid"

SSH_HOST="127.0.0.1"
SSH_PORT="22"
SSH_USER="test"
REMOTE_PROJECT="/var/www/radium-desk"
PROJECT_ROOT="$ROOT"

# shellcheck source=tools/lib/deploy-rsync-safety.sh
source "$SAFETY_LIB"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

LAST_RSYNC_CMD=()
deploy_rsync_exec() {
    LAST_RSYNC_CMD=("$@")
    : >"${TMP}/rsync.stdout"
    return 0
}

assert_has_flag() {
    local flag="$1"
    local arg

    for arg in "${LAST_RSYNC_CMD[@]}"; do
        [[ "$arg" == "$flag" ]] && return 0
    done

    fail "expected rsync argv to contain ${flag}; got: ${LAST_RSYNC_CMD[*]}"
}

assert_no_live_rsync_without_dry_run() {
    local saw_rsync=0 saw_dry_run=0 arg

    for arg in "${LAST_RSYNC_CMD[@]}"; do
        [[ "$arg" == "rsync" ]] && saw_rsync=1
        [[ "$arg" == "--dry-run" ]] && saw_dry_run=1
    done

    [[ "$saw_rsync" -eq 1 ]] || fail "expected captured rsync command"
    [[ "$saw_dry_run" -eq 1 ]] || fail "live rsync would run without --dry-run"
}

# A. Application dry-run argv
deploy_rsync_run_application_dry_run "${TMP}/app.log"
assert_has_flag "--dry-run"
assert_has_flag "--delete"
pass "TEST A application dry-run includes --dry-run"

# B. Public-build dry-run argv
deploy_rsync_run_public_build_dry_run "${TMP}/build.log"
assert_has_flag "--dry-run"
assert_has_flag "--delete"
pass "TEST B public-build dry-run includes --dry-run"

# C. Neither dry-run function runs live rsync
assert_no_live_rsync_without_dry_run
pass "TEST C dry-run functions capture non-live rsync argv"

# D. --delete retained
[[ " ${LAST_RSYNC_CMD[*]} " == *" --delete "* ]] || fail "missing --delete"
pass "TEST D --delete retained for deletion analysis"

# E. Base initializer always includes both flags
deploy_rsync_init_dry_run_command
[[ "${DEPLOY_RSYNC_DRY_RUN_BASE[*]}" == "rsync -avzi --dry-run --delete" ]] \
    || fail "unexpected base dry-run argv: ${DEPLOY_RSYNC_DRY_RUN_BASE[*]}"
deploy_rsync_assert_dry_run_command "${DEPLOY_RSYNC_DRY_RUN_BASE[@]}" \
    || fail "assert helper rejected valid dry-run argv"
pass "TEST E centralized dry-run initializer is correct"

# F. assert helper rejects live-only command construction
if deploy_rsync_assert_dry_run_command rsync -avzi --delete 2>/dev/null; then
    fail "assert helper must reject rsync without --dry-run"
fi
pass "TEST F assert helper rejects missing --dry-run"

# G. P-04-10-50 incident regression — production-only path survives dry-run
test_p041050_incident_no_mutation() {
    local src="${TMP}/incident-src" dest="${TMP}/incident-dest" log="${TMP}/incident.log"

    mkdir -p "${src}/app" "${dest}/app/Overlay"
    echo 'release' >"${src}/app/keep.txt"
    echo 'production-only' >"${dest}/app/Overlay/OnlyOnProduction.php"
    echo 'also-keep' >"${dest}/app/keep.txt"

    # Use real rsync for this case to prove no filesystem mutation occurs.
    deploy_rsync_exec() { "$@"; }

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"

    [[ -f "${dest}/app/Overlay/OnlyOnProduction.php" ]] \
        || fail "P-04-10-50 regression: production-only file was deleted during dry-run"
    [[ -f "${dest}/app/keep.txt" ]] || fail "P-04-10-50 regression: existing file removed"

    grep -Fq 'app/Overlay/OnlyOnProduction.php' "$log" \
        || fail "P-04-10-50 regression: dry-run log missing deletion inventory"

    deploy_rsync_exec() { LAST_RSYNC_CMD=("$@"); :; }
    pass "TEST G P-04-10-50 incident regression — inventory without mutation"
}

test_p041050_incident_no_mutation

echo "All deploy-rsync-dry-run-args tests passed."
