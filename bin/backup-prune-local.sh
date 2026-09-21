#!/usr/bin/env bash
#
# Radium Desk — local backup staging retention (standalone).
#
# Deletes cloud-verified backup runs older than BACKUP_LOCAL_RETENTION_DAYS
# from BACKUP_STAGING_ROOT/runs/. Dry-run by default; --execute required to delete.
#
# Never deletes unverified, incomplete, in-progress, or cloud-unverified backups.
# See docs/storage-lifecycle-phase1.md and docs/backup-runbook.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
SSH_BIN="${SSH_BIN:-$(command -v ssh || true)}"

BACKUP_STAGING_ROOT="${BACKUP_STAGING_ROOT:-/var/backups/radium-desk}"
BACKUP_LOCAL_RETENTION_DAYS="${BACKUP_LOCAL_RETENTION_DAYS:-7}"

EXECUTE=0
DRY_RUN_FLAG=0
DATE_FLAVOR=""
AS_OF_ID=""
AS_OF_YMD=""
AS_OF_EPOCH=""

PRUNE_TEMP=""

KEEP_IDS=()
KEEP_REASONS=()
DELETE_IDS=()
DELETE_REASONS=()
DELETE_PATHS=()
DELETE_SIZES=()

ITEM_SKIP_REASON=""

log() {
    echo "backup-prune-local.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage: backup-prune-local.sh [--dry-run | --execute]

Local backup retention for cloud-verified runs only. Dry-run by default.

Environment:
  BACKUP_STAGING_ROOT              (default /var/backups/radium-desk)
  BACKUP_LOCAL_RETENTION_DAYS      (default 7)
  BACKUP_CLOUD_SSH_HOST            (required for cloud re-verification)
  BACKUP_CLOUD_SSH_USER
  BACKUP_CLOUD_SSH_PORT            (default 65002)
  BACKUP_CLOUD_SSH_IDENTITY_FILE   (optional)
  BACKUP_PRUNE_AS_OF               (optional UTC backup_id used as "now")
  PHP_BIN, SSH_BIN
EOF
}

cleanup_temp() {
    if [[ -n "$PRUNE_TEMP" && -d "$PRUNE_TEMP" ]]; then
        rm -rf "$PRUNE_TEMP"
        PRUNE_TEMP=""
    fi
}

trap cleanup_temp EXIT

parse_args() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --execute) EXECUTE=1 ;;
            --dry-run) DRY_RUN_FLAG=1 ;;
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

    if [[ "$EXECUTE" -eq 1 && "$DRY_RUN_FLAG" -eq 1 ]]; then
        die "Pass either --dry-run or --execute, not both."
    fi
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

utc_midnight_epoch() {
    local ymd="$1"

    case "$DATE_FLAVOR" in
        gnu) date -u -d "${ymd} 00:00:00" +%s ;;
        bsd) date -u -j -f "%Y-%m-%d %H:%M:%S" "${ymd} 00:00:00" +%s ;;
        *) die "date flavor not detected" ;;
    esac
}

ymd_from_backup_id() {
    local backup_id="$1"
    printf '%s-%s-%s' "${backup_id:0:4}" "${backup_id:4:2}" "${backup_id:6:2}"
}

resolve_as_of() {
    if [[ -n "${BACKUP_PRUNE_AS_OF:-}" ]]; then
        AS_OF_ID="${BACKUP_PRUNE_AS_OF}"
    else
        AS_OF_ID="$(date -u +%Y%m%dT%H%M%SZ)"
    fi

    if [[ ! "$AS_OF_ID" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
        die "BACKUP_PRUNE_AS_OF must be a UTC backup_id (YYYYMMDDTHHMMSSZ)"
    fi

    AS_OF_YMD="$(ymd_from_backup_id "$AS_OF_ID")"
    AS_OF_EPOCH="$(utc_midnight_epoch "$AS_OF_YMD")"
}

age_days_for_backup_id() {
    local backup_id="$1"
    local ymd epoch age

    ymd="$(ymd_from_backup_id "$backup_id")"
    epoch="$(utc_midnight_epoch "$ymd")"
    age=$(( (AS_OF_EPOCH - epoch) / 86400 ))
    if [[ "$age" -lt 0 ]]; then
        age=0
    fi
    printf '%s' "$age"
}

sha256_file() {
    local path="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{print $1}'
    else
        shasum -a 256 "$path" | awk '{print $1}'
    fi
}

dir_size_bytes() {
    local path="$1"

    if du -sb "$path" >/dev/null 2>&1; then
        du -sb "$path" | awk '{print $1}'
    else
        du -sk "$path" | awk '{print $1 * 1024}'
    fi
}

resolve_cloud_config() {
    BACKUP_CLOUD_SSH_HOST="${BACKUP_CLOUD_SSH_HOST:-}"
    BACKUP_CLOUD_SSH_USER="${BACKUP_CLOUD_SSH_USER:-}"
    BACKUP_CLOUD_SSH_PORT="${BACKUP_CLOUD_SSH_PORT:-65002}"

    [[ -n "$BACKUP_CLOUD_SSH_HOST" ]] || die "BACKUP_CLOUD_SSH_HOST is required for cloud re-verification"
    [[ -n "$BACKUP_CLOUD_SSH_USER" ]] || die "BACKUP_CLOUD_SSH_USER is required for cloud re-verification"
    [[ -z "$SSH_BIN" ]] && die "ssh is required for cloud re-verification"
    [[ -n "$PHP_BIN" ]] || die "php is required to validate backup JSON"
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
        manifest.json|database.sql.gz.gpg|database.sql.gz.age|secrets.tar.gz.gpg|secrets.tar.gz.age)
            return 0
            ;;
    esac
    return 1
}

backup_in_progress() {
    local backup_id="$1"
    local work_root="${BACKUP_STAGING_ROOT}/work"

    [[ -d "$work_root" ]] || return 1

    local match
    for match in "$work_root"/backup-"${backup_id}"-*; do
        [[ -e "$match" ]] || continue
        return 0
    done

    return 1
}

php_validate_local_manifest() {
    local manifest_path="$1"
    local backup_id="$2"

    "$PHP_BIN" -r '
        $path = $argv[1];
        $backupId = $argv[2];
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            fwrite(STDERR, "invalid manifest json\n");
            exit(2);
        }
        if (($data["backup_id"] ?? "") !== $backupId) {
            fwrite(STDERR, "manifest backup_id mismatch\n");
            exit(3);
        }
        if (($data["phase"] ?? "") !== "cloud_uploaded") {
            fwrite(STDERR, "manifest phase is not cloud_uploaded\n");
            exit(4);
        }
        $upload = $data["upload"] ?? null;
        if (! is_array($upload)) {
            fwrite(STDERR, "manifest upload block missing\n");
            exit(5);
        }
        if (($upload["status"] ?? "") !== "completed") {
            fwrite(STDERR, "manifest upload status is not completed\n");
            exit(6);
        }
        if (($upload["artifacts_verified"] ?? false) !== true) {
            fwrite(STDERR, "manifest upload artifacts_verified is not true\n");
            exit(7);
        }
        $remotePath = rtrim((string) ($upload["remote_path"] ?? ""), "/");
        if ($remotePath === "" || $remotePath[0] !== "/") {
            fwrite(STDERR, "manifest upload remote_path invalid\n");
            exit(8);
        }
        $artifacts = $data["artifacts"] ?? null;
        if (! is_array($artifacts) || count($artifacts) !== 2) {
            fwrite(STDERR, "manifest artifacts missing or unexpected\n");
            exit(9);
        }
    ' "$manifest_path" "$backup_id"
}

php_validate_remote_marker() {
    local marker_path="$1"
    local remote_manifest_path="$2"
    local backup_id="$3"
    local remote_path="$4"

    "$PHP_BIN" -r '
        $markerPath = $argv[1];
        $remoteManifestPath = $argv[2];
        $backupId = $argv[3];
        $remotePath = rtrim($argv[4], "/");

        $marker = json_decode((string) file_get_contents($markerPath), true);
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
        if (rtrim((string) ($marker["remote_path"] ?? ""), "/") !== $remotePath) {
            fwrite(STDERR, "upload-complete remote_path mismatch\n");
            exit(5);
        }
        $markerSha = (string) ($marker["manifest_sha256"] ?? "");
        if (! preg_match("/^[a-f0-9]{64}$/", $markerSha)) {
            fwrite(STDERR, "upload-complete manifest_sha256 is invalid\n");
            exit(6);
        }

        $remoteManifestSha = hash_file("sha256", $remoteManifestPath);
        if ($markerSha !== $remoteManifestSha) {
            fwrite(STDERR, "remote manifest sha256 mismatch\n");
            exit(7);
        }

        $remoteManifest = json_decode((string) file_get_contents($remoteManifestPath), true);
        if (! is_array($remoteManifest)) {
            fwrite(STDERR, "invalid remote manifest json\n");
            exit(8);
        }
        if (($remoteManifest["backup_id"] ?? "") !== $backupId) {
            fwrite(STDERR, "remote manifest backup_id mismatch\n");
            exit(9);
        }
    ' "$marker_path" "$remote_manifest_path" "$backup_id" "$remote_path"
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

verify_cloud_copy() {
    local manifest_path="$1"
    local backup_id="$2"
    local remote_path marker_file remote_manifest_file

    remote_path="$("$PHP_BIN" -r '
        $data = json_decode((string) file_get_contents($argv[1]), true);
        echo rtrim((string) ($data["upload"]["remote_path"] ?? ""), "/");
    ' "$manifest_path")"

    [[ -n "$remote_path" ]] || return 1

    marker_file="${PRUNE_TEMP}/cloud-marker.json"
    remote_manifest_file="${PRUNE_TEMP}/cloud-manifest.json"

    fetch_remote_regular_file "${remote_path}/upload-complete.json" "$marker_file"
    fetch_remote_regular_file "${remote_path}/manifest.json" "$remote_manifest_file"

    php_validate_remote_marker \
        "$marker_file" \
        "$remote_manifest_file" \
        "$backup_id" \
        "$remote_path"
}

validate_local_run() {
    local run_dir="$1"
    local backup_id="$2"
    local manifest_path="$3"
    local name size total_size=0 has_db=0 has_secrets=0

    ITEM_SKIP_REASON=""

    if [[ -L "$run_dir" ]]; then
        ITEM_SKIP_REASON="symlink-run-dir"
        return 1
    fi
    if [[ ! -d "$run_dir" ]]; then
        ITEM_SKIP_REASON="missing-run-dir"
        return 1
    fi
    if [[ ! "$backup_id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
        ITEM_SKIP_REASON="invalid-backup-id"
        return 1
    fi
    if backup_in_progress "$backup_id"; then
        ITEM_SKIP_REASON="backup-in-progress"
        return 1
    fi
    if [[ ! -f "$manifest_path" ]]; then
        ITEM_SKIP_REASON="missing-manifest"
        return 1
    fi

    for name in "$run_dir"/*; do
        [[ -e "$name" ]] || continue
        name="$(basename "$name")"

        if [[ "$name" == "manifest.json" ]]; then
            continue
        fi

        if [[ -L "$run_dir/$name" ]]; then
            ITEM_SKIP_REASON="symlink-entry:${name}"
            return 1
        fi
        if [[ -d "$run_dir/$name" ]]; then
            ITEM_SKIP_REASON="unexpected-directory:${name}"
            return 1
        fi
        if is_plaintext_artifact "$name"; then
            ITEM_SKIP_REASON="plaintext-artifact:${name}"
            return 1
        fi
        if ! is_allowed_backup_file "$name"; then
            ITEM_SKIP_REASON="unexpected-file:${name}"
            return 1
        fi

        size="$(stat -c '%s' "$run_dir/$name" 2>/dev/null || stat -f '%z' "$run_dir/$name")"
        total_size=$((total_size + size))

        case "$name" in
            database.sql.gz.gpg|database.sql.gz.age) has_db=$((has_db + 1)) ;;
            secrets.tar.gz.gpg|secrets.tar.gz.age) has_secrets=$((has_secrets + 1)) ;;
        esac
    done

    if [[ "$has_db" -ne 1 || "$has_secrets" -ne 1 ]]; then
        ITEM_SKIP_REASON="missing-or-duplicate-encrypted-artifacts"
        return 1
    fi

    if ! php_validate_local_manifest "$manifest_path" "$backup_id" 2>"${PRUNE_TEMP}/manifest.err"; then
        ITEM_SKIP_REASON="local-manifest-invalid:$(tr '\n' ' ' <"${PRUNE_TEMP}/manifest.err" | sed 's/[[:space:]]*$//')"
        return 1
    fi

    if ! verify_cloud_copy "$manifest_path" "$backup_id"; then
        ITEM_SKIP_REASON="cloud-verification-failed"
        return 1
    fi

    return 0
}

classify_runs() {
    local runs_root="${BACKUP_STAGING_ROOT}/runs"
    local run_dir backup_id manifest_path age size reason

    KEEP_IDS=()
    KEEP_REASONS=()
    DELETE_IDS=()
    DELETE_REASONS=()
    DELETE_PATHS=()
    DELETE_SIZES=()

    [[ -d "$runs_root" ]] || die "runs directory missing: ${runs_root}"

    shopt -s nullglob
    for run_dir in "$runs_root"/*; do
        [[ -d "$run_dir" ]] || continue
        backup_id="$(basename "$run_dir")"
        manifest_path="${run_dir}/manifest.json"
        age="$(age_days_for_backup_id "$backup_id")"
        size="$(dir_size_bytes "$run_dir")"

        if [[ "$age" -le "$BACKUP_LOCAL_RETENTION_DAYS" ]]; then
            KEEP_IDS+=("$backup_id")
            KEEP_REASONS+=("within-${BACKUP_LOCAL_RETENTION_DAYS}d")
            log "KEEP   ${backup_id}  age=${age}  size=${size}  within-${BACKUP_LOCAL_RETENTION_DAYS}d  cloud=not-required-for-retention"
            continue
        fi

        if validate_local_run "$run_dir" "$backup_id" "$manifest_path"; then
            DELETE_IDS+=("$backup_id")
            DELETE_REASONS+=("cloud-verified-expired")
            DELETE_PATHS+=("$run_dir")
            DELETE_SIZES+=("$size")
            log "DELETE ${backup_id}  age=${age}  size=${size}  cloud-verified  eligible-after-${BACKUP_LOCAL_RETENTION_DAYS}d"
        else
            KEEP_IDS+=("$backup_id")
            KEEP_REASONS+=("${ITEM_SKIP_REASON:-unknown}")
            log "KEEP   ${backup_id}  age=${age}  size=${size}  cloud=FAILED  reason=${ITEM_SKIP_REASON:-unknown}"
        fi
    done
    shopt -u nullglob
}

delete_local_run() {
    local run_dir="$1"
    local backup_id="$2"
    local manifest_path="${run_dir}/manifest.json"
    local name

    if ! validate_local_run "$run_dir" "$backup_id" "$manifest_path"; then
        die "backup ${backup_id} failed re-validation before delete; aborting"
    fi

    for name in manifest.json database.sql.gz.gpg database.sql.gz.age secrets.tar.gz.gpg secrets.tar.gz.age; do
        if [[ -f "${run_dir}/${name}" ]]; then
            if [[ -L "${run_dir}/${name}" ]]; then
                die "refusing to delete symlink ${name} in ${backup_id}"
            fi
            rm -f -- "${run_dir}/${name}"
        fi
    done

    if find "$run_dir" -mindepth 1 -maxdepth 1 -print | grep -q .; then
        die "unexpected leftover files in ${backup_id}; aborting"
    fi

    rmdir -- "$run_dir"
}

execute_deletes() {
    local i id path

    if [[ ${#DELETE_IDS[@]} -eq 0 ]]; then
        log "no local runs eligible for deletion"
        return 0
    fi

    i=0
    while [[ $i -lt ${#DELETE_IDS[@]} ]]; do
        id="${DELETE_IDS[$i]}"
        path="${DELETE_PATHS[$i]}"
        log "deleting ${id}  ${path}"
        if ! delete_local_run "$path" "$id"; then
            die "deletion failed for ${id}; aborting"
        fi
        log "deleted ${id}"
        i=$((i + 1))
    done
}

summarize() {
    local mode bytes=0 i

    if [[ "$EXECUTE" -eq 1 ]]; then
        mode="execute"
    else
        mode="dry-run"
    fi

    if [[ ${#DELETE_SIZES[@]} -gt 0 ]]; then
        i=0
        while [[ $i -lt ${#DELETE_SIZES[@]} ]]; do
            bytes=$((bytes + DELETE_SIZES[$i]))
            i=$((i + 1))
        done
    fi

    log "as_of=${AS_OF_ID} mode=${mode} retention_days=${BACKUP_LOCAL_RETENTION_DAYS}"
    log "keep=${#KEEP_IDS[@]} delete=${#DELETE_IDS[@]} bytes_to_free=${bytes}"
    if [[ "$EXECUTE" -ne 1 ]]; then
        log "no local runs were deleted (dry-run)"
    fi
}

main() {
    parse_args "$@"
    detect_date_flavor
    resolve_cloud_config
    resolve_as_of

    PRUNE_TEMP="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-prune-local.XXXXXX")"
    chmod 700 "$PRUNE_TEMP"

    if [[ "$EXECUTE" -eq 1 ]]; then
        log "EXECUTE mode — eligible cloud-verified local runs will be deleted"
    else
        log "dry-run — no local runs will be deleted"
    fi

    classify_runs
    summarize

    if [[ "$EXECUTE" -eq 1 ]]; then
        execute_deletes
        log "local prune execute completed"
    fi
}

main "$@"
