#!/usr/bin/env bash
#
# Regression tests for bin/backup-schedule.sh
#
# Run: bash tests/scripts/backup-schedule.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/tests/scripts/fixtures/backup-schedule-mocks"
SETFACL_MOCKS="$ROOT/tests/scripts/fixtures/backup-mocks"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

assert_status_setfacl_traversal_chain() {
    local log="$1"
    local staging="$2"
    local status_path="$3"

    grep -F -- "-m u:ravi:--x,m:--x ${staging}" "$log" >/dev/null \
        || fail "setfacl missing staging root traversal ACL/mask"
    grep -F -- "-m u:ravi:r-x,m:r-x ${staging}/runs" "$log" >/dev/null \
        || fail "setfacl missing runs directory traversal ACL/mask"
    grep -F -- "-m u:ravi:r,m:r ${status_path}" "$log" >/dev/null \
        || fail "setfacl missing status read ACL/mask"
    grep -F -- "-d -m u:ravi:--x ${staging}/runs" "$log" >/dev/null \
        || fail "setfacl missing runs default traversal ACL"
}

[[ -x "$ROOT/bin/backup-schedule.sh" ]] || fail "backup-schedule.sh missing or not executable"
bash -n "$ROOT/bin/backup-schedule.sh" || fail "backup-schedule.sh syntax check failed"
pass "script syntax valid"

command -v php >/dev/null 2>&1 || fail "php is required for tests"

FLOCK_MOCK_DIR="$FIXTURES"

grep -q 'backup-schedule' "$ROOT/bin/backup-run.sh" && fail "backup-run.sh must not invoke backup-schedule"
pass "backup-run.sh left unchanged"

mkdir -p "$FIXTURES"

write_mock_run() {
    local name="$1"
    local body="$2"
    local path="$FIXTURES/$name"

    cat >"$path" <<EOF
#!/usr/bin/env bash
set -euo pipefail
$body
EOF
    chmod +x "$path"
}

write_mock_run success '
echo "backup-run.sh: starting backup 20260822T023001Z (database=radium_desk, encryption=gpg)" >&2
echo "backup-run.sh: local backup completed: /var/backups/radium-desk/runs/20260822T023001Z" >&2
echo "backup-run.sh: starting Cloud upload for 20260822T023001Z (host=example, remote=/remote/path)" >&2
echo "backup-run.sh: backup completed with Cloud upload: /remote/path/20260822T023001Z" >&2
exit 0
'

write_mock_run local-failure '
echo "backup-run.sh: starting backup 20260822T023001Z (database=radium_desk, encryption=gpg)" >&2
echo "backup-run.sh: ERROR: mysqldump failed" >&2
exit 1
'

write_mock_run cloud-failure '
echo "backup-run.sh: starting backup 20260822T023001Z (database=radium_desk, encryption=gpg)" >&2
echo "backup-run.sh: local backup completed: /var/backups/radium-desk/runs/20260822T023001Z" >&2
echo "backup-run.sh: starting Cloud upload for 20260822T023001Z (host=example, remote=/remote/path)" >&2
echo "backup-run.sh: ERROR: rsync to remote staging directory failed" >&2
exit 1
'

run_schedule() {
    local staging="$1"
    local status_path="$2"
    local lock_file="$3"
    local mock_script="$4"
    shift 4
    local -a extra_env=("$@")

    env \
        BACKUP_STAGING_ROOT="$staging" \
        BACKUP_STATUS_PATH="$status_path" \
        BACKUP_LOCK_FILE="$lock_file" \
        BACKUP_RUN_SCRIPT="$mock_script" \
        BACKUP_CLOUD_UPLOAD_ENABLED=true \
        BACKUP_MANIFEST_ACL_ENABLED="${BACKUP_MANIFEST_ACL_ENABLED:-false}" \
        FLOCK_BIN="${FLOCK_BIN:-}" \
        "${extra_env[@]+"${extra_env[@]}"}" \
        "$ROOT/bin/backup-schedule.sh"
}

validate_status() {
    local status_path="$1"
    local expected_outcome="$2"
    local expected_exit_code="${3:-}"

    if [[ -n "$expected_exit_code" ]]; then
        php "$ROOT/tests/scripts/validate-backup-schedule-status.php" "$status_path" "$expected_outcome" "$expected_exit_code" \
            || fail "status JSON validation failed for outcome=${expected_outcome} exit_code=${expected_exit_code}"
    else
        php "$ROOT/tests/scripts/validate-backup-schedule-status.php" "$status_path" "$expected_outcome" \
            || fail "status JSON validation failed for outcome=${expected_outcome}"
    fi
}

STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-schedule-staging-XXXXXX")"
STATUS="${STAGING}/last-run-status.json"
LOCK="${STAGING}/backup.lock"
trap 'rm -rf "$STAGING"' EXIT

set +e
run_schedule "$STAGING" "$STATUS" "$LOCK" "$FIXTURES/success"
SUCCESS_STATUS=$?
set -e
[[ "$SUCCESS_STATUS" -eq 0 ]] || fail "success should exit zero (got ${SUCCESS_STATUS})"
[[ -f "$STATUS" ]] || fail "success status file missing"
validate_status "$STATUS" "success" 0
grep -q '"phase": "cloud_uploaded"' "$STATUS" || fail "expected cloud_uploaded phase"
pass "records successful cloud backup with exit_code=0"

rm -f "$STATUS"
set +e
run_schedule "$STAGING" "$STATUS" "$LOCK" "$FIXTURES/local-failure"
LOCAL_STATUS=$?
set -e
[[ "$LOCAL_STATUS" -ne 0 ]] || fail "local failure should exit non-zero"
validate_status "$STATUS" "local_failure" "$LOCAL_STATUS"
pass "records local staging failure with matching exit_code"

rm -f "$STATUS"
set +e
run_schedule "$STAGING" "$STATUS" "$LOCK" "$FIXTURES/cloud-failure"
CLOUD_STATUS=$?
set -e
[[ "$CLOUD_STATUS" -ne 0 ]] || fail "cloud failure should exit non-zero"
validate_status "$STATUS" "cloud_upload_failure" "$CLOUD_STATUS"
pass "records cloud upload failure with matching exit_code"

rm -f "$STATUS"
MOCK_STATE="${STAGING}/flock-held"
printf 'held\n' >"$MOCK_STATE"
set +e
run_schedule "$STAGING" "$STATUS" "$LOCK" "$FIXTURES/success" \
    FLOCK_BIN="$FLOCK_MOCK_DIR/flock" \
    FLOCK_MOCK_STATE_FILE="$MOCK_STATE"
OVERLAP_STATUS=$?
set -e
rm -f "$MOCK_STATE"
[[ "$OVERLAP_STATUS" -eq 0 ]] || fail "lock overlap should exit zero"
validate_status "$STATUS" "lock_overlap" 0
pass "records lock overlap without running backup (exit_code=0)"

grep -Ei 'passphrase|password|api[_-]?key|ssh|secret|sha256|\.gpg' "$STATUS" >/dev/null \
    && fail "status JSON must not contain sensitive fields"
pass "status JSON excludes sensitive fields"

STAT_MODE="$(stat -f '%OLp' "$STATUS" 2>/dev/null || stat -c '%a' "$STATUS")"
[[ "$STAT_MODE" == "600" ]] || fail "status file must be mode 600 (got ${STAT_MODE})"
pass "status file mode is 600"

ACL_STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-schedule-acl-staging-XXXXXX")"
ACL_STATUS="${ACL_STAGING}/last-run-status.json"
ACL_LOCK="${ACL_STAGING}/backup.lock"
SETFACL_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-schedule-setfacl-XXXXXX")"
ACL_OUTPUT="$(env \
    BACKUP_STAGING_ROOT="$ACL_STAGING" \
    BACKUP_STATUS_PATH="$ACL_STATUS" \
    BACKUP_LOCK_FILE="$ACL_LOCK" \
    BACKUP_RUN_SCRIPT="$FIXTURES/success" \
    BACKUP_CLOUD_UPLOAD_ENABLED=true \
    BACKUP_MANIFEST_ACL_ENABLED=true \
    PATH="$SETFACL_MOCKS:$PATH" \
    SETFACL_MOCK_LOG="$SETFACL_LOG" \
    "$ROOT/bin/backup-schedule.sh" 2>&1)"
echo "$ACL_OUTPUT" | grep -F 'status read ACL applied for ravi' >/dev/null \
    || fail "status ACL success was not logged: $ACL_OUTPUT"
grep -q '"watchdog_accessible": true' "$ACL_STATUS" || fail "expected watchdog_accessible true when ACL applied"
assert_status_setfacl_traversal_chain "$SETFACL_LOG" "$ACL_STAGING" "$ACL_STATUS"
pass "applies staging traversal ACLs and status read ACL"

rm -f "$ACL_STATUS"
ACL_FAIL_OUTPUT="$(env \
    BACKUP_STAGING_ROOT="$ACL_STAGING" \
    BACKUP_STATUS_PATH="$ACL_STATUS" \
    BACKUP_LOCK_FILE="$ACL_LOCK" \
    BACKUP_RUN_SCRIPT="$FIXTURES/success" \
    BACKUP_CLOUD_UPLOAD_ENABLED=true \
    BACKUP_MANIFEST_ACL_ENABLED=true \
    PATH="$SETFACL_MOCKS:$PATH" \
    SETFACL_MOCK_FAIL=1 \
    "$ROOT/bin/backup-schedule.sh" 2>&1)" || true
echo "$ACL_FAIL_OUTPUT" | grep -F 'watchdog alerting unavailable' >/dev/null \
    || fail "ACL failure must log watchdog alerting unavailable"
grep -q '"watchdog_accessible": false' "$ACL_STATUS" || fail "expected watchdog_accessible false when ACL fails"
pass "detects ACL failure without claiming alerting is available"

rm -f "$SETFACL_LOG"
rm -rf "$ACL_STAGING"

MOCK_STATE="${STAGING}/flock-held-outer"
printf 'held\n' >"$MOCK_STATE"
run_schedule "$STAGING" "$STATUS" "$LOCK" "$FIXTURES/success" \
    FLOCK_BIN="$FLOCK_MOCK_DIR/flock" \
    FLOCK_MOCK_STATE_FILE="$MOCK_STATE" \
    BACKUP_SCHEDULE_SKIP_LOCK=true
grep -q '"outcome": "success"' "$STATUS" || fail "skip-lock should run backup under outer flock"
rm -f "$MOCK_STATE"
pass "outer flock compatibility via BACKUP_SCHEDULE_SKIP_LOCK"

echo "All backup-schedule tests passed."
