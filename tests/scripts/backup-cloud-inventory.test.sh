#!/usr/bin/env bash
#
# Regression tests for bin/backup-cloud-inventory.sh
#
# Run: bash tests/scripts/backup-cloud-inventory.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/tests/scripts/fixtures/backup-mocks"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

assert_inventory_setfacl_traversal_chain() {
    local log="$1"
    local staging="$2"
    local inventory_path="$3"

    grep -F -- "-m u:ravi:--x,m:--x ${staging}" "$log" >/dev/null \
        || fail "setfacl missing staging root traversal ACL/mask"
    grep -F -- "-m u:ravi:r-x,m:r-x ${staging}/runs" "$log" >/dev/null \
        || fail "setfacl missing runs directory traversal ACL/mask"
    grep -F -- "-m u:ravi:r,m:r ${inventory_path}" "$log" >/dev/null \
        || fail "setfacl missing inventory read ACL/mask"
    grep -F -- "-d -m u:ravi:--x ${staging}/runs" "$log" >/dev/null \
        || fail "setfacl missing runs default traversal ACL"
}

[[ -x "$ROOT/bin/backup-cloud-inventory.sh" ]] || fail "backup-cloud-inventory.sh missing or not executable"
bash -n "$ROOT/bin/backup-cloud-inventory.sh" || fail "backup-cloud-inventory.sh syntax check failed"
pass "script syntax valid"

command -v php >/dev/null 2>&1 || fail "php is required for tests"
[[ -x "$FIXTURES/ssh" ]] || fail "ssh mock missing"

grep -q 'backup-cloud-inventory' "$ROOT/bin/backup-run.sh" && fail "backup-run.sh must not invoke inventory"
grep -q 'backup-cloud-inventory' "$ROOT/bin/backup-prune-cloud.sh" && fail "backup-prune-cloud.sh must not invoke inventory"
pass "existing backup scripts left unchanged"

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

run_inventory() {
    local remote_root="$1"
    local output_path="$2"
    shift 2
    local -a extra_env=("$@")

    env \
        PATH="$FIXTURES:$PATH" \
        SSH_BIN=ssh \
        BACKUP_STAGING_ROOT="$(dirname "$output_path")" \
        BACKUP_CLOUD_INVENTORY_PATH="$output_path" \
        BACKUP_CLOUD_REMOTE_ROOT="$remote_root" \
        BACKUP_CLOUD_SSH_HOST=mockhost \
        BACKUP_CLOUD_SSH_USER=mockuser \
        BACKUP_CLOUD_SSH_PORT=22 \
        "${extra_env[@]}" \
        "$ROOT/bin/backup-cloud-inventory.sh"
}

REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-remote-XXXXXX")"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-staging-XXXXXX")"
OUTPUT="${STAGING}/cloud-inventory.json"
trap 'rm -rf "$REMOTE" "$STAGING"' EXIT

create_completed_backup "$REMOTE" "20260818T185214Z"
create_completed_backup "$REMOTE" "20260820T083001Z"
mkdir -p "$(backup_dir "$REMOTE" "20260819T120000Z")"
printf '{}' >"$(backup_dir "$REMOTE" "20260819T120000Z")/manifest.json"

OUTPUT_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-inventory-log-XXXXXX")"
run_inventory "$REMOTE" "$OUTPUT" BACKUP_MANIFEST_ACL_ENABLED=false 2>"$OUTPUT_LOG"

[[ -f "$OUTPUT" ]] || fail "inventory output file missing"

php "$ROOT/tests/scripts/validate-backup-cloud-inventory.php" "$OUTPUT" || fail "inventory JSON validation failed"

ENTRY_COUNT="$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["entries"] ?? []);' "$OUTPUT")"
[[ "$ENTRY_COUNT" -eq 2 ]] || fail "expected 2 completed backups in inventory, got ${ENTRY_COUNT}"
grep -q 'missing-upload-complete' "$OUTPUT_LOG" || fail "expected incomplete backup to be skipped"
pass "writes sanitized inventory JSON with completed backups only"

# Regression: ssh -n prevents OpenSSH from consuming the here-string that feeds classify loop.
BULK_REMOTE="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-bulk-XXXXXX")"
BULK_STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-bulk-staging-XXXXXX")"
BULK_OUTPUT="${BULK_STAGING}/cloud-inventory.json"
BULK_EXPECT=30
day=1
while [[ "$day" -le "$BULK_EXPECT" ]]; do
    backup_id="$(printf '202611%02dT%02d0001Z' "$day" $((day % 24)))"
    create_completed_backup "$BULK_REMOTE" "$backup_id"
    day=$((day + 1))
done
# Invalid fixtures: bad checksum, malformed marker, malformed path (must not count as completed).
BAD_SHA_ID="20261115T120001Z"
create_completed_backup "$BULK_REMOTE" "$BAD_SHA_ID"
php -r '
    $dir = $argv[1];
    $raw = json_decode(file_get_contents($dir . "/upload-complete.json"), true);
    $raw["manifest_sha256"] = str_repeat("0", 64);
    file_put_contents($dir . "/upload-complete.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$(backup_dir "$BULK_REMOTE" "$BAD_SHA_ID")"
mkdir -p "$(backup_dir "$BULK_REMOTE" "20261116T120001Z")"
printf 'not-json' >"$(backup_dir "$BULK_REMOTE" "20261116T120001Z")/upload-complete.json"
mkdir -p "$BULK_REMOTE/2026/11/17/not-a-valid-id"
printf '{}' >"$BULK_REMOTE/2026/11/17/not-a-valid-id/upload-complete.json"

run_inventory "$BULK_REMOTE" "$BULK_OUTPUT" BACKUP_MANIFEST_ACL_ENABLED=false >/dev/null
BULK_COUNT="$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["entries"] ?? []);' "$BULK_OUTPUT")"
[[ "$BULK_COUNT" -eq "$BULK_EXPECT" ]] \
    || fail "bulk inventory expected ${BULK_EXPECT} completed entries, got ${BULK_COUNT}"

SPACE_ROOT="$BULK_REMOTE/with spaces/backup-root"
set +e
SPACE_LOG="$(BACKUP_CLOUD_REMOTE_ROOT="$SPACE_ROOT" env PATH="$FIXTURES:$PATH" SSH_BIN=ssh \
    BACKUP_STAGING_ROOT="$(dirname "$BULK_OUTPUT")" BACKUP_CLOUD_INVENTORY_PATH="$BULK_OUTPUT" \
    BACKUP_CLOUD_SSH_HOST=mockhost BACKUP_CLOUD_SSH_USER=mockuser BACKUP_CLOUD_SSH_PORT=22 \
    BACKUP_MANIFEST_ACL_ENABLED=false "$ROOT/bin/backup-cloud-inventory.sh" 2>&1)"
SPACE_STATUS=$?
set -e
[[ "$SPACE_STATUS" -ne 0 ]] || echo "$SPACE_LOG" | grep -qi 'unsupported characters' \
    || fail "path with spaces must be rejected safely"
pass "enumerates many completion markers without ssh stdin loop regression"
rm -rf "$BULK_REMOTE" "$BULK_STAGING" 2>/dev/null || true
rm -f "$OUTPUT_LOG"

grep -q 'remote_path' "$OUTPUT" && fail "inventory must not contain remote_path"
grep -q '\.gpg' "$OUTPUT" && fail "inventory must not contain encrypted artifact names"
grep -q 'mockhost' "$OUTPUT" && fail "inventory must not contain ssh host"
pass "inventory excludes sensitive fields"

ACL_STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-acl-staging-XXXXXX")"
SETFACL_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-inventory-setfacl-XXXXXX")"
ACL_OUTPUT="$(run_inventory "$REMOTE" "${ACL_STAGING}/cloud-inventory.json" BACKUP_MANIFEST_ACL_ENABLED=true SETFACL_MOCK_LOG="$SETFACL_LOG" 2>&1)"
echo "$ACL_OUTPUT" | grep -F 'inventory read ACL applied for ravi' >/dev/null \
    || fail "inventory ACL success was not logged: $ACL_OUTPUT"
assert_inventory_setfacl_traversal_chain "$SETFACL_LOG" "$ACL_STAGING" "${ACL_STAGING}/cloud-inventory.json"
pass "inventory applies staging traversal ACLs before index read ACL"

rm -f "$SETFACL_LOG"
rm -rf "$ACL_STAGING"

set +e
TMP_BAD="$(mktemp "${TMPDIR:-/tmp}/radium-inv-bad-XXXXXX").json"
GUARD_LOG="$(
    env PATH="$FIXTURES:$PATH" SSH_BIN=ssh \
        BACKUP_STAGING_ROOT="$STAGING" \
        BACKUP_CLOUD_INVENTORY_PATH="$TMP_BAD" \
        BACKUP_CLOUD_REMOTE_ROOT="$REMOTE" \
        BACKUP_CLOUD_SSH_HOST=mockhost BACKUP_CLOUD_SSH_USER=mockuser BACKUP_CLOUD_SSH_PORT=22 \
        BACKUP_MANIFEST_ACL_ENABLED=false \
        "$ROOT/bin/backup-cloud-inventory.sh" 2>&1
)"
GUARD_EC=$?
set -e
rm -f "$TMP_BAD"
[[ "$GUARD_EC" -ne 0 ]] || fail "inventory must fail when output path is outside staging root"
echo "$GUARD_LOG" | grep -qi 'must stay under BACKUP_STAGING_ROOT' \
    || fail "expected staging containment error for /tmp output path"
pass "rejects BACKUP_CLOUD_INVENTORY_PATH outside BACKUP_STAGING_ROOT"

echo "All backup-cloud-inventory tests passed."
