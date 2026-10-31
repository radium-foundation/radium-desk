#!/usr/bin/env bash
#
# Vendor/node_modules must not appear in rsync --delete dry-run output (P-01-01).
# Includes macOS openrsync remote dry-run probe when DEPLOY_RSYNC_PROBE_SSH=1.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SAFETY_LIB="$ROOT/tools/lib/deploy-rsync-safety.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

print_warning() { :; }
print_error() { echo "ERROR: $*" >&2; }
print_success() { :; }

SSH_HOST="${SSH_HOST:-127.0.0.1}"
SSH_PORT="${SSH_PORT:-22}"
SSH_USER="${SSH_USER:-test}"
REMOTE_PROJECT="${REMOTE_PROJECT:-/var/www/radium-desk}"
PROJECT_ROOT="$ROOT"

# shellcheck source=tools/lib/deploy-rsync-safety.sh
source "$SAFETY_LIB"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# TEST H: destination vendor/ must not be scheduled for deletion in local dry-run
test_h_local_vendor_protected() {
    local src="${TMP}/h-src" dest="${TMP}/h-dest" log="${TMP}/h.log"
    mkdir -p "${src}/app" "${dest}/app" "${dest}/vendor/deep"
    echo 'app' >"${src}/app/keep.php"
    echo 'app' >"${dest}/app/keep.php"
    echo 'v' >"${dest}/vendor/deep/autoload.php"

    deploy_rsync_local_run_dry_run "$src" "$dest" "$log"
    local ec=$?
    if [[ $ec -eq 23 ]]; then
        fail "TEST H local dry-run must not exit 23 (got 23)"
    fi
    if grep -q '^\*deleting   vendor/' "$log"; then
        fail "TEST H vendor/ must not appear in deletion dry-run output"
    fi
    pass "TEST H local vendor tree protected from delete dry-run"
}

# TEST I: rsync non-zero exit (e.g. 23) must fail dry-run helper — never treat as success
test_i_dry_run_rejects_exit_23() {
    local log="${TMP}/i.log"

    deploy_rsync_use_kvm_linux_application_dry_run() { return 1; }
    deploy_rsync_exec() { return 23; }

    if deploy_rsync_run_application_dry_run "$log"; then
        fail "TEST I application dry-run must fail when rsync exits 23"
    fi
    pass "TEST I rsync exit 23 fails application dry-run (not treated as success)"
}

# TEST J: unexpected deletion still detected
test_j_unexpected_still_detected() {
    deploy_rsync_use_kvm_linux_application_dry_run() { return 1; }
    deploy_rsync_exec() {
        printf '%s\n' '*deleting app/ProductionOnlyOverlay.php'
        return 0
    }

    local log="${TMP}/j.log"
    deploy_rsync_run_application_dry_run "$log" || true
    local paths
    paths="$(deploy_rsync_parse_deletion_paths "$log")"
    echo "$paths" | grep -Fq 'app/ProductionOnlyOverlay.php' \
        || fail "TEST J must still parse unexpected deletion paths"
    local classification
    classification="$(deploy_rsync_classify_deletion_path "app/ProductionOnlyOverlay.php" HEAD "")"
    [[ "$classification" == "unexpected" ]] || fail "TEST J expected unexpected, got ${classification}"
    pass "TEST J unexpected deletion classification unchanged"
}

test_h_local_vendor_protected
test_i_dry_run_rejects_exit_23
test_j_unexpected_still_detected

if [[ "${DEPLOY_RSYNC_PROBE_SSH:-0}" == "1" ]]; then
    SSH_HOST="187.127.129.16"
    SSH_USER="ravi"
    REMOTE_PROJECT="/var/www/radium-desk"
    PROJECT_ROOT="$ROOT"
    # shellcheck disable=SC1090
    source "$SAFETY_LIB"
    LOG="${TMP}/probe.log"
    set +e
    deploy_rsync_run_application_dry_run "$LOG"
    EC=$?
    set -e
    VD="$(grep -c '^\*deleting   vendor/' "$LOG" || true)"
    if [[ "$EC" -eq 23 ]]; then
        fail "PROBE SSH dry-run exited 23 (macOS openrsync + remote vendor); run deploy preflight from Linux"
    fi
    if [[ "$VD" -gt 0 ]]; then
        fail "PROBE SSH dry-run scheduled ${VD} vendor deletions"
    fi
    pass "PROBE SSH application dry-run exit=${EC}, vendor deletions=${VD}"
else
    echo "SKIP: DEPLOY_RSYNC_PROBE_SSH=1 not set (no production SSH probe in default test run)"
fi

echo "All deploy-rsync-vendor-protect tests passed."
