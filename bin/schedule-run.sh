#!/usr/bin/env bash
#
# Hostinger Cron #1 entrypoint for Laravel's scheduler.
#
# Hostinger wraps cron commands with:
#   flock -w 1 /tmp/cron_lock_<id> timeout -s 9 1800 <command>
# The lock FD is inherited by PHP. Laravel runInBackground() children inherit
# it too; a hung child (e.g. inbound-email:sync-gmail) keeps the flock held
# after schedule:run exits, so later flock -w 1 ticks skip entirely.
#
# This wrapper closes only inherited cron_lock* descriptors (never 0/1/2),
# then execs `php artisan schedule:run` so PHP and its background children
# never hold the host flock. Hostinger's flock parent still serializes until
# this process exits; that is intentional.
#
# Do NOT attempt to release the lock from PHP via fopen('php://fd/N') —
# PHP always dup()s that FD and fclose only closes the duplicate.
# See docs/hostinger-scheduler-cron-wrapper.md.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
    PHP_BIN="$(command -v php || true)"
fi
if [[ -z "${PHP_BIN}" || ! -x "$PHP_BIN" ]]; then
    echo "schedule-run.sh: PHP binary not found (set PHP_BIN)" >&2
    exit 127
fi

release_inherited_cron_locks() {
    local fdpath fd target base
    local -a to_close=()

    [[ -d /proc/$$/fd ]] || return 0

    # Collect first — closing while iterating /proc/self/fd is unsafe.
    for fdpath in /proc/$$/fd/*; do
        [[ -e "$fdpath" || -L "$fdpath" ]] || continue
        fd="$(basename "$fdpath")"
        [[ "$fd" =~ ^[0-9]+$ ]] || continue
        if (( fd < 3 )); then
            continue
        fi

        target="$(readlink "$fdpath" 2>/dev/null || true)"
        [[ -n "$target" ]] || continue
        base="$(basename "$target")"

        # Hostinger: /tmp/cron_lock_<id> — and generic */cron_lock* paths.
        if [[ "$base" == cron_lock* || "$target" == */cron_lock* ]]; then
            to_close+=("$fd")
        fi
    done

    for fd in "${to_close[@]+"${to_close[@]}"}"; do
        # Write-opened locks need >&-; read-only opens need <&-. Try both.
        eval "exec ${fd}>&-" 2>/dev/null || true
        eval "exec ${fd}<&-" 2>/dev/null || true
    done
}

release_inherited_cron_locks

exec "$PHP_BIN" artisan schedule:run "$@"
