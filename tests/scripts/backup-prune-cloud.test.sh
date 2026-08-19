#!/usr/bin/env bash
#
# Regression tests for bin/backup-prune-cloud.sh
#
# Run: bash tests/scripts/backup-prune-cloud.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/tests/scripts/fixtures/backup-mocks"
AS_OF="20260819T120000Z"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -x "$ROOT/bin/backup-prune-cloud.sh" ]] || fail "backup-prune-cloud.sh missing or not executable"
bash -n "$ROOT/bin/backup-prune-cloud.sh" || fail "backup-prune-cloud.sh syntax check failed"
pass "script syntax valid"

command -v php >/dev/null 2>&1 || fail "php is required for tests"
[[ -x "$FIXTURES/ssh" ]] || fail "ssh mock missing"

grep -q 'backup-prune-cloud' "$ROOT/bin/backup-run.sh" && fail "backup-run.sh must not invoke prune"
grep -q 'upload_run_to_cloud' "$ROOT/bin/backup-run.sh" || fail "backup-run.sh unexpectedly changed"
pass "backup-run.sh left unchanged"

backup_dir() {
    local remote_root="$1"
    local backup_id="$2"
    printf '%s/%s/%s/%s/%s' \
        "$remote_root" \
        "${backup_id:0:4}" \
        "${backup_id:4:2}" \
        "${backup_id:6:2}" \
        "$backup_id"
}

create_completed_backup() {
    local remote_root="$1"
    local backup_id="$2"
    local dir
    dir="$(backup_dir "$remote_root" "$backup_id")"
    mkdir -p "$dir"
    printf 'db-%s' "$backup_id" >"${dir}/database.sql.gz.gpg"
    printf 'sec-%s' "$backup_id" >"${dir}/secrets.tar.gz.gpg"
    php -r '
        $id = $argv[1];
        $dir = $argv[2];
        $created = substr($id, 0, 4) . "-" . substr($id, 4, 2) . "-" . substr($id, 6, 2)
            . "T" . substr($id, 9, 2) . ":" . substr($id, 11, 2) . ":" . substr($id, 13, 2) . "Z";
        $manifest = [
            "backup_id" => $id,
            "created_at" => $created,
            "phase" => "local_staging",
            "artifacts" => [
                [
                    "role" => "database",
                    "filename" => "database.sql.gz.gpg",
                    "size_bytes" => filesize($dir . "/database.sql.gz.gpg"),
                    "sha256" => hash_file("sha256", $dir . "/database.sql.gz.gpg"),
                    "encryption" => "gpg-aes256-symmetric",
                ],
                [
                    "role" => "secrets",
                    "filename" => "secrets.tar.gz.gpg",
                    "size_bytes" => filesize($dir . "/secrets.tar.gz.gpg"),
                    "sha256" => hash_file("sha256", $dir . "/secrets.tar.gz.gpg"),
                    "encryption" => "gpg-aes256-symmetric",
                ],
            ],
        ];
        file_put_contents($dir . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $marker = [
            "backup_id" => $id,
            "uploaded_at" => $created,
            "remote_path" => $dir,
            "manifest_sha256" => hash_file("sha256", $dir . "/manifest.json"),
            "status" => "completed",
        ];
        file_put_contents($dir . "/upload-complete.json", json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$backup_id" "$dir"
}

create_incomplete_without_marker() {
    local dir
    dir="$(backup_dir "$1" "$2")"
    mkdir -p "$dir"
    printf 'db' >"${dir}/database.sql.gz.gpg"
    printf 'sec' >"${dir}/secrets.tar.gz.gpg"
    printf '{}' >"${dir}/manifest.json"
}

run_prune() {
    local remote_root="$1"
    shift

    env \
        PATH="$FIXTURES:$PATH" \
        SSH_BIN=ssh \
        BACKUP_CLOUD_REMOTE_ROOT="$remote_root" \
        BACKUP_CLOUD_SSH_HOST=mockhost \
        BACKUP_CLOUD_SSH_USER=mockuser \
        BACKUP_CLOUD_SSH_PORT=22 \
        BACKUP_PRUNE_AS_OF="$AS_OF" \
        "$ROOT/bin/backup-prune-cloud.sh" \
        "$@"
}

snapshot_tree() {
    find "$1" -print | sort
}

assert_keep() {
    local output="$1"
    local backup_id="$2"
    echo "$output" | grep -E "KEEP[[:space:]]+${backup_id}" >/dev/null \
        || fail "expected KEEP ${backup_id}"
}

assert_delete() {
    local output="$1"
    local backup_id="$2"
    echo "$output" | grep -E "DELETE[[:space:]]+${backup_id}" >/dev/null \
        || fail "expected DELETE ${backup_id}"
}

assert_skip_reason() {
    local output="$1"
    local needle="$2"
    echo "$output" | grep -F "SKIP" | grep -F "$needle" >/dev/null \
        || fail "expected SKIP matching ${needle}"
}

assert_exists() {
    [[ -e "$1" ]] || fail "expected to exist: $1"
}

assert_missing() {
    [[ ! -e "$1" ]] || fail "expected to be deleted: $1"
}

REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-prune-XXXXXX")"

# --- missing Cloud configuration ---
set +e
OUTPUT="$(env PATH="$FIXTURES:$PATH" SSH_BIN=ssh "$ROOT/bin/backup-prune-cloud.sh" --dry-run 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected failure without Cloud configuration"
echo "$OUTPUT" | grep -qi 'BACKUP_CLOUD_SSH_HOST is required' || fail "expected missing Cloud host error"
pass "missing Cloud configuration fails safely"

# --- both flags rejected ---
set +e
OUTPUT="$(run_prune "$REMOTE" --dry-run --execute 2>&1)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected failure when both --dry-run and --execute are passed"
echo "$OUTPUT" | grep -qi 'not both' || fail "expected both-flags error"
pass "both --dry-run and --execute are rejected"

# Policy fixture
create_completed_backup "$REMOTE" "20260819T180000Z"
create_completed_backup "$REMOTE" "20260819T080000Z"
create_completed_backup "$REMOTE" "20260818T080000Z"
create_completed_backup "$REMOTE" "20260818T200000Z"
create_completed_backup "$REMOTE" "20260812T120000Z"
create_completed_backup "$REMOTE" "20260811T080000Z"
create_completed_backup "$REMOTE" "20260811T200000Z"
create_completed_backup "$REMOTE" "20260804T080000Z"
create_completed_backup "$REMOTE" "20260804T200000Z"
create_completed_backup "$REMOTE" "20260720T120000Z"
create_completed_backup "$REMOTE" "20260719T120000Z"
create_completed_backup "$REMOTE" "20260718T120000Z"
create_completed_backup "$REMOTE" "20260706T120000Z"
create_completed_backup "$REMOTE" "20260705T080000Z"
create_completed_backup "$REMOTE" "20260705T200000Z"
create_completed_backup "$REMOTE" "20260524T180000Z"
create_completed_backup "$REMOTE" "20260521T120000Z"
create_completed_backup "$REMOTE" "20260520T120000Z"
create_completed_backup "$REMOTE" "20260401T120000Z"

create_incomplete_without_marker "$REMOTE" "20260315T120000Z"

php -r '
    $dir = $argv[1];
    $raw = json_decode(file_get_contents($dir . "/upload-complete.json"), true);
    $raw["status"] = "failed";
    file_put_contents($dir . "/upload-complete.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$(backup_dir "$REMOTE" "20260812T120000Z")"
# Recreate a within-7d completed backup after using 20260812 for status!=completed.
# Keep 20260812 as the failed-status fixture; add 20260813 as a valid day-6 backup.
create_completed_backup "$REMOTE" "20260813T120000Z"

BAD_SHA_ID="20260810T120000Z"
create_completed_backup "$REMOTE" "$BAD_SHA_ID"
php -r '
    $dir = $argv[1];
    $raw = json_decode(file_get_contents($dir . "/upload-complete.json"), true);
    $raw["manifest_sha256"] = str_repeat("0", 64);
    file_put_contents($dir . "/upload-complete.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$(backup_dir "$REMOTE" "$BAD_SHA_ID")"

PLAIN_ID="20260809T120000Z"
create_completed_backup "$REMOTE" "$PLAIN_ID"
printf 'plaintext' >"$(backup_dir "$REMOTE" "$PLAIN_ID")/database.sql.gz"

WRONG_PATH_ID="20260808T120000Z"
create_completed_backup "$REMOTE" "$WRONG_PATH_ID"
php -r '
    $dir = $argv[1];
    $raw = json_decode(file_get_contents($dir . "/upload-complete.json"), true);
    $raw["remote_path"] = "/tmp/outside-radium-backup";
    file_put_contents($dir . "/upload-complete.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$(backup_dir "$REMOTE" "$WRONG_PATH_ID")"

mkdir -p "$REMOTE/2026/08/07"
ln -s "$(backup_dir "$REMOTE" "20260819T180000Z")" "$REMOTE/2026/08/07/20260807T120000Z"

mkdir -p "$REMOTE/work/uploading-20260101T000000Z"
printf 'partial' >"$REMOTE/work/uploading-20260101T000000Z/database.sql.gz.gpg"

mkdir -p "$REMOTE/2026/08/01/not-a-backup-id"
printf 'x' >"$REMOTE/2026/08/01/not-a-backup-id/upload-complete.json"

mkdir -p "$REMOTE/2026/08/02/20260803T120000Z"
printf 'mismatch' >"$REMOTE/2026/08/02/20260803T120000Z/upload-complete.json"

BEFORE="$(snapshot_tree "$REMOTE")"

# --- default is dry-run ---
OUTPUT="$(run_prune "$REMOTE" 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "default dry-run failed: $OUTPUT"
echo "$OUTPUT" | grep -qi 'dry-run' || fail "default mode should be dry-run"
AFTER="$(snapshot_tree "$REMOTE")"
[[ "$BEFORE" == "$AFTER" ]] || fail "dry-run without flags mutated the remote tree"
pass "default mode is dry-run and does not delete"

# --- explicit dry-run policy ---
OUTPUT="$(run_prune "$REMOTE" --dry-run 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "explicit dry-run failed: $OUTPUT"
AFTER="$(snapshot_tree "$REMOTE")"
[[ "$BEFORE" == "$AFTER" ]] || fail "explicit dry-run mutated the remote tree"
pass "explicit --dry-run does not delete"

assert_keep "$OUTPUT" "20260819T180000Z"
assert_keep "$OUTPUT" "20260819T080000Z"
assert_keep "$OUTPUT" "20260818T080000Z"
assert_keep "$OUTPUT" "20260818T200000Z"
assert_keep "$OUTPUT" "20260813T120000Z"
pass "0-7 day completed backups are kept, including both same-day runs"

assert_keep "$OUTPUT" "20260811T200000Z"
assert_delete "$OUTPUT" "20260811T080000Z"
assert_keep "$OUTPUT" "20260804T200000Z"
assert_delete "$OUTPUT" "20260804T080000Z"
assert_keep "$OUTPUT" "20260720T120000Z"
pass "8-30 day window keeps latest successful backup per UTC day"

assert_keep "$OUTPUT" "20260719T120000Z"
assert_keep "$OUTPUT" "20260705T200000Z"
assert_delete "$OUTPUT" "20260705T080000Z"
assert_delete "$OUTPUT" "20260706T120000Z"
assert_delete "$OUTPUT" "20260718T120000Z"
assert_keep "$OUTPUT" "20260524T180000Z"
assert_delete "$OUTPUT" "20260521T120000Z"
pass "31-90 day window keeps latest successful Sunday per UTC week"

assert_delete "$OUTPUT" "20260520T120000Z"
assert_delete "$OUTPUT" "20260401T120000Z"
echo "$OUTPUT" | grep -E "KEEP[[:space:]]+20260819T180000Z" | grep -q newest \
    || fail "newest completed backup should be kept with reason newest"
pass ">90 day completed backups are delete candidates except the newest"

assert_skip_reason "$OUTPUT" "missing-upload-complete"
assert_skip_reason "$OUTPUT" "status is not completed"
assert_skip_reason "$OUTPUT" "manifest sha256 mismatch"
assert_skip_reason "$OUTPUT" "plaintext-artifact"
assert_skip_reason "$OUTPUT" "remote_path mismatch"
assert_skip_reason "$OUTPUT" "symlink-dir"
assert_skip_reason "$OUTPUT" "malformed-path"
assert_skip_reason "$OUTPUT" "date-path-mismatch"
pass "incomplete, malformed, and unsafe backups are skipped"

echo "$OUTPUT" | grep -F "uploading-20260101T000000Z" >/dev/null \
    && fail "work/uploading directories must not be enumerated"
assert_exists "$REMOTE/work/uploading-20260101T000000Z/database.sql.gz.gpg"
pass "work/uploading-* directories are never touched in dry-run"

# --- --execute deletes only expected completed backups ---
OUTPUT="$(run_prune "$REMOTE" --execute 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "execute failed: $OUTPUT"
echo "$OUTPUT" | grep -qi 'EXECUTE mode' || fail "execute mode banner missing"

assert_exists "$(backup_dir "$REMOTE" "20260819T180000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260819T080000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260818T080000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260818T200000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260813T120000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260811T200000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260804T200000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260720T120000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260719T120000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260705T200000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260524T180000Z")/upload-complete.json"

assert_missing "$(backup_dir "$REMOTE" "20260811T080000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260804T080000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260718T120000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260706T120000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260705T080000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260521T120000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260520T120000Z")"
assert_missing "$(backup_dir "$REMOTE" "20260401T120000Z")"
assert_missing "$REMOTE/2026/04"

assert_exists "$(backup_dir "$REMOTE" "20260315T120000Z")/database.sql.gz.gpg"
assert_exists "$(backup_dir "$REMOTE" "20260812T120000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "$BAD_SHA_ID")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "$PLAIN_ID")/database.sql.gz"
assert_exists "$(backup_dir "$REMOTE" "$WRONG_PATH_ID")/upload-complete.json"
assert_exists "$REMOTE/2026/08/07/20260807T120000Z"
assert_exists "$REMOTE/work/uploading-20260101T000000Z/database.sql.gz.gpg"
assert_exists "$REMOTE/2026/08/01/not-a-backup-id/upload-complete.json"
assert_exists "$REMOTE/2026/08/02/20260803T120000Z/upload-complete.json"
pass "--execute deletes only validated GFS candidates and preserves skipped trees"

# empty parent cleanup: April 2026 had only the expired backup
[[ ! -d "$REMOTE/2026/04" ]] || fail "empty year/month parent should be removed with rmdir"
[[ -d "$REMOTE/2026/08/18" ]] || fail "non-empty parent day directory should remain"
pass "empty parent directories are removed only when empty"

rm -rf "$REMOTE"

# --- newest completed backup older than 90 days is still kept ---
REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-prune-XXXXXX")"
create_completed_backup "$REMOTE" "20260520T120000Z"
create_completed_backup "$REMOTE" "20260401T120000Z"
OUTPUT="$(run_prune "$REMOTE" --dry-run 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "newest-over-90 dry-run failed: $OUTPUT"
assert_keep "$OUTPUT" "20260520T120000Z"
assert_delete "$OUTPUT" "20260401T120000Z"
echo "$OUTPUT" | grep -E "KEEP[[:space:]]+20260520T120000Z" | grep -q newest \
    || fail "over-90 newest should still use reason newest"
OUTPUT="$(run_prune "$REMOTE" --execute 2>&1)"
STATUS=$?
[[ "$STATUS" -eq 0 ]] || fail "newest-over-90 execute failed: $OUTPUT"
assert_exists "$(backup_dir "$REMOTE" "20260520T120000Z")/upload-complete.json"
assert_missing "$(backup_dir "$REMOTE" "20260401T120000Z")"
pass "newest completed backup is always kept even if older than 90 days"
rm -rf "$REMOTE"

# --- deletion abort on first rm failure ---
REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-prune-XXXXXX")"
create_completed_backup "$REMOTE" "20260819T180000Z"
create_completed_backup "$REMOTE" "20260401T120000Z"
WRAP="$(mktemp "${TMPDIR:-/tmp}/radium-backup-ssh-wrap-XXXXXX")"
cat >"$WRAP" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$*" == *rm\ -f* ]]; then
    echo "simulated rm failure" >&2
    exit 1
fi
exec ssh "$@"
EOF
chmod +x "$WRAP"
set +e
OUTPUT="$(
    env \
        PATH="$FIXTURES:$PATH" \
        SSH_BIN="$WRAP" \
        BACKUP_CLOUD_REMOTE_ROOT="$REMOTE" \
        BACKUP_CLOUD_SSH_HOST=mockhost \
        BACKUP_CLOUD_SSH_USER=mockuser \
        BACKUP_CLOUD_SSH_PORT=22 \
        BACKUP_PRUNE_AS_OF="$AS_OF" \
        "$ROOT/bin/backup-prune-cloud.sh" \
        --execute \
        2>&1
)"
STATUS=$?
set -e
[[ "$STATUS" -ne 0 ]] || fail "expected abort when remote delete fails"
echo "$OUTPUT" | grep -qi 'deletion failed\|simulated rm failure\|ERROR' \
    || fail "expected deletion failure error"
assert_exists "$(backup_dir "$REMOTE" "20260401T120000Z")/upload-complete.json"
assert_exists "$(backup_dir "$REMOTE" "20260819T180000Z")/upload-complete.json"
pass "first deletion failure aborts and does not continue"
rm -f "$WRAP"
rm -rf "$REMOTE"

echo "All backup-prune-cloud checks passed."
