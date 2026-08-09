#!/usr/bin/env bash
#
# Hostinger Cron #3 — process CPU attribution sampler (Layer A).
#
# Pure bash + ps/awk/date/flock. Never boots PHP/artisan.
# Wall budget: 4 samples + 3×~15s sleep ≈ 45–50s (<55s Hostinger flock window).
#
# Lock order (mandatory): open FD 9 on the lock file, THEN flock -n 9.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OUT_DIR="${ROOT}/storage/logs/cpu-process-samples"
SCHED_DIR="${ROOT}/storage/logs/scheduler-timing"
LOCK="${OUT_DIR}/sample.lock"
INTERVAL="${CPU_SAMPLE_INTERVAL:-15}"
COUNT="${CPU_SAMPLE_COUNT:-4}"
MAX_DAY_BYTES=$((32 * 1024 * 1024))

warn_once() {
    echo "cpu-process-sample.sh: $*" >&2
}

mkdir -p "$OUT_DIR" 2>/dev/null || {
    warn_once "cannot create ${OUT_DIR}; exiting 0"
    exit 0
}
chmod 0755 "$OUT_DIR" 2>/dev/null || true

# Open lock FD before flock (readiness fix vs early spec draft).
exec 9>"$LOCK" || {
    warn_once "cannot open lock ${LOCK}; exiting 0"
    exit 0
}
chmod 0644 "$LOCK" 2>/dev/null || true

if command -v flock >/dev/null 2>&1; then
    if ! flock -n 9; then
        exit 0
    fi
else
    # Hostinger Linux has flock; macOS/local may not — still sample for offline checks.
    warn_once "flock not found; continuing without overlap guard"
fi

day_file() {
    TZ=Asia/Kolkata date +%Y-%m-%d
}

iso_ist() {
    TZ=Asia/Kolkata date +%Y-%m-%dT%H:%M:%S%z | sed 's/\([+-][0-9][0-9]\)\([0-9][0-9]\)$/\1:\2/'
}

iso_utc() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

HEADER="ts_ist	ts_utc	load1	load5	load15	desk_lsphp_cpu	desk_lsphp_n	other_lsphp_cpu	other_lsphp_n	queue_cpu	queue_n	schedule_run_cpu	schedule_run_n	light_tick_cpu	warm_cpu	automation_snapshot_cpu	watchdog_cpu	appointment_reminders_cpu	recover_sync_cpu	missing_serial_cpu	cashfree_recover_cpu	gmail_cpu	artisan_other_cpu	artisan_n	acct_php_cpu	top5	lve_usage"

capture_sample() {
    local ts_ist ts_utc load1 load5 load15 lve_usage outfile size line

    ts_ist="$(iso_ist)"
    ts_utc="$(iso_utc)"

    load1=0
    load5=0
    load15=0
    if [[ -r /proc/loadavg ]]; then
        # shellcheck disable=SC2034
        read -r load1 load5 load15 _ </proc/loadavg || true
    fi

    lve_usage=""
    if [[ -r /proc/lve/usage ]]; then
        lve_usage="$(head -c 200 /proc/lve/usage 2>/dev/null | tr '\t\n' '  ' | sed 's/  */ /g')" || true
    fi

    # One ps pass + awk classification (no PHP).
    line="$(
        ps -eo pcpu,pmem,etime,pid,cmd 2>/dev/null | awk -v load1="$load1" -v load5="$load5" -v load15="$load15" -v ts_ist="$ts_ist" -v ts_utc="$ts_utc" -v lve_usage="$lve_usage" '
        BEGIN {
            desk_cpu=0; desk_n=0; other_cpu=0; other_n=0
            queue_cpu=0; queue_n=0
            schedule_run_cpu=0; schedule_run_n=0
            light_tick_cpu=0; warm_cpu=0; automation_snapshot_cpu=0
            watchdog_cpu=0; appointment_reminders_cpu=0; recover_sync_cpu=0
            missing_serial_cpu=0; cashfree_recover_cpu=0; gmail_cpu=0
            artisan_other_cpu=0; artisan_n=0
            top_n=0
        }
        NR==1 { next }
        {
            pcpu=$1+0
            pid=$4
            cmd=""
            for (i=5; i<=NF; i++) {
                cmd = (cmd == "" ? $i : cmd " " $i)
            }
            if (cmd ~ /cpu-process-sample\.sh/ || cmd ~ /[[:space:]]awk[[:space:]]/ || cmd ~ /^awk /) {
                next
            }

            # Top-5 candidates (all processes).
            consider_top(pcpu, pid, cmd)

            if (cmd ~ /lsphp:ins\/desk\.radiumbox\.com/) {
                desk_cpu += pcpu
                desk_n++
            } else if (cmd ~ /lsphp/) {
                other_cpu += pcpu
                other_n++
            }

            if (cmd ~ /queue:work/) {
                queue_cpu += pcpu
                queue_n++
            }

            if (cmd !~ /artisan/) {
                next
            }

            artisan_n++
            if (cmd ~ /schedule:run([[:space:]]|$)/) {
                schedule_run_cpu += pcpu
                schedule_run_n++
            } else if (cmd ~ /schedule:light-tick([[:space:]]|$)/) {
                light_tick_cpu += pcpu
            } else if (cmd ~ /platform:snapshots:warm([[:space:]]|$)/) {
                warm_cpu += pcpu
            } else if (cmd ~ /automation:snapshot/) {
                automation_snapshot_cpu += pcpu
            } else if (cmd ~ /watchdog:send-critical([[:space:]]|$)/) {
                watchdog_cpu += pcpu
            } else if (cmd ~ /team-telegram:send-appointment-reminders/) {
                appointment_reminders_cpu += pcpu
            } else if (cmd ~ /radiumbox:recover-sync([[:space:]]|$)/) {
                recover_sync_cpu += pcpu
            } else if (cmd ~ /missing-serial:process([[:space:]]|$)/) {
                missing_serial_cpu += pcpu
            } else if (cmd ~ /cashfree:auto-recover/) {
                cashfree_recover_cpu += pcpu
            } else if (cmd ~ /inbound-email:sync-gmail([[:space:]]|$)/) {
                gmail_cpu += pcpu
            } else {
                artisan_other_cpu += pcpu
            }
        }
        function consider_top(pcpu, pid, cmd,   i, j, c) {
            c = cmd
            gsub(/[\t\r\n]+/, " ", c)
            gsub(/ +/, " ", c)
            if (length(c) > 160) {
                c = substr(c, 1, 160)
            }
            if (top_n < 5) {
                top_n++
                top_cpu[top_n] = pcpu
                top_pid[top_n] = pid
                top_cmd[top_n] = c
            } else {
                # Replace current minimum if this is larger.
                min_i = 1
                for (i = 2; i <= 5; i++) {
                    if (top_cpu[i] < top_cpu[min_i]) min_i = i
                }
                if (pcpu > top_cpu[min_i]) {
                    top_cpu[min_i] = pcpu
                    top_pid[min_i] = pid
                    top_cmd[min_i] = c
                }
            }
            # Keep descending order for stable output.
            for (i = 1; i <= top_n; i++) {
                for (j = i + 1; j <= top_n; j++) {
                    if (top_cpu[j] > top_cpu[i]) {
                        t = top_cpu[i]; top_cpu[i] = top_cpu[j]; top_cpu[j] = t
                        t = top_pid[i]; top_pid[i] = top_pid[j]; top_pid[j] = t
                        t = top_cmd[i]; top_cmd[i] = top_cmd[j]; top_cmd[j] = t
                    }
                }
            }
        }
        function fmt(x) {
            return sprintf("%.1f", x + 0)
        }
        END {
            acct = desk_cpu + queue_cpu + schedule_run_cpu + light_tick_cpu + warm_cpu + automation_snapshot_cpu + watchdog_cpu + appointment_reminders_cpu + recover_sync_cpu + missing_serial_cpu + cashfree_recover_cpu + gmail_cpu + artisan_other_cpu
            top5 = ""
            for (i = 1; i <= top_n; i++) {
                piece = sprintf("%.1f,%s,%s", top_cpu[i], top_pid[i], top_cmd[i])
                top5 = (top5 == "" ? piece : top5 "|" piece)
            }
            gsub(/[\t\r\n]+/, " ", lve_usage)
            printf "%s\t%s\t%s\t%s\t%s\t%s\t%d\t%s\t%d\t%s\t%d\t%s\t%d\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%d\t%s\t%s\t%s\n", \
                ts_ist, ts_utc, load1, load5, load15, \
                fmt(desk_cpu), desk_n, fmt(other_cpu), other_n, \
                fmt(queue_cpu), queue_n, fmt(schedule_run_cpu), schedule_run_n, \
                fmt(light_tick_cpu), fmt(warm_cpu), fmt(automation_snapshot_cpu), \
                fmt(watchdog_cpu), fmt(appointment_reminders_cpu), fmt(recover_sync_cpu), \
                fmt(missing_serial_cpu), fmt(cashfree_recover_cpu), fmt(gmail_cpu), \
                fmt(artisan_other_cpu), artisan_n, fmt(acct), top5, lve_usage
        }
        ' || true
    )"

    if [[ -z "${line}" ]]; then
        line="${ts_ist}	${ts_utc}	${load1}	${load5}	${load15}	0.0	0	0.0	0	0.0	0	0.0	0	0.0	0.0	0.0	0.0	0.0	0.0	0.0	0.0	0.0	0.0	0	0.0		${lve_usage}"
    fi

    outfile="${OUT_DIR}/$(day_file).tsv"

    if [[ -f "$outfile" ]]; then
        size="$(wc -c <"$outfile" 2>/dev/null || echo 0)"
        if [[ "${size}" -gt "${MAX_DAY_BYTES}" ]]; then
            warn_once "today sample file exceeds 32MB; skipping append"
            return 0
        fi
    else
        if ! printf '%s\n' "$HEADER" >"$outfile"; then
            warn_once "cannot write header to ${outfile}"
            return 0
        fi
        chmod 0644 "$outfile" 2>/dev/null || true
    fi

    if ! printf '%s\n' "$line" >>"$outfile"; then
        warn_once "disk write failed for ${outfile}"
        return 0
    fi
    chmod 0644 "$outfile" 2>/dev/null || true
}

i=1
while [[ "$i" -le "$COUNT" ]]; do
    capture_sample || true
    if [[ "$i" -lt "$COUNT" ]]; then
        sleep "$INTERVAL" || true
    fi
    i=$((i + 1))
done

# Sampler owns prune for A (TSV) and B (JSONL) trees — 7-day retention.
find "$OUT_DIR" -type f -name '*.tsv' -mtime +7 -delete 2>/dev/null || true
if [[ -d "$SCHED_DIR" ]]; then
    find "$SCHED_DIR" -type f -name '*.jsonl' -mtime +7 -delete 2>/dev/null || true
fi

exit 0
