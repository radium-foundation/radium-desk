#!/usr/bin/env bash
#
# Regression: Hostinger cron flock FDs must be closable via bash exec N>&-,
# and must NOT be releasable via PHP fopen('php://fd/N') + fclose.
#
# Run: bash tests/scripts/schedule-run-wrapper.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRAPPER="$ROOT/bin/schedule-run.sh"
PHP_BIN="${PHP_BIN:-$(command -v php)}"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -x "$WRAPPER" ]] || fail "wrapper missing or not executable: $WRAPPER"
grep -q 'artisan schedule:run' "$WRAPPER" || fail "wrapper must exec schedule:run"
grep -q 'cron_lock' "$WRAPPER" || fail "wrapper must match cron_lock paths"
grep -q 'php://fd' "$WRAPPER" || fail "wrapper must document php://fd limitation"

# --- PHP php://fd cannot release flock (skip if no /proc) ---
if [[ ! -d /proc/self/fd ]]; then
    pass "skip php://fd probe (no /proc)"
else
    "$PHP_BIN" -r '
        $lock = sys_get_temp_dir() . "/cron_lock_wrapper_test_" . getmypid();
        touch($lock);
        $fh = fopen($lock, "c+");
        flock($fh, LOCK_EX) || exit(1);
        $orig = null;
        foreach (scandir("/proc/self/fd") as $e) {
            if (!ctype_digit($e) || (int) $e < 3) continue;
            $t = @readlink("/proc/self/fd/$e");
            if (is_string($t) && str_contains($t, basename($lock))) { $orig = (int) $e; break; }
        }
        $orig === null && exit(2);
        $s = fopen("php://fd/$orig", "r+");
        $s || exit(3);
        fclose($s);
        $still = @readlink("/proc/self/fd/$orig");
        $still === false && exit(4); // original must remain
        $fh2 = fopen($lock, "c+");
        $got = flock($fh2, LOCK_EX | LOCK_NB);
        flock($fh, LOCK_UN); fclose($fh); fclose($fh2); @unlink($lock);
        exit($got ? 5 : 0); // expect still blocked
    ' && pass "php://fd + fclose does not release flock" || fail "php://fd probe unexpected exit $?"
fi

# --- bash exec N>&- does release flock ---
if ! command -v flock >/dev/null 2>&1; then
    pass "skip bash flock release probe (flock not installed)"
elif [[ ! -d /proc/$$/fd ]]; then
    pass "skip bash flock release probe (no /proc)"
else
LOCK="$(mktemp "${TMPDIR:-/tmp}/cron_lock_wrapper_bash_XXXXXX")"
exec 9>>"$LOCK"
flock -x 9
eval "exec 9>&-"
exec 8>>"$LOCK"
if flock -n 8; then
    flock -u 8
    pass "bash exec N>&- releases flock"
else
    rm -f "$LOCK"
    fail "bash exec N>&- did not release flock"
fi
exec 8>&-
rm -f "$LOCK"
fi

# --- Wrapper closes inherited cron_lock before exec (simulate via sourcing function) ---
# Run a child that inherits a lock FD, invokes the release logic equivalently,
# and asserts PHP would not see the lock. We exercise the wrapper's close loop
# by running a minimal clone of its close logic then checking /proc.
if [[ ! -d /proc/$$/fd ]]; then
    pass "skip inherit-close probe (no /proc)"
else
LOCK="$(mktemp "${TMPDIR:-/tmp}/cron_lock_wrapper_inherit_XXXXXX")"
# Rename to match Hostinger basename pattern
LOCK2="$(dirname "$LOCK")/cron_lock_$(basename "$LOCK")"
mv "$LOCK" "$LOCK2"
LOCK="$LOCK2"

(
    exec 9>>"$LOCK"
    flock -x 9

    # Same close loop as bin/schedule-run.sh
    to_close=()
    for fdpath in /proc/$$/fd/*; do
        [[ -e "$fdpath" || -L "$fdpath" ]] || continue
        fd="$(basename "$fdpath")"
        [[ "$fd" =~ ^[0-9]+$ ]] || continue
        (( fd < 3 )) && continue
        target="$(readlink "$fdpath" 2>/dev/null || true)"
        [[ -n "$target" ]] || continue
        base="$(basename "$target")"
        if [[ "$base" == cron_lock* || "$target" == */cron_lock* ]]; then
            to_close+=("$fd")
        fi
    done
    for fd in "${to_close[@]+"${to_close[@]}"}"; do
        eval "exec ${fd}>&-" 2>/dev/null || true
        eval "exec ${fd}<&-" 2>/dev/null || true
    done

    if ls -l /proc/$$/fd 2>/dev/null | grep -q cron_lock; then
        echo "still has cron_lock after close" >&2
        exit 1
    fi
    exit 0
) && pass "inherited cron_lock FDs can be dropped before PHP" || fail "failed to drop inherited cron_lock"

rm -f "$LOCK"
fi

echo "All schedule-run wrapper checks passed."
