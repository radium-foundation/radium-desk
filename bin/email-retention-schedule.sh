#!/usr/bin/env bash
#
# Radium Desk — recurring inbound email retention cron wrapper.
#
# Serializes daily dry-runs and weekly approved deletions with flock.
# See docs/storage-lifecycle-phase4g-email-retention-schedule.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
RETENTION_LOCK_FILE="${RETENTION_LOCK_FILE:-/var/lock/radium-desk-email-retention.lock}"
RETENTION_LOG="${RETENTION_LOG:-${ROOT}/storage/logs/email-retention-schedule.log}"
RETENTION_SKIP_LOCK="${RETENTION_SKIP_LOCK:-false}"

MODE=""

log() {
    echo "email-retention-schedule.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage: email-retention-schedule.sh (--dry-run-only | --weekly-execute)

Recurring inbound email retention wrapper with non-blocking flock.

Environment:
  RETENTION_EMAIL_SCHEDULER_ENABLED=true   (required — enables orchestrator command)
  RETENTION_EMAIL_SCHEDULE_SKIP_LOCK=true  (optional — outer cron already holds lock)
  RETENTION_LOCK_FILE                      (default /var/lock/radium-desk-email-retention.lock)
  RETENTION_LOG                            (default storage/logs/email-retention-schedule.log)
  PHP_BIN
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --dry-run-only)
                MODE="dry-run-only"
                ;;
            --weekly-execute)
                MODE="weekly-execute"
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

    if [[ -z "$MODE" ]]; then
        usage
        die "pass exactly one of --dry-run-only or --weekly-execute"
    fi
}

ensure_prerequisites() {
    [[ -n "$PHP_BIN" ]] || die "php is required"
    [[ -x "${ROOT}/artisan" ]] || die "artisan is missing"
}

run_artisan() {
    local artisan_args=()

    if [[ "$MODE" == "dry-run-only" ]]; then
        artisan_args=(database:retention-email-schedule --dry-run-only)
    else
        artisan_args=(database:retention-email-schedule --weekly-execute)
    fi

    RETENTION_EMAIL_SCHEDULER_ENABLED=true \
    RETENTION_EMAIL_SCHEDULE_SKIP_LOCK=true \
    "$PHP_BIN" artisan "${artisan_args[@]}"
}

acquire_lock() {
    if [[ "$RETENTION_SKIP_LOCK" == "true" ]]; then
        return 0
    fi

    local flock_bin
    flock_bin="$(command -v flock || true)"
    [[ -n "$flock_bin" ]] || die "flock is required"

    exec 9>"${RETENTION_LOCK_FILE}"
    if ! flock -n 9; then
        die "concurrent email retention process detected"
    fi
}

main() {
    parse_args "$@"
    ensure_prerequisites
    acquire_lock
    mkdir -p "$(dirname "$RETENTION_LOG")"
    {
        log "starting mode=${MODE}"
        run_artisan
        log "completed mode=${MODE}"
    } >>"$RETENTION_LOG" 2>&1
}

main "$@"
