#!/usr/bin/env bash
#
# Regression tests for bin/backup-run.sh (local staging phase).
#
# Run: bash tests/scripts/backup-run.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/tests/scripts/fixtures/backup-mocks"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -x "$ROOT/bin/backup-run.sh" ]] || fail "backup-run.sh missing or not executable"
bash -n "$ROOT/bin/backup-run.sh" || fail "backup-run.sh syntax check failed"
pass "script syntax valid"

command -v php >/dev/null 2>&1 || fail "php is required for tests"

create_test_project() {
    local dir
    dir="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-test-XXXXXX")"
    mkdir -p "$dir/bin" "$dir/storage/app/google" "$dir/storage/app/private"
    cp "$ROOT/bin/backup-run.sh" "$dir/bin/backup-run.sh"
    chmod +x "$dir/bin/backup-run.sh"

    printf '%s\n' \
        'DB_CONNECTION=mysql' \
        'DB_HOST=127.0.0.1' \
        'DB_PORT=3306' \
        'DB_DATABASE=radium_desk_test' \
        'DB_USERNAME=backup_test_user' \
        'DB_PASSWORD=super_secret_password_do_not_print' \
        >"$dir/.env"

    printf '%s\n' '{"type":"service_account","project_id":"test"}' >"$dir/storage/app/google/service-account.json"
    printf '%s\n' '{"version":"4.0.39","build":"testbuild","deployed_at":"2026-08-18T00:00:00+05:30"}' >"$dir/storage/app/private/release.json"

    echo "$dir"
}

run_backup_in_project() {
    local project="$1"
    local staging="$2"
    local passphrase_file="$3"
    local extra_env="${4:-}"

    env \
        PATH="$FIXTURES:$PATH" \
        BACKUP_STAGING_ROOT="$staging" \
        BACKUP_ENCRYPTION_METHOD=gpg \
        BACKUP_ENCRYPTION_PASSPHRASE_FILE="$passphrase_file" \
        MYSQLDUMP_BIN=mysqldump \
        MYSQL_BIN=mysql \
        GPG_BIN=gpg \
        RSYNC_BIN=rsync \
        SSH_BIN=ssh \
        $extra_env \
        "$project/bin/backup-run.sh"
}

run_cloud_backup_in_project() {
    local project="$1"
    local staging="$2"
    local mock_remote="$3"
    local passphrase_file="$4"
    local extra_env="${5:-}"

    env \
        PATH="$FIXTURES:$PATH" \
        BACKUP_STAGING_ROOT="$staging" \
        BACKUP_ENCRYPTION_METHOD=gpg \
        BACKUP_ENCRYPTION_PASSPHRASE_FILE="$passphrase_file" \
        MYSQLDUMP_BIN=mysqldump \
        MYSQL_BIN=mysql \
        GPG_BIN=gpg \
        RSYNC_BIN=rsync \
        SSH_BIN=ssh \
        BACKUP_CLOUD_REMOTE_ROOT="$mock_remote" \
        BACKUP_CLOUD_SSH_HOST=mockhost \
        BACKUP_CLOUD_SSH_USER=mockuser \
        BACKUP_CLOUD_SSH_PORT=22 \
        $extra_env \
        "$project/bin/backup-run.sh"
}

# --- fail-closed without encryption config ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

set +e
OUTPUT="$(env PATH="$FIXTURES:$PATH" BACKUP_STAGING_ROOT="$STAGING" "$PROJECT/bin/backup-run.sh" 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected failure without encryption configuration"
echo "$OUTPUT" | grep -qi 'Encryption not configured' || fail "expected encryption configuration error"
pass "fail-closed when encryption is not configured"

rm -rf "$PROJECT" "$STAGING"
rm -f "$PASS_FILE"

# --- successful run ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

OUTPUT="$(run_backup_in_project "$PROJECT" "$STAGING" "$PASS_FILE" 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "backup-run.sh failed: $OUTPUT"

echo "$OUTPUT" | grep -F 'super_secret_password_do_not_print' >/dev/null && fail "DB password appeared in script output"
echo "$OUTPUT" | grep -F 'test-passphrase' >/dev/null && fail "encryption passphrase appeared in script output"
pass "no secrets printed in successful run output"

RUN_DIR="$(find "$STAGING/runs" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
[[ -n "$RUN_DIR" ]] || fail "expected a completed backup run directory"

[[ -f "$RUN_DIR/manifest.json" ]] || fail "manifest.json missing"
[[ -f "$RUN_DIR/database.sql.gz.gpg" ]] || fail "encrypted database artifact missing"
[[ -f "$RUN_DIR/secrets.tar.gz.gpg" ]] || fail "encrypted secrets artifact missing"

if find "$RUN_DIR" -name 'database.sql' -o -name 'database.sql.gz' -o -name 'secrets.tar.gz' | grep -q .; then
    fail "plaintext database or secrets artifacts remain after encryption"
fi
pass "no plaintext DB or secrets artifacts remain after encryption"

php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true);
    if (! is_array($manifest)) { fwrite(STDERR, "invalid manifest json\n"); exit(1); }
    if (($manifest["phase"] ?? "") !== "local_staging") { fwrite(STDERR, "wrong phase\n"); exit(1); }
    if (($manifest["database"]["name"] ?? "") !== "radium_desk_test") { fwrite(STDERR, "wrong db name\n"); exit(1); }
    if (count($manifest["artifacts"] ?? []) !== 2) { fwrite(STDERR, "expected 2 artifacts\n"); exit(1); }
    foreach ($manifest["artifacts"] as $artifact) {
        if (! isset($artifact["sha256"], $artifact["size_bytes"], $artifact["filename"])) {
            fwrite(STDERR, "artifact metadata incomplete\n"); exit(1);
        }
        if (strlen($artifact["sha256"]) !== 64) { fwrite(STDERR, "invalid sha256\n"); exit(1); }
    }
' "$RUN_DIR/manifest.json" || fail "manifest validation failed"
pass "manifest generation and structure"

WORK_LEFT="$(find "$STAGING/work" -mindepth 1 2>/dev/null | wc -l | tr -d " ")"
[[ "$WORK_LEFT" -eq 0 ]] || fail "temporary work directory was not cleaned after success"

rm -rf "$PROJECT" "$STAGING"
rm -f "$PASS_FILE"

# --- failure cleanup ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

set +e
OUTPUT="$(run_backup_in_project "$PROJECT" "$STAGING" "$PASS_FILE" "MYSQLDUMP_BIN=mysqldump-fail" 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected mysqldump failure to abort run"
echo "$OUTPUT" | grep -qi 'mysqldump failed' || fail "expected mysqldump failure message"

RUN_COUNT="$(find "$STAGING/runs" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l | tr -d " ")"
[[ "$RUN_COUNT" -eq 0 ]] || fail "failed run should not create a completed runs directory"

WORK_LEFT="$(find "$STAGING/work" -mindepth 1 2>/dev/null | wc -l | tr -d " ")"
[[ "$WORK_LEFT" -eq 0 ]] || fail "temporary work artifacts remain after failure"

pass "failure cleanup removes incomplete temporary artifacts"

rm -rf "$PROJECT" "$STAGING"
rm -f "$PASS_FILE"

# --- unrelated scripts unchanged (sanity grep) ---
grep -q 'schedule:run' "$ROOT/bin/schedule-run.sh" || fail "schedule-run.sh unexpectedly changed"
grep -q 'cpu-process-sample' "$ROOT/bin/cpu-process-sample.sh" || fail "cpu-process-sample.sh unexpectedly changed"
pass "existing unrelated bin scripts remain intact"

# --- Cloud upload disabled by default ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

OUTPUT="$(run_backup_in_project "$PROJECT" "$STAGING" "$PASS_FILE" 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "local backup failed when Cloud upload disabled: $OUTPUT"

RUN_DIR="$(find "$STAGING/runs" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    if (($m["phase"] ?? "") !== "local_staging") { fwrite(STDERR, "expected local_staging\n"); exit(1); }
    if (isset($m["upload"])) { fwrite(STDERR, "upload block should not exist\n"); exit(1); }
' "$RUN_DIR/manifest.json" || fail "Cloud upload disabled manifest check failed"
pass "Cloud upload disabled by default"

rm -rf "$PROJECT" "$STAGING"
rm -f "$PASS_FILE"

# --- Cloud upload enabled but missing configuration ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

set +e
OUTPUT="$(run_backup_in_project "$PROJECT" "$STAGING" "$PASS_FILE" "BACKUP_CLOUD_UPLOAD_ENABLED=true" 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected failure when Cloud upload enabled without host configuration"
echo "$OUTPUT" | grep -qi 'BACKUP_CLOUD_SSH_HOST is required' || fail "expected missing Cloud host error"
pass "missing Cloud configuration fails safely"

rm -rf "$PROJECT" "$STAGING"
rm -f "$PASS_FILE"

# --- successful mocked Cloud upload ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
MOCK_REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-cloud-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
RSYNC_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-rsync-log-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

OUTPUT="$(run_cloud_backup_in_project "$PROJECT" "$STAGING" "$MOCK_REMOTE" "$PASS_FILE" "BACKUP_CLOUD_UPLOAD_ENABLED=true RSYNC_MOCK_LOG=$RSYNC_LOG" 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "Cloud upload backup failed: $OUTPUT"

RUN_DIR="$(find "$STAGING/runs" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
BACKUP_ID="$(basename "$RUN_DIR")"
REMOTE_FINAL="$MOCK_REMOTE/${BACKUP_ID:0:4}/${BACKUP_ID:4:2}/${BACKUP_ID:6:2}/$BACKUP_ID"

[[ -f "$REMOTE_FINAL/upload-complete.json" ]] || fail "remote upload-complete marker missing"
[[ -f "$REMOTE_FINAL/manifest.json" ]] || fail "remote manifest missing"
[[ -f "$REMOTE_FINAL/database.sql.gz.gpg" ]] || fail "remote database artifact missing"
[[ ! -d "$MOCK_REMOTE/work/uploading-$BACKUP_ID" ]] || fail "remote temporary upload directory was not removed"

grep -F 'database.sql' "$RSYNC_LOG" | grep -v '.gpg' >/dev/null && fail "plaintext database artifact was uploaded"
grep -F 'secrets.tar.gz' "$RSYNC_LOG" | grep -v '.gpg' >/dev/null && fail "plaintext secrets artifact was uploaded"
grep -Fx 'manifest.json' "$RSYNC_LOG" >/dev/null || fail "manifest was not uploaded"
grep -Fx 'database.sql.gz.gpg' "$RSYNC_LOG" >/dev/null || fail "encrypted database was not uploaded"
grep -Fx 'secrets.tar.gz.gpg' "$RSYNC_LOG" >/dev/null || fail "encrypted secrets were not uploaded"

php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    if (($m["phase"] ?? "") !== "cloud_uploaded") { fwrite(STDERR, "expected cloud_uploaded phase\n"); exit(1); }
    if (($m["upload"]["status"] ?? "") !== "completed") { fwrite(STDERR, "upload status not completed\n"); exit(1); }
' "$RUN_DIR/manifest.json" || fail "manifest upload metadata missing after Cloud upload"
pass "successful mocked Cloud upload is recognized"

rm -rf "$PROJECT" "$STAGING" "$MOCK_REMOTE"
rm -f "$PASS_FILE" "$RSYNC_LOG"

# --- rsync failure preserves local backup ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
MOCK_REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-cloud-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

set +e
OUTPUT="$(run_cloud_backup_in_project "$PROJECT" "$STAGING" "$MOCK_REMOTE" "$PASS_FILE" "BACKUP_CLOUD_UPLOAD_ENABLED=true RSYNC_BIN=rsync-fail" 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected rsync failure to abort upload"
echo "$OUTPUT" | grep -qi 'rsync' || fail "expected rsync failure message"

RUN_DIR="$(find "$STAGING/runs" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
[[ -n "$RUN_DIR" ]] || fail "local backup should remain after upload failure"
[[ -f "$RUN_DIR/manifest.json" ]] || fail "local manifest missing after upload failure"
php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    if (($m["phase"] ?? "") !== "local_staging") { fwrite(STDERR, "phase should remain local_staging\n"); exit(1); }
' "$RUN_DIR/manifest.json" || fail "local manifest changed after upload failure"

REMOTE_FINAL_COUNT="$(find "$MOCK_REMOTE" -name upload-complete.json 2>/dev/null | wc -l | tr -d " ")"
[[ "$REMOTE_FINAL_COUNT" -eq 0 ]] || fail "incomplete remote upload should not have upload-complete marker"
pass "rsync failure returns non-zero and preserves local backup"

rm -rf "$PROJECT" "$STAGING" "$MOCK_REMOTE"
rm -f "$PASS_FILE"

# --- previous remote backups are never deleted ---
PROJECT="$(create_test_project)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-staging-XXXXXX")"
MOCK_REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-cloud-XXXXXX")"
PASS_FILE="$(mktemp "${TMPDIR:-/tmp}/radium-backup-pass-XXXXXX")"
printf 'test-passphrase' >"$PASS_FILE"
chmod 600 "$PASS_FILE"

mkdir -p "$MOCK_REMOTE/2020/01/01/previous-backup"
printf 'keep' >"$MOCK_REMOTE/2020/01/01/previous-backup/upload-complete.json"

OUTPUT="$(run_cloud_backup_in_project "$PROJECT" "$STAGING" "$MOCK_REMOTE" "$PASS_FILE" "BACKUP_CLOUD_UPLOAD_ENABLED=true" 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "Cloud upload failed during previous-backup retention test: $OUTPUT"

[[ -f "$MOCK_REMOTE/2020/01/01/previous-backup/upload-complete.json" ]] || fail "previous remote backup was deleted"
pass "previous remote backups are never deleted"

rm -rf "$PROJECT" "$STAGING" "$MOCK_REMOTE"
rm -f "$PASS_FILE"

echo "All backup-run checks passed."
