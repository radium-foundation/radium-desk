#!/usr/bin/env bash
#
# Radium Desk — scheduled backup cron wrapper.
#
# Acquires a non-blocking flock, runs bin/backup-run.sh (unchanged), and writes a
# sanitized last-run-status.json for ProductionWatchdogService / Telegram alerts.
#
# Does not modify backup artifacts, credentials, or remote storage.
# See docs/backup-runbook.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
BACKUP_STAGING_ROOT="${BACKUP_STAGING_ROOT:-/var/backups/radium-desk}"
BACKUP_STATUS_PATH="${BACKUP_STATUS_PATH:-${BACKUP_STAGING_ROOT%/}/last-run-status.json}"
BACKUP_LOCK_FILE="${BACKUP_LOCK_FILE:-/var/lock/radium-backup.lock}"
BACKUP_RUN_SCRIPT="${BACKUP_RUN_SCRIPT:-${ROOT}/bin/backup-run.sh}"
BACKUP_ENV_FILE="${BACKUP_ENV_FILE:-}"

RUN_LOG=""
STARTED_AT_EPOCH=""

log() {
    echo "backup-schedule.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage: backup-schedule.sh

Cron wrapper for bin/backup-run.sh with flock and sanitized status output.

Environment:
  BACKUP_STAGING_ROOT          (default /var/backups/radium-desk)
  BACKUP_STATUS_PATH           (default {staging}/last-run-status.json)
  BACKUP_LOCK_FILE             (default /var/lock/radium-backup.lock)
  BACKUP_SCHEDULE_SKIP_LOCK    (set true when an outer cron flock already holds BACKUP_LOCK_FILE)
  BACKUP_ENV_FILE              (optional shell env file to source)
  BACKUP_RUN_SCRIPT            (default ./bin/backup-run.sh)
  BACKUP_MANIFEST_ACL_ENABLED  (default true)
  BACKUP_MANIFEST_ACL_USER     (default ravi)
  PHP_BIN

Production cron example (outer flock + matching lock path):
  BACKUP_LOCK_FILE=/var/lock/radium-desk-backup.lock BACKUP_SCHEDULE_SKIP_LOCK=true
EOF
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

ensure_prerequisites() {
    [[ -n "$PHP_BIN" ]] || die "php is required to write backup status JSON"
    [[ -x "$BACKUP_RUN_SCRIPT" ]] || die "backup run script is not executable: ${BACKUP_RUN_SCRIPT}"
}

load_env_file() {
    if [[ -z "$BACKUP_ENV_FILE" ]]; then
        return 0
    fi

    if [[ ! -r "$BACKUP_ENV_FILE" ]]; then
        die "BACKUP_ENV_FILE is not readable"
    fi

    # shellcheck disable=SC1090
    set -a
    source "$BACKUP_ENV_FILE"
    set +a
}

sanitize_summary() {
    local raw="${1:-}"
    local line

    raw="$(printf '%s' "$raw" | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"

    if [[ -z "$raw" ]]; then
        printf ''
        return 0
    fi

  # Drop backup-run.sh prefix for a shorter operator-facing summary.
    raw="${raw#backup-run.sh: }"
    raw="${raw#backup-run.sh: ERROR: }"
    raw="${raw#ERROR: }"

  # Remove absolute paths and common secret-bearing tokens.
    raw="$(printf '%s' "$raw" | sed -E \
        -e 's#/root/[^[:space:]]+#[path-redacted]#g' \
        -e 's#/var/[^[:space:]]+#[path-redacted]#g' \
        -e 's#/home/[^[:space:]]+#[path-redacted]#g' \
        -e 's#\.env[^[:space:]]*#[env-redacted]#g' \
        -e 's#[Pp]assphrase[^[:space:]]*#[secret-redacted]#g' \
        -e 's#[Pp]assword[^[:space:]]*#[secret-redacted]#g' \
        -e 's#@[0-9.]+:[0-9]+:[^[:space:]]+#[remote-redacted]#g' \
        -e 's#host=[^, )]+#host=[redacted]#g' \
        -e 's#remote=[^, )]+#remote=[redacted]#g')"

    line="$(printf '%.240s' "$raw")"
    printf '%s' "$line"
}

extract_backup_id() {
    local log_file="$1"
    local line id

    id="$(grep -E 'starting backup [0-9]{8}T[0-9]{6}Z' "$log_file" 2>/dev/null | tail -n 1 | sed -E 's/.*starting backup ([0-9]{8}T[0-9]{6}Z).*/\1/' || true)"
    if [[ "$id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
        printf '%s' "$id"
        return 0
    fi

    line="$(grep -E 'local backup completed:' "$log_file" 2>/dev/null | tail -n 1 || true)"
    if [[ -n "$line" ]]; then
        id="$(printf '%s' "$line" | sed -E 's#.*/([0-9]{8}T[0-9]{6}Z)/?$#\1#')"
        if [[ "$id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
            printf '%s' "$id"
            return 0
        fi
    fi

    printf ''
}

extract_error_summary() {
    local log_file="$1"
    local line

    line="$(grep -E 'backup-run\.sh: ERROR:' "$log_file" 2>/dev/null | tail -n 1 || true)"
    if [[ -z "$line" ]]; then
        line="$(grep -E 'backup-run\.sh: ' "$log_file" 2>/dev/null | tail -n 1 || true)"
    fi

    sanitize_summary "$line"
}

classify_outcome() {
    local exit_code="$1"
    local log_file="$2"
    local cloud_enabled="${BACKUP_CLOUD_UPLOAD_ENABLED:-}"

    case "$(printf '%s' "$cloud_enabled" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) cloud_enabled="true" ;;
        *) cloud_enabled="false" ;;
    esac

    if grep -q 'backup completed with Cloud upload:' "$log_file" 2>/dev/null; then
        printf 'success'
        return 0
    fi

    if [[ "$exit_code" -eq 0 ]] && grep -q 'local backup completed:' "$log_file" 2>/dev/null; then
        printf 'success'
        return 0
    fi

    if grep -q 'local backup completed:' "$log_file" 2>/dev/null; then
        if [[ "$cloud_enabled" == "true" ]]; then
            printf 'cloud_upload_failure'
            return 0
        fi

        printf 'success'
        return 0
    fi

    printf 'local_failure'
}

resolve_phase() {
    local outcome="$1"
    local log_file="$2"

    if [[ "$outcome" != "success" ]]; then
        printf ''
        return 0
    fi

    if grep -q 'backup completed with Cloud upload:' "$log_file" 2>/dev/null; then
        printf 'cloud_uploaded'
        return 0
    fi

    printf 'local_staging'
}

truthy_env() {
    local value="${1:-}"

    case "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

LOCK_DIR=""

release_schedule_lock() {
    if [[ -n "$LOCK_DIR" && -d "$LOCK_DIR" ]]; then
        rmdir "$LOCK_DIR" 2>/dev/null || true
        LOCK_DIR=""
    fi
}

status_read_acl_enabled() {
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"

    [[ -n "$acl_user" ]] && truthy_env "${BACKUP_MANIFEST_ACL_ENABLED:-true}"
}

restore_staging_traversal_acls() {
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"
    local runs_root="${BACKUP_STAGING_ROOT}/runs"

    if ! status_read_acl_enabled; then
        return 0
    fi

    if ! command -v setfacl >/dev/null 2>&1; then
        log "ERROR: setfacl not available; staging traversal ACLs not restored"

        return 1
    fi

    mkdir -p "$runs_root"
    chmod 700 "$BACKUP_STAGING_ROOT" 2>/dev/null || true
    chmod 700 "$runs_root" 2>/dev/null || true

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

apply_status_read_acl() {
    local status_path="$1"
    local acl_user="${BACKUP_MANIFEST_ACL_USER:-ravi}"

    if ! status_read_acl_enabled; then
        return 0
    fi

    if [[ ! -f "$status_path" ]]; then
        log "ERROR: status ACL: file missing"

        return 1
    fi

    if [[ "$(basename "$status_path")" != "last-run-status.json" ]]; then
        log "ERROR: status ACL: unexpected filename"

        return 1
    fi

    if ! command -v setfacl >/dev/null 2>&1; then
        log "ERROR: setfacl not available; status read ACL not applied"

        return 1
    fi

    restore_staging_traversal_acls \
        || log "ERROR: staging traversal ACLs were not restored"

    if setfacl -m "u:${acl_user}:r,m:r" "$status_path"; then
        log "status read ACL applied for ${acl_user}"
        return 0
    fi

    log "ERROR: setfacl failed for status file (exit $?)"
    return 1
}

set_watchdog_accessible_flag() {
    local status_path="$1"
    local accessible="$2"

    [[ -n "$PHP_BIN" ]] || return 1

    export BACKUP_STATUS_PATH="$status_path"
    export BACKUP_STATUS_WATCHDOG_ACCESSIBLE="$accessible"

    "$PHP_BIN" -r '
        $path = getenv("BACKUP_STATUS_PATH") ?: "";
        $accessible = (getenv("BACKUP_STATUS_WATCHDOG_ACCESSIBLE") ?: "false") === "true";
        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (! is_array($data)) {
            fwrite(STDERR, "invalid status json\n");
            exit(1);
        }
        $data["watchdog_accessible"] = $accessible;
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    '
}

finalize_status_file() {
    local status_path="$1"
    local watchdog_accessible="false"

    if status_read_acl_enabled; then
        if apply_status_read_acl "$status_path"; then
            watchdog_accessible="true"
        else
            log "ERROR: watchdog alerting unavailable — status read ACL was not applied"
        fi
    else
        watchdog_accessible="true"
    fi

    set_watchdog_accessible_flag "$status_path" "$watchdog_accessible" \
        || log "ERROR: failed to update watchdog_accessible flag in status file"

    if [[ "$watchdog_accessible" == "true" ]]; then
        return 0
    fi

    return 1
}

try_acquire_schedule_lock() {
    if truthy_env "${BACKUP_SCHEDULE_SKIP_LOCK:-}"; then
        return 0
    fi

    local flock_bin="${FLOCK_BIN:-$(command -v flock 2>/dev/null || true)}"

    if [[ -n "$flock_bin" ]]; then
        exec 9>"$BACKUP_LOCK_FILE"
        if "$flock_bin" -n 9; then
            return 0
        fi

        return 1
    fi

    LOCK_DIR="${BACKUP_LOCK_FILE}.lock.d"
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        return 0
    fi

    LOCK_DIR=""
    return 1
}

cloud_upload_enabled_flag() {
    if truthy_env "${BACKUP_CLOUD_UPLOAD_ENABLED:-}"; then
        printf 'true'
    else
        printf 'false'
    fi
}

write_status_json() {
    local outcome="$1"
    local exit_code="$2"
    local duration_seconds="$3"
    local lock_acquired="$4"
    local backup_id="$5"
    local phase="$6"
    local error_summary="$7"
    local generated_at
  local cloud_upload_enabled
  local output_path="$BACKUP_STATUS_PATH"
  local temp_path="${output_path}.tmp.$$"

    generated_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    cloud_upload_enabled="$(cloud_upload_enabled_flag)"

    mkdir -p "$(dirname "$output_path")"
    chmod 700 "$(dirname "$output_path")" 2>/dev/null || true

    export BACKUP_STATUS_GENERATED_AT="$generated_at"
    export BACKUP_STATUS_OUTCOME="$outcome"
    export BACKUP_STATUS_EXIT_CODE="$exit_code"
    export BACKUP_STATUS_DURATION="$duration_seconds"
    export BACKUP_STATUS_LOCK_ACQUIRED="$lock_acquired"
    export BACKUP_STATUS_BACKUP_ID="$backup_id"
    export BACKUP_STATUS_PHASE="$phase"
    export BACKUP_STATUS_ERROR_SUMMARY="$error_summary"
    export BACKUP_STATUS_CLOUD_UPLOAD_ENABLED="$cloud_upload_enabled"
    export BACKUP_STATUS_OUTPUT="$temp_path"

    "$PHP_BIN" -r '
        $output = getenv("BACKUP_STATUS_OUTPUT") ?: "";
        $backupId = getenv("BACKUP_STATUS_BACKUP_ID") ?: "";
        $phase = getenv("BACKUP_STATUS_PHASE") ?: "";
        $errorSummary = getenv("BACKUP_STATUS_ERROR_SUMMARY") ?: "";

        if ($backupId !== "" && ! preg_match("/^[0-9]{8}T[0-9]{6}Z$/", $backupId)) {
            $backupId = "";
        }

        if ($phase !== "" && ! in_array($phase, ["local_staging", "cloud_uploaded"], true)) {
            $phase = "";
        }

        $exitCode = getenv("BACKUP_STATUS_EXIT_CODE");

        $payload = [
            "version" => 1,
            "generated_at" => getenv("BACKUP_STATUS_GENERATED_AT") ?: gmdate("c"),
            "outcome" => getenv("BACKUP_STATUS_OUTCOME") ?: "local_failure",
            "exit_code" => (int) ($exitCode !== false ? $exitCode : "1"),
            "duration_seconds" => max(0, (int) (getenv("BACKUP_STATUS_DURATION") ?: "0")),
            "lock_acquired" => (getenv("BACKUP_STATUS_LOCK_ACQUIRED") ?: "false") === "true",
            "cloud_upload_enabled" => (getenv("BACKUP_STATUS_CLOUD_UPLOAD_ENABLED") ?: "false") === "true",
            "watchdog_accessible" => false,
            "backup_id" => $backupId !== "" ? $backupId : null,
            "phase" => $phase !== "" ? $phase : null,
            "error_summary" => $errorSummary !== "" ? $errorSummary : null,
        ];

        file_put_contents(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    '

    chmod 600 "$temp_path"
    mv -f "$temp_path" "$output_path"
    finalize_status_file "$output_path" \
        || log "ERROR: status file written but watchdog alerting is unavailable"
}

write_lock_overlap_status() {
    local duration_seconds="$1"

    write_status_json \
        "lock_overlap" \
        0 \
        "$duration_seconds" \
        "false" \
        "" \
        "" \
        "Another backup run is still holding the schedule lock."
}

main() {
    parse_args "$@"
    ensure_prerequisites
    load_env_file

    STARTED_AT_EPOCH="$(date +%s)"
    RUN_LOG="$(mktemp "${TMPDIR:-/tmp}/radium-backup-schedule.XXXXXX.log")"
    chmod 600 "$RUN_LOG"
    trap 'release_schedule_lock; rm -f "$RUN_LOG"' EXIT

    mkdir -p "$(dirname "$BACKUP_LOCK_FILE")" 2>/dev/null || true

    if ! try_acquire_schedule_lock; then
        local duration=$(( $(date +%s) - STARTED_AT_EPOCH ))
        write_lock_overlap_status "$duration"
        log "skipped — schedule lock is held (${BACKUP_LOCK_FILE})"
        exit 0
    fi

    local exit_code=0
    set +e
    "$BACKUP_RUN_SCRIPT" >"$RUN_LOG" 2>&1
    exit_code=$?
    set -e

    local duration=$(( $(date +%s) - STARTED_AT_EPOCH ))
    local outcome backup_id phase error_summary

    outcome="$(classify_outcome "$exit_code" "$RUN_LOG")"
    backup_id="$(extract_backup_id "$RUN_LOG")"
    phase="$(resolve_phase "$outcome" "$RUN_LOG")"
    error_summary="$(extract_error_summary "$RUN_LOG")"

    if [[ "$outcome" == "success" ]]; then
        error_summary=""
    fi

    write_status_json \
        "$outcome" \
        "$exit_code" \
        "$duration" \
        "true" \
        "$backup_id" \
        "$phase" \
        "$error_summary"

    local watchdog_ready=0
    if [[ -f "$BACKUP_STATUS_PATH" ]] \
        && grep -q '"watchdog_accessible": true' "$BACKUP_STATUS_PATH" 2>/dev/null; then
        watchdog_ready=1
    fi

    case "$outcome" in
        success)
            if [[ "$watchdog_ready" -eq 1 ]]; then
                log "completed (${backup_id:-unknown id}, phase=${phase:-n/a}; watchdog status readable)"
            else
                log "completed (${backup_id:-unknown id}, phase=${phase:-n/a}; watchdog alerting unavailable)"
            fi
            exit 0
            ;;
        cloud_upload_failure)
            log "cloud upload failed after local staging (${backup_id:-unknown id})"
            exit "$exit_code"
            ;;
        local_failure)
            log "local staging failed"
            exit "$exit_code"
            ;;
        *)
            log "backup finished with outcome=${outcome}"
            exit "$exit_code"
            ;;
    esac
}

main "$@"
