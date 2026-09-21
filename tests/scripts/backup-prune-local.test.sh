#!/usr/bin/env bash
#
# Regression tests for bin/backup-prune-local.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/tests/scripts/fixtures/backup-mocks"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -x "$ROOT/bin/backup-prune-local.sh" ]] || fail "backup-prune-local.sh missing"
bash -n "$ROOT/bin/backup-prune-local.sh" || fail "syntax check failed"
pass "syntax valid"

command -v php >/dev/null 2>&1 || fail "php required"
[[ -x "$FIXTURES/ssh" ]] || fail "ssh mock missing"

create_local_run() {
    local staging="$1"
    local backup_id="$2"
    local phase="${3:-cloud_uploaded}"
    local remote_path="$4"
    local dir="${staging}/runs/${backup_id}"

    mkdir -p "$dir"
    printf 'db-%s' "$backup_id" >"${dir}/database.sql.gz.gpg"
    printf 'sec-%s' "$backup_id" >"${dir}/secrets.tar.gz.gpg"

    php -r '
        $id = $argv[1];
        $dir = $argv[2];
        $phase = $argv[3];
        $remote = $argv[4];
        $created = substr($id, 0, 4) . "-" . substr($id, 4, 2) . "-" . substr($id, 6, 2)
            . "T" . substr($id, 9, 2) . ":" . substr($id, 11, 2) . ":" . substr($id, 13, 2) . "Z";
        $manifest = [
            "backup_id" => $id,
            "created_at" => $created,
            "phase" => $phase,
            "artifacts" => [
                ["role" => "database", "filename" => "database.sql.gz.gpg"],
                ["role" => "secrets", "filename" => "secrets.tar.gz.gpg"],
            ],
            "upload" => [
                "status" => "completed",
                "uploaded_at" => $created,
                "remote_host" => "mock-cloud",
                "remote_path" => $remote,
                "artifacts_verified" => true,
            ],
        ];
        file_put_contents($dir . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$backup_id" "$dir" "$phase" "$remote_path"
}

setup_cloud_mock() {
    local cloud_root="$1"
    local backup_id="$2"
    local remote="${cloud_root}/2026/08/10/${backup_id}"
    mkdir -p "$remote"
    cp "$FIXTURES/cloud-marker-template.json" "${remote}/upload-complete.json" 2>/dev/null || true
}

TMP="$(mktemp -d)"
CLOUD="$(mktemp -d)"
STAGING="${TMP}/radium-desk"
mkdir -p "${STAGING}/runs"

export BACKUP_CLOUD_REMOTE_ROOT="$CLOUD"
create_local_run "$STAGING" "20260810T120000Z" "cloud_uploaded" "${CLOUD}/2026/08/10/20260810T120000Z"
mkdir -p "${CLOUD}/2026/08/10/20260810T120000Z"
php -r '
    $id = "20260810T120000Z";
    $dir = $argv[1];
    $manifestPath = $argv[2] . "/runs/" . $id . "/manifest.json";
    $manifestSha = hash_file("sha256", $manifestPath);
    file_put_contents($dir . "/manifest.json", json_encode([
        "backup_id" => $id,
        "phase" => "local_staging",
        "artifacts" => [
            ["filename" => "database.sql.gz.gpg"],
            ["filename" => "secrets.tar.gz.gpg"],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($dir . "/upload-complete.json", json_encode([
        "backup_id" => $id,
        "status" => "completed",
        "remote_path" => $dir,
        "manifest_sha256" => $manifestSha,
    ], JSON_PRETTY_PRINT));
' "${CLOUD}/2026/08/10/20260810T120000Z" "$STAGING"

create_local_run "$STAGING" "20260819T120000Z" "local_staging" "${CLOUD}/2026/08/19/20260819T120000Z"

OUT="$(env \
    PATH="$FIXTURES:$PATH" \
    SSH_BIN=ssh \
    BACKUP_STAGING_ROOT="$STAGING" \
    BACKUP_LOCAL_RETENTION_DAYS=7 \
    BACKUP_CLOUD_SSH_HOST=mock \
    BACKUP_CLOUD_SSH_USER=mock \
    BACKUP_CLOUD_REMOTE_ROOT="$CLOUD" \
    BACKUP_PRUNE_AS_OF=20260820T120000Z \
    "$ROOT/bin/backup-prune-local.sh" --dry-run 2>&1)" || fail "dry-run failed"

echo "$OUT" | grep -q 'KEEP   20260819T120000Z' || fail "recent run should be kept"
echo "$OUT" | grep -q 'DELETE 20260810T120000Z' || fail "old verified run should delete"
pass "retention classification"

EXEC_OUT="$(env \
    PATH="$FIXTURES:$PATH" \
    SSH_BIN=ssh \
    BACKUP_STAGING_ROOT="$STAGING" \
    BACKUP_LOCAL_RETENTION_DAYS=7 \
    BACKUP_CLOUD_SSH_HOST=mock \
    BACKUP_CLOUD_SSH_USER=mock \
    BACKUP_CLOUD_REMOTE_ROOT="$CLOUD" \
    BACKUP_PRUNE_AS_OF=20260820T120000Z \
    "$ROOT/bin/backup-prune-local.sh" --execute 2>&1)" || fail "execute failed"

[[ ! -d "${STAGING}/runs/20260810T120000Z" ]] || fail "old run should be deleted"
[[ -d "${STAGING}/runs/20260819T120000Z" ]] || fail "recent run should remain"
pass "execute deletes only verified expired run"

rm -rf "$TMP" "$CLOUD"
echo "ALL TESTS PASSED"
