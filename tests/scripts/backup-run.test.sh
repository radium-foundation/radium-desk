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

echo "All backup-run checks passed."
