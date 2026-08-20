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
OUTPUT="$(mktemp "${TMPDIR:-/tmp}/radium-backup-inventory-out-XXXXXX").json"
trap 'rm -rf "$REMOTE"; rm -f "$OUTPUT"' EXIT

create_completed_backup "$REMOTE" "20260818T185214Z"
create_completed_backup "$REMOTE" "20260820T083001Z"
mkdir -p "$(backup_dir "$REMOTE" "20260819T120000Z")"
printf '{}' >"$(backup_dir "$REMOTE" "20260819T120000Z")/manifest.json"

run_inventory "$REMOTE" "$OUTPUT" BACKUP_MANIFEST_ACL_ENABLED=false

[[ -f "$OUTPUT" ]] || fail "inventory output file missing"

php "$ROOT/tests/scripts/validate-backup-cloud-inventory.php" "$OUTPUT" || fail "inventory JSON validation failed"

pass "writes sanitized inventory JSON with completed backups only"

grep -q 'remote_path' "$OUTPUT" && fail "inventory must not contain remote_path"
grep -q '\.gpg' "$OUTPUT" && fail "inventory must not contain encrypted artifact names"
grep -q 'mockhost' "$OUTPUT" && fail "inventory must not contain ssh host"
pass "inventory excludes sensitive fields"

STAGING="$(dirname "$OUTPUT")"
ACL_STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory-staging-XXXXXX")"
SETFACL_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-inventory-setfacl-XXXXXX")"
ACL_OUTPUT="$(run_inventory "$REMOTE" "${ACL_STAGING}/cloud-inventory.json" BACKUP_MANIFEST_ACL_ENABLED=true SETFACL_MOCK_LOG="$SETFACL_LOG" 2>&1)"
echo "$ACL_OUTPUT" | grep -F 'inventory read ACL applied for ravi' >/dev/null \
    || fail "inventory ACL success was not logged: $ACL_OUTPUT"
assert_inventory_setfacl_traversal_chain "$SETFACL_LOG" "$ACL_STAGING" "${ACL_STAGING}/cloud-inventory.json"
pass "inventory applies staging traversal ACLs before index read ACL"

rm -f "$SETFACL_LOG"
rm -rf "$ACL_STAGING"

echo "All backup-cloud-inventory tests passed."
