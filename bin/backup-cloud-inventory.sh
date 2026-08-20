#!/usr/bin/env bash
#
# Radium Desk — Cloud backup inventory (read-only).
#
# Enumerates completed Hostinger Cloud backups over SSH using the same
# validation model as backup-prune-cloud.sh and writes a sanitized local
# JSON index for the Desk web app. Does not delete, prune, upload, or restore.
#
# See docs/backup-runbook.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SSH_BIN="${SSH_BIN:-$(command -v ssh || true)}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
BACKUP_STAGING_ROOT="${BACKUP_STAGING_ROOT:-/var/backups/radium-desk}"

INVENTORY_TEMP=""
DATE_FLAVOR=""

COMPLETED_IDS=()
COMPLETED_SIZES=()

ITEM_SKIP_REASON=""
ITEM_ID=""
ITEM_SIZE=""

log() {
    echo "backup-cloud-inventory.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage: backup-cloud-inventory.sh

Builds a sanitized local JSON index of completed Cloud backups.

Environment:
  BACKUP_STAGING_ROOT                 (default /var/backups/radium-desk)
  BACKUP_CLOUD_SSH_HOST
  BACKUP_CLOUD_SSH_USER
  BACKUP_CLOUD_SSH_PORT               (default 65002)
  BACKUP_CLOUD_SSH_IDENTITY_FILE      (optional)
  BACKUP_CLOUD_REMOTE_ROOT
  BACKUP_CLOUD_INVENTORY_PATH         (optional override for output file)
  BACKUP_MANIFEST_ACL_ENABLED         (default true)
  BACKUP_MANIFEST_ACL_USER            (default ravi)
  PHP_BIN, SSH_BIN
EOF
}

cleanup_temp() {
    if [[ -n "$INVENTORY_TEMP" && -d "$INVENTORY_TEMP" ]]; then
        rm -rf "$INVENTORY_TEMP"
        INVENTORY_TEMP=""
    fi
}

trap cleanup_temp EXIT

truthy_env() {
    local value="${1:-}"

    case "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

inventory_read_acl_enabled() {
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"

    [[ -n "$acl_user" ]] && truthy_env "${BACKUP_MANIFEST_ACL_ENABLED:-true}"
}

restore_staging_traversal_acls() {
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"
    local runs_root="${BACKUP_STAGING_ROOT}/runs"

    if ! inventory_read_acl_enabled; then
        return 0
    fi

    if ! command -v setfacl >/dev/null 2>&1; then
        log "ERROR: setfacl not available; staging traversal ACLs not restored"

        return 1
    fi

    if ! setfacl -m "u:${acl_user}:--x,m:--x" "$BACKUP_STAGING_ROOT"; then
        log "ERROR: setfacl failed for staging root (exit $?)"

        return 1
    fi

    if ! setfacl -m "u:${acl_user}:r-x,m:r-x" "$runs_root"; then
        log "ERROR: setfacl failed for runs directory (exit $?)"

        return 1
    fi

    setfacl -d -m "u:${acl_user}:--x" "$runs_root" 2>/dev/null || true

    return 0
}

ensure_staging_layout_for_acl() {
    mkdir -p "${BACKUP_STAGING_ROOT}/runs"
    chmod 700 "$BACKUP_STAGING_ROOT" 2>/dev/null || true
    chmod 700 "${BACKUP_STAGING_ROOT}/runs" 2>/dev/null || true
}

detect_date_flavor() {
    if date -u -d "1970-01-01 00:00:00" +%s >/dev/null 2>&1; then
        DATE_FLAVOR="gnu"
    elif date -u -j -f "%Y-%m-%d %H:%M:%S" "1970-01-01 00:00:00" +%s >/dev/null 2>&1; then
        DATE_FLAVOR="bsd"
    else
        die "unable to parse UTC dates with this date(1)"
    fi
}

backup_id_to_iso8601() {
    local backup_id="$1"

    if [[ ! "$backup_id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
        return 1
    fi

    printf '%s-%s-%sT%s:%s:%sZ' \
        "${backup_id:0:4}" \
        "${backup_id:4:2}" \
        "${backup_id:6:2}" \
        "${backup_id:9:2}" \
        "${backup_id:11:2}" \
        "${backup_id:13:2}"
}

sha256_file() {
    local path="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{print $1}'
    else
        shasum -a 256 "$path" | awk '{print $1}'
    fi
}

path_has_unsafe_chars() {
    local path="$1"

    case "$path" in
        *"'"*|*"\""*|*$'\n'*|*$'\r'*) return 0 ;;
    esac
    return 1
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --help|-h)
                usage
                exit 0
                ;;
            *)
                usage
                die "unknown argument: ${arg}"
                ;;
        esac
    done
}

resolve_cloud_config() {
    if [[ -z "$SSH_BIN" ]]; then
        die "ssh is required for Cloud inventory but was not found on PATH"
    fi

    if [[ -z "$PHP_BIN" ]]; then
        die "php is required to validate backup JSON"
    fi

    BACKUP_CLOUD_SSH_HOST="${BACKUP_CLOUD_SSH_HOST:-}"
    BACKUP_CLOUD_SSH_USER="${BACKUP_CLOUD_SSH_USER:-}"
    BACKUP_CLOUD_SSH_PORT="${BACKUP_CLOUD_SSH_PORT:-65002}"
    BACKUP_CLOUD_REMOTE_ROOT="${BACKUP_CLOUD_REMOTE_ROOT:-}"

    [[ -n "$BACKUP_CLOUD_SSH_HOST" ]] || die "BACKUP_CLOUD_SSH_HOST is required"
    [[ -n "$BACKUP_CLOUD_SSH_USER" ]] || die "BACKUP_CLOUD_SSH_USER is required"
    [[ -n "$BACKUP_CLOUD_REMOTE_ROOT" ]] || die "BACKUP_CLOUD_REMOTE_ROOT is required"

    BACKUP_CLOUD_REMOTE_ROOT="${BACKUP_CLOUD_REMOTE_ROOT%/}"

    [[ "$BACKUP_CLOUD_REMOTE_ROOT" == /* ]] || die "BACKUP_CLOUD_REMOTE_ROOT must be an absolute path"
    [[ "$BACKUP_CLOUD_REMOTE_ROOT" != *..* ]] || die "BACKUP_CLOUD_REMOTE_ROOT must not contain .."
    if path_has_unsafe_chars "$BACKUP_CLOUD_REMOTE_ROOT"; then
        die "BACKUP_CLOUD_REMOTE_ROOT contains unsupported characters"
    fi
}

resolve_inventory_path() {
    BACKUP_CLOUD_INVENTORY_PATH="${BACKUP_CLOUD_INVENTORY_PATH:-${BACKUP_STAGING_ROOT%/}/cloud-inventory.json}"

    [[ "$BACKUP_CLOUD_INVENTORY_PATH" == /* ]] || die "inventory output path must be absolute"
    [[ "$BACKUP_CLOUD_INVENTORY_PATH" != *..* ]] || die "inventory output path must not contain .."
    if path_has_unsafe_chars "$BACKUP_CLOUD_INVENTORY_PATH"; then
        die "inventory output path contains unsupported characters"
    fi
}

remote_ssh_target() {
    printf '%s@%s' "$BACKUP_CLOUD_SSH_USER" "$BACKUP_CLOUD_SSH_HOST"
}

remote_ssh_exec() {
    local remote_command="$1"
    local -a ssh_args=(
        -p "$BACKUP_CLOUD_SSH_PORT"
        -o BatchMode=yes
        -o StrictHostKeyChecking=accept-new
    )

    if [[ -n "${BACKUP_CLOUD_SSH_IDENTITY_FILE:-}" ]]; then
        ssh_args+=(-i "$BACKUP_CLOUD_SSH_IDENTITY_FILE")
    fi

    "$SSH_BIN" "${ssh_args[@]}" "$(remote_ssh_target)" "$remote_command"
}

assert_path_under_remote_root() {
    local path="$1"

    [[ "$path" == "$BACKUP_CLOUD_REMOTE_ROOT"/* ]] || return 1
    [[ "$path" != *..* ]] || return 1
    if path_has_unsafe_chars "$path"; then
        return 1
    fi
    return 0
}

relative_from_remote_root() {
    local path="$1"
    printf '%s' "${path#"$BACKUP_CLOUD_REMOTE_ROOT"/}"
}

is_plaintext_artifact() {
    local name="$1"

    case "$name" in
        *.sql|*.sql.gz|*.tar.gz)
            [[ "$name" != *.gpg && "$name" != *.age ]] && return 0
            ;;
    esac
    return 1
}

is_allowed_backup_file() {
    local name="$1"

    case "$name" in
        manifest.json|upload-complete.json|database.sql.gz.gpg|database.sql.gz.age|secrets.tar.gz.gpg|secrets.tar.gz.age)
            return 0
            ;;
    esac
    return 1
}

ensure_remote_root() {
    local kind
    kind="$(remote_ssh_exec "
        set -euo pipefail
        root='${BACKUP_CLOUD_REMOTE_ROOT}'
        if [ -L \"\$root\" ]; then echo SYMLINK; exit 0; fi
        if [ ! -d \"\$root\" ]; then echo MISSING; exit 0; fi
        echo DIR
    ")"

    case "$kind" in
        DIR) ;;
        SYMLINK) die "BACKUP_CLOUD_REMOTE_ROOT is a symlink; refusing to follow" ;;
        MISSING) die "BACKUP_CLOUD_REMOTE_ROOT does not exist on the remote host" ;;
        *) die "unable to classify BACKUP_CLOUD_REMOTE_ROOT (${kind})" ;;
    esac
}

enumerate_leaf_paths() {
    remote_ssh_exec "
        set -euo pipefail
        root='${BACKUP_CLOUD_REMOTE_ROOT}'
        find -P \"\$root\" -mindepth 4 -maxdepth 4 \\( -type d -o -type l \\) 2>/dev/null | sort
    "
}

inspect_leaf_entries() {
    local dir="$1"

    remote_ssh_exec "
        set -euo pipefail
        dir='${dir}'
        if [ -L \"\$dir\" ]; then echo SYMLINK_DIR; exit 0; fi
        if [ ! -d \"\$dir\" ]; then echo NOT_DIR; exit 0; fi
        echo DIR
        find -P \"\$dir\" -mindepth 1 -maxdepth 1 -print | sort | while IFS= read -r p; do
            [ -n \"\$p\" ] || continue
            base=\$(basename \"\$p\")
            if [ -L \"\$p\" ]; then
                printf 'symlink\t%s\t0\n' \"\$base\"
            elif [ -f \"\$p\" ]; then
                size=\$(stat -c '%s' \"\$p\" 2>/dev/null || stat -f '%z' \"\$p\")
                printf 'file\t%s\t%s\n' \"\$base\" \"\$size\"
            elif [ -d \"\$p\" ]; then
                printf 'dir\t%s\t0\n' \"\$base\"
            else
                printf 'other\t%s\t0\n' \"\$base\"
            fi
        done
    "
}

fetch_remote_regular_file() {
    local remote_path="$1"
    local dest="$2"

    remote_ssh_exec "
        set -euo pipefail
        p='${remote_path}'
        if [ -L \"\$p\" ]; then echo 'symlink' >&2; exit 3; fi
        if [ ! -f \"\$p\" ]; then echo 'missing' >&2; exit 2; fi
        cat -- \"\$p\"
    " >"$dest"
}

php_validate_completed_json() {
    local marker_path="$1"
    local manifest_path="$2"
    local backup_id="$3"
    local remote_path="$4"
    local manifest_sha="$5"

    "$PHP_BIN" -r '
        $markerPath = $argv[1];
        $manifestPath = $argv[2];
        $backupId = $argv[3];
        $remotePath = rtrim($argv[4], "/");
        $manifestSha = $argv[5];

        $markerRaw = file_get_contents($markerPath);
        $marker = json_decode((string) $markerRaw, true);
        if (! is_array($marker)) {
            fwrite(STDERR, "invalid upload-complete json\n");
            exit(2);
        }
        if (($marker["status"] ?? "") !== "completed") {
            fwrite(STDERR, "upload-complete status is not completed\n");
            exit(3);
        }
        if (($marker["backup_id"] ?? "") !== $backupId) {
            fwrite(STDERR, "upload-complete backup_id mismatch\n");
            exit(4);
        }
        $markerRemote = rtrim((string) ($marker["remote_path"] ?? ""), "/");
        if ($markerRemote !== $remotePath) {
            fwrite(STDERR, "upload-complete remote_path mismatch\n");
            exit(5);
        }
        $markerSha = (string) ($marker["manifest_sha256"] ?? "");
        if (! preg_match("/^[a-f0-9]{64}$/", $markerSha)) {
            fwrite(STDERR, "upload-complete manifest_sha256 is invalid\n");
            exit(6);
        }
        if ($markerSha !== $manifestSha) {
            fwrite(STDERR, "manifest sha256 mismatch\n");
            exit(7);
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            fwrite(STDERR, "invalid manifest json\n");
            exit(8);
        }
        if (($manifest["backup_id"] ?? "") !== $backupId) {
            fwrite(STDERR, "manifest backup_id mismatch\n");
            exit(9);
        }

        $artifacts = $manifest["artifacts"] ?? null;
        if (! is_array($artifacts) || count($artifacts) !== 2) {
            fwrite(STDERR, "manifest artifacts missing or unexpected\n");
            exit(10);
        }

        $names = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                fwrite(STDERR, "manifest artifact is invalid\n");
                exit(11);
            }
            $name = (string) ($artifact["filename"] ?? "");
            $names[] = $name;
        }
        sort($names);
        $okGpg = ($names === ["database.sql.gz.gpg", "secrets.tar.gz.gpg"]);
        $okAge = ($names === ["database.sql.gz.age", "secrets.tar.gz.age"]);
        if (! $okGpg && ! $okAge) {
            fwrite(STDERR, "manifest artifact filenames are not the encrypted pair\n");
            exit(12);
        }
    ' "$marker_path" "$manifest_path" "$backup_id" "$remote_path" "$manifest_sha"
}

validate_completed_leaf() {
    local path="$1"
    local rel backup_id year month day inspect kind name size
    local has_marker=0 has_manifest=0 has_db=0 has_secrets=0
    local total_size=0
    local php_status marker_file manifest_file manifest_sha

    ITEM_SKIP_REASON=""
    ITEM_ID=""
    ITEM_SIZE=""

    if ! assert_path_under_remote_root "$path"; then
        ITEM_SKIP_REASON="path-outside-remote-root"
        return 1
    fi

    rel="$(relative_from_remote_root "$path")"
    if [[ ! "$rel" =~ ^[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{8}T[0-9]{6}Z$ ]]; then
        ITEM_SKIP_REASON="malformed-path"
        return 1
    fi

    year="${rel:0:4}"
    month="${rel:5:2}"
    day="${rel:8:2}"
    backup_id="${rel:11}"

    if [[ "${backup_id:0:4}" != "$year" || "${backup_id:4:2}" != "$month" || "${backup_id:6:2}" != "$day" ]]; then
        ITEM_SKIP_REASON="date-path-mismatch"
        return 1
    fi

    inspect="$(inspect_leaf_entries "$path")"
    kind="$(printf '%s\n' "$inspect" | head -n 1)"

    case "$kind" in
        SYMLINK_DIR)
            ITEM_SKIP_REASON="symlink-dir"
            return 1
            ;;
        NOT_DIR)
            ITEM_SKIP_REASON="not-a-directory"
            return 1
            ;;
        DIR) ;;
        *)
            ITEM_SKIP_REASON="unknown-dir-kind"
            return 1
            ;;
    esac

    while IFS=$'\t' read -r kind name size; do
        [[ -n "${kind:-}" ]] || continue
        case "$kind" in
            symlink)
                ITEM_SKIP_REASON="symlink-entry:${name}"
                return 1
                ;;
            dir|other)
                ITEM_SKIP_REASON="unexpected-entry:${name}"
                return 1
                ;;
            file) ;;
            *)
                continue
                ;;
        esac

        if is_plaintext_artifact "$name"; then
            ITEM_SKIP_REASON="plaintext-artifact:${name}"
            return 1
        fi

        if ! is_allowed_backup_file "$name"; then
            ITEM_SKIP_REASON="unexpected-file:${name}"
            return 1
        fi

        case "$name" in
            upload-complete.json) has_marker=1 ;;
            manifest.json) has_manifest=1 ;;
            database.sql.gz.gpg|database.sql.gz.age) has_db=$((has_db + 1)) ;;
            secrets.tar.gz.gpg|secrets.tar.gz.age) has_secrets=$((has_secrets + 1)) ;;
        esac

        total_size=$((total_size + size))
    done < <(printf '%s\n' "$inspect" | tail -n +2)

    if [[ "$has_marker" -ne 1 ]]; then
        ITEM_SKIP_REASON="missing-upload-complete"
        return 1
    fi
    if [[ "$has_manifest" -ne 1 ]]; then
        ITEM_SKIP_REASON="missing-manifest"
        return 1
    fi
    if [[ "$has_db" -ne 1 || "$has_secrets" -ne 1 ]]; then
        ITEM_SKIP_REASON="missing-or-duplicate-encrypted-artifacts"
        return 1
    fi

    marker_file="${INVENTORY_TEMP}/marker.json"
    manifest_file="${INVENTORY_TEMP}/manifest.json"
    fetch_remote_regular_file "${path}/upload-complete.json" "$marker_file"
    fetch_remote_regular_file "${path}/manifest.json" "$manifest_file"
    manifest_sha="$(sha256_file "$manifest_file")"

    set +e
    php_validate_completed_json "$marker_file" "$manifest_file" "$backup_id" "$path" "$manifest_sha" 2>"${INVENTORY_TEMP}/php.err"
    php_status=$?
    set -e

    if [[ "$php_status" -ne 0 ]]; then
        ITEM_SKIP_REASON="validation-failed:$(tr '\n' ' ' <"${INVENTORY_TEMP}/php.err" | sed 's/[[:space:]]*$//')"
        return 1
    fi

    ITEM_ID="$backup_id"
    ITEM_SIZE="$total_size"
    return 0
}

record_completed() {
    COMPLETED_IDS+=("$1")
    COMPLETED_SIZES+=("$2")
}

classify_leaf() {
    local path="$1"

    if validate_completed_leaf "$path"; then
        record_completed "$ITEM_ID" "$ITEM_SIZE"
        return 0
    fi

    log "SKIP   ${path}  ${ITEM_SKIP_REASON}"
}

write_inventory_json() {
    local output_path="$1"
    local temp_path="${output_path}.tmp.$$"
    local generated_at
    generated_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    mkdir -p "$(dirname "$output_path")"
    chmod 700 "$(dirname "$output_path")" 2>/dev/null || true

    export BACKUP_INVENTORY_GENERATED_AT="$generated_at"
    export BACKUP_INVENTORY_OUTPUT="$temp_path"

    local i=0
    while [[ $i -lt ${#COMPLETED_IDS[@]} ]]; do
        export "BACKUP_INVENTORY_ID_${i}=${COMPLETED_IDS[$i]}"
        export "BACKUP_INVENTORY_SIZE_${i}=${COMPLETED_SIZES[$i]}"
        i=$((i + 1))
    done
    export BACKUP_INVENTORY_COUNT="$i"

    "$PHP_BIN" -r '
        $generatedAt = getenv("BACKUP_INVENTORY_GENERATED_AT") ?: gmdate("c");
        $output = getenv("BACKUP_INVENTORY_OUTPUT") ?: "";
        $count = (int) (getenv("BACKUP_INVENTORY_COUNT") ?: "0");
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $id = getenv("BACKUP_INVENTORY_ID_" . $i) ?: "";
            $size = (int) (getenv("BACKUP_INVENTORY_SIZE_" . $i) ?: "0");
            if (! preg_match("/^[0-9]{8}T[0-9]{6}Z$/", $id)) {
                continue;
            }
            $timestamp = sprintf(
                "%s-%s-%sT%s:%s:%sZ",
                substr($id, 0, 4),
                substr($id, 4, 2),
                substr($id, 6, 2),
                substr($id, 9, 2),
                substr($id, 11, 2),
                substr($id, 13, 2),
            );
            $entries[] = [
                "backup_id" => $id,
                "timestamp_utc" => $timestamp,
                "total_size_bytes" => max(0, $size),
                "manifest_present" => true,
                "upload_complete" => true,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b["backup_id"], $a["backup_id"]));

        $payload = [
            "version" => 1,
            "generated_at" => $generatedAt,
            "entries" => $entries,
        ];

        file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    '

    chmod 600 "$temp_path"
    mv -f "$temp_path" "$output_path"
}

apply_inventory_read_acl() {
    local inventory_path="$1"
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"

    if ! inventory_read_acl_enabled; then
        log "inventory read ACL skipped (disabled)"
        return 0
    fi

    if [[ ! -f "$inventory_path" ]]; then
        log "ERROR: inventory ACL: file missing"
        return 1
    fi

    if [[ "$(basename "$inventory_path")" != "cloud-inventory.json" ]]; then
        log "ERROR: inventory ACL: unexpected filename"
        return 1
    fi

    if ! command -v setfacl >/dev/null 2>&1; then
        log "ERROR: setfacl not available; inventory read ACL not applied"
        return 1
    fi

    ensure_staging_layout_for_acl
    restore_staging_traversal_acls \
        || log "ERROR: staging traversal ACLs were not restored"

    if setfacl -m "u:${acl_user}:r,m:r" "$inventory_path"; then
        log "inventory read ACL applied for ${acl_user}"
        return 0
    fi

    log "ERROR: setfacl failed for inventory (exit $?)"
    return 1
}

main() {
    parse_args "$@"
    detect_date_flavor
    resolve_cloud_config
    resolve_inventory_path

    INVENTORY_TEMP="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-inventory.XXXXXX")"
    chmod 700 "$INVENTORY_TEMP"

    log "building sanitized Cloud inventory index"

    ensure_remote_root

    local paths_raw path
    paths_raw="$(enumerate_leaf_paths || true)"

    if [[ -n "$paths_raw" ]]; then
        while IFS= read -r path; do
            [[ -n "$path" ]] || continue
            classify_leaf "$path"
        done <<<"$paths_raw"
    fi

    write_inventory_json "$BACKUP_CLOUD_INVENTORY_PATH"
    apply_inventory_read_acl "$BACKUP_CLOUD_INVENTORY_PATH" \
        || log "ERROR: inventory read ACL was not applied"

    log "inventory written (${#COMPLETED_IDS[@]} completed backups) -> ${BACKUP_CLOUD_INVENTORY_PATH}"
}

main "$@"
