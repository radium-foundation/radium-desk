#!/usr/bin/env bash
#
# Radium Desk — Cloud backup retention / pruning (standalone).
#
# Enumerates completed Hostinger Cloud backups over SSH and applies GFS
# retention. Dry-run by default. --execute is required to delete.
#
# Does not run backups, does not touch local KVM runs/, and does not
# modify work/ or uploading-* directories.
#
# See docs/backup-runbook.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SSH_BIN="${SSH_BIN:-$(command -v ssh || true)}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

PRUNE_TEMP=""
EXECUTE=0
DRY_RUN_FLAG=0
DATE_FLAVOR=""
AS_OF_ID=""
AS_OF_YMD=""
AS_OF_EPOCH=""

COMPLETED_IDS=()
COMPLETED_PATHS=()
COMPLETED_AGES=()
COMPLETED_SIZES=()

SKIP_PATHS=()
SKIP_REASONS=()

KEEP_IDS=()
KEEP_REASONS=()
DELETE_IDS=()
DELETE_REASONS=()
DELETE_PATHS=()
DELETE_SIZES=()

ITEM_SKIP_REASON=""
ITEM_ID=""
ITEM_PATH=""
ITEM_AGE=""
ITEM_SIZE=""

log() {
    echo "backup-prune-cloud.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage: backup-prune-cloud.sh [--dry-run | --execute]

Cloud backup retention (GFS). Dry-run by default; --execute required to delete.

Environment:
  BACKUP_CLOUD_SSH_HOST
  BACKUP_CLOUD_SSH_USER
  BACKUP_CLOUD_SSH_PORT              (default 65002)
  BACKUP_CLOUD_SSH_IDENTITY_FILE     (optional)
  BACKUP_CLOUD_REMOTE_ROOT
  BACKUP_PRUNE_AS_OF                 (optional UTC backup_id used as "now")
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

array_contains() {
    local needle="$1"
    shift || true
    local item
    for item in "$@"; do
        if [[ "$item" == "$needle" ]]; then
            return 0
        fi
    done
    return 1
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

utc_weekday() {
    local ymd="$1"

    case "$DATE_FLAVOR" in
        gnu) date -u -d "${ymd}" +%u ;;
        bsd) date -u -j -f "%Y-%m-%d" "${ymd}" +%u ;;
        *) die "date flavor not detected" ;;
    esac
}

utc_iso_week() {
    local ymd="$1"

    case "$DATE_FLAVOR" in
        gnu) date -u -d "${ymd}" +%G-W%V ;;
        bsd) date -u -j -f "%Y-%m-%d" "${ymd}" +%G-W%V ;;
        *) die "date flavor not detected" ;;
    esac
}

ymd_from_backup_id() {
    local backup_id="$1"
    printf '%s-%s-%s' "${backup_id:0:4}" "${backup_id:4:2}" "${backup_id:6:2}"
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
            --execute)
                EXECUTE=1
                ;;
            --dry-run)
                DRY_RUN_FLAG=1
                ;;
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

resolve_cloud_config() {
    if [[ -z "$SSH_BIN" ]]; then
        die "ssh is required for Cloud prune but was not found on PATH"
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

skip_item() {
    local path="$1"
    local reason="$2"
    SKIP_PATHS+=("$path")
    SKIP_REASONS+=("$reason")
    log "SKIP   ${path}  ${reason}"
}

record_completed() {
    local backup_id="$1"
    local path="$2"
    local age="$3"
    local size="$4"

    COMPLETED_IDS+=("$backup_id")
    COMPLETED_PATHS+=("$path")
    COMPLETED_AGES+=("$age")
    COMPLETED_SIZES+=("$size")
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
    local php_status marker_file manifest_file manifest_sha age

    ITEM_SKIP_REASON=""
    ITEM_ID=""
    ITEM_PATH=""
    ITEM_AGE=""
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

    marker_file="${PRUNE_TEMP}/marker.json"
    manifest_file="${PRUNE_TEMP}/manifest.json"
    fetch_remote_regular_file "${path}/upload-complete.json" "$marker_file"
    fetch_remote_regular_file "${path}/manifest.json" "$manifest_file"
    manifest_sha="$(sha256_file "$manifest_file")"

    set +e
    php_validate_completed_json "$marker_file" "$manifest_file" "$backup_id" "$path" "$manifest_sha" 2>"${PRUNE_TEMP}/php.err"
    php_status=$?
    set -e

    if [[ "$php_status" -ne 0 ]]; then
        ITEM_SKIP_REASON="validation-failed:$(tr '\n' ' ' <"${PRUNE_TEMP}/php.err" | sed 's/[[:space:]]*$//')"
        return 1
    fi

    age="$(age_days_for_backup_id "$backup_id")"
    ITEM_ID="$backup_id"
    ITEM_PATH="$path"
    ITEM_AGE="$age"
    ITEM_SIZE="$total_size"
    return 0
}

classify_leaf() {
    local path="$1"

    if validate_completed_leaf "$path"; then
        record_completed "$ITEM_ID" "$ITEM_PATH" "$ITEM_AGE" "$ITEM_SIZE"
        return 0
    fi

    skip_item "$path" "$ITEM_SKIP_REASON"
}

index_of_completed_id() {
    local needle="$1"
    local i=0

    while [[ $i -lt ${#COMPLETED_IDS[@]} ]]; do
        if [[ "${COMPLETED_IDS[$i]}" == "$needle" ]]; then
            printf '%s' "$i"
            return 0
        fi
        i=$((i + 1))
    done
    return 1
}

compute_retention() {
    local newest_id="" newest_idx i id age path size weekday iso_week day
    local seen_days="" seen_weeks="" reason action
    local -a sorted_ids=()

    KEEP_IDS=()
    KEEP_REASONS=()
    DELETE_IDS=()
    DELETE_REASONS=()
    DELETE_PATHS=()
    DELETE_SIZES=()

    if [[ ${#COMPLETED_IDS[@]} -eq 0 ]]; then
        return 0
    fi

    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        sorted_ids+=("$id")
    done < <(printf '%s\n' "${COMPLETED_IDS[@]}" | sort)

    newest_id="${sorted_ids[${#sorted_ids[@]}-1]}"

    i=$((${#sorted_ids[@]} - 1))
    while [[ $i -ge 0 ]]; do
        id="${sorted_ids[$i]}"
        newest_idx="$(index_of_completed_id "$id")"
        age="${COMPLETED_AGES[$newest_idx]}"
        path="${COMPLETED_PATHS[$newest_idx]}"
        size="${COMPLETED_SIZES[$newest_idx]}"
        day="${id:0:8}"
        weekday="$(utc_weekday "$(ymd_from_backup_id "$id")")"
        iso_week="$(utc_iso_week "$(ymd_from_backup_id "$id")")"
        action="delete"
        reason=""

        if [[ "$id" == "$newest_id" ]]; then
            action="keep"
            reason="newest"
            if [[ "$age" -ge 8 && "$age" -le 30 ]]; then
                seen_days="${seen_days} ${day}"
            fi
            if [[ "$age" -ge 31 && "$age" -le 90 && "$weekday" == "7" ]]; then
                seen_weeks="${seen_weeks} ${iso_week}"
            fi
        elif [[ "$age" -le 7 ]]; then
            action="keep"
            reason="within-7d"
        elif [[ "$age" -le 30 ]]; then
            if array_contains "$day" $seen_days; then
                action="delete"
                reason="superseded-daily"
            else
                action="keep"
                reason="daily-one"
                seen_days="${seen_days} ${day}"
            fi
        elif [[ "$age" -le 90 ]]; then
            if [[ "$weekday" == "7" ]]; then
                if array_contains "$iso_week" $seen_weeks; then
                    action="delete"
                    reason="superseded-weekly"
                else
                    action="keep"
                    reason="weekly-sunday"
                    seen_weeks="${seen_weeks} ${iso_week}"
                fi
            else
                action="delete"
                reason="superseded-weekly"
            fi
        else
            action="delete"
            reason="expired-over-90d"
        fi

        if [[ "$action" == "keep" ]]; then
            KEEP_IDS+=("$id")
            KEEP_REASONS+=("$reason")
            log "KEEP   ${id}  age=${age}  ${reason}  ${path}"
        else
            DELETE_IDS+=("$id")
            DELETE_REASONS+=("$reason")
            DELETE_PATHS+=("$path")
            DELETE_SIZES+=("$size")
            log "DELETE ${id}  age=${age}  ${reason}  ${path}"
        fi

        i=$((i - 1))
    done
}

delete_remote_backup() {
    local path="$1"
    local backup_id="$2"
    local year month day day_dir month_dir year_dir

    year="${backup_id:0:4}"
    month="${backup_id:4:2}"
    day="${backup_id:6:2}"
    day_dir="${BACKUP_CLOUD_REMOTE_ROOT}/${year}/${month}/${day}"
    month_dir="${BACKUP_CLOUD_REMOTE_ROOT}/${year}/${month}"
    year_dir="${BACKUP_CLOUD_REMOTE_ROOT}/${year}"

    assert_path_under_remote_root "$path" || die "refusing to delete path outside remote root: ${path}"
    [[ "$path" == "${day_dir}/${backup_id}" ]] || die "delete path does not match backup_id layout: ${path}"

    if ! validate_completed_leaf "$path"; then
        die "backup ${backup_id} failed re-validation before delete (${ITEM_SKIP_REASON}); aborting"
    fi
    if [[ "$ITEM_ID" != "$backup_id" ]]; then
        die "backup ${backup_id} re-validation returned a different id; aborting"
    fi

    remote_ssh_exec "
        set -euo pipefail
        dir='${path}'
        root='${BACKUP_CLOUD_REMOTE_ROOT}'
        day_dir='${day_dir}'
        month_dir='${month_dir}'
        year_dir='${year_dir}'

        case \"\$dir\" in
            \"\$root\"/*) ;;
            *) echo 'path outside remote root' >&2; exit 1 ;;
        esac

        if [ -L \"\$dir\" ]; then echo 'refusing to delete symlink directory' >&2; exit 1; fi
        if [ ! -d \"\$dir\" ]; then echo 'delete target is not a directory' >&2; exit 1; fi

        for name in manifest.json upload-complete.json database.sql.gz.gpg database.sql.gz.age secrets.tar.gz.gpg secrets.tar.gz.age; do
            p=\"\$dir/\$name\"
            if [ -L \"\$p\" ]; then
                echo \"refusing to delete symlink \$name\" >&2
                exit 1
            fi
            if [ -e \"\$p\" ]; then
                if [ ! -f \"\$p\" ]; then
                    echo \"refusing to delete non-file \$name\" >&2
                    exit 1
                fi
                rm -f -- \"\$p\"
            fi
        done

        leftover=\$(find -P \"\$dir\" -mindepth 1 -maxdepth 1 -print)
        if [ -n \"\$leftover\" ]; then
            echo 'unexpected leftover files after allowlist delete; aborting' >&2
            exit 1
        fi

        rmdir -- \"\$dir\"

        rmdir -- \"\$day_dir\" 2>/dev/null || true
        rmdir -- \"\$month_dir\" 2>/dev/null || true
        rmdir -- \"\$year_dir\" 2>/dev/null || true
    "
}

execute_deletes() {
    local i id path

    if [[ ${#DELETE_IDS[@]} -eq 0 ]]; then
        log "no completed backups eligible for deletion"
        return 0
    fi

    i=0
    while [[ $i -lt ${#DELETE_IDS[@]} ]]; do
        id="${DELETE_IDS[$i]}"
        path="${DELETE_PATHS[$i]}"
        log "deleting ${id}  ${path}"
        if ! delete_remote_backup "$path" "$id"; then
            die "deletion failed for ${id} at ${path}; aborting"
        fi
        log "deleted ${id}"
        i=$((i + 1))
    done
}

summarize() {
    local mode bytes=0 i
    local keep_count=0 delete_count=0 skip_count=0 completed_count=0

    if [[ "$EXECUTE" -eq 1 ]]; then
        mode="execute"
    else
        mode="dry-run"
    fi

    completed_count=${#COMPLETED_IDS[@]}
    skip_count=${#SKIP_PATHS[@]}
    keep_count=${#KEEP_IDS[@]}
    delete_count=${#DELETE_IDS[@]}

    if [[ "$delete_count" -gt 0 ]]; then
        i=0
        while [[ $i -lt ${#DELETE_SIZES[@]} ]]; do
            bytes=$((bytes + DELETE_SIZES[$i]))
            i=$((i + 1))
        done
    fi

    log "as_of=${AS_OF_ID} mode=${mode}"
    log "completed=${completed_count} skipped=${skip_count} keep=${keep_count} delete=${delete_count} bytes_to_free=${bytes}"
    if [[ "$EXECUTE" -ne 1 ]]; then
        log "no remote files were deleted (dry-run)"
    fi
}

main() {
    parse_args "$@"
    detect_date_flavor
    resolve_cloud_config
    resolve_as_of

    PRUNE_TEMP="$(mktemp -d "${TMPDIR:-/tmp}/radium-backup-prune.XXXXXX")"
    chmod 700 "$PRUNE_TEMP"

    if [[ "$EXECUTE" -eq 1 ]]; then
        log "EXECUTE mode — eligible completed Cloud backups will be deleted"
    else
        log "dry-run — no Cloud backups will be deleted"
    fi

    ensure_remote_root

    local paths_raw path
    paths_raw="$(enumerate_leaf_paths || true)"

    if [[ -n "$paths_raw" ]]; then
        while IFS= read -r path; do
            [[ -n "$path" ]] || continue
            classify_leaf "$path"
        done <<<"$paths_raw"
    fi

    compute_retention
    summarize

    if [[ "$EXECUTE" -eq 1 ]]; then
        execute_deletes
        log "Cloud prune execute completed"
    fi
}

main "$@"
