#!/usr/bin/env bash
#
# Radium Desk / KVM8 — storage threshold evaluation (read-only inputs).
#
# Reads daily metrics snapshots and writes sanitized last-status.json for operators.
# Does not send Telegram directly; ProductionWatchdog integration is future work.
#
set -euo pipefail

STORAGE_METRICS_ROOT="${STORAGE_METRICS_ROOT:-/var/backups/storage-metrics}"
STORAGE_METRICS_STATUS_PATH="${STORAGE_METRICS_STATUS_PATH:-${STORAGE_METRICS_ROOT}/last-status.json}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

WARN_DISK_PERCENT="${STORAGE_WARN_DISK_PERCENT:-25}"
CRIT_DISK_PERCENT="${STORAGE_CRIT_DISK_PERCENT:-28}"
WARN_WEEKLY_GROWTH_BYTES="${STORAGE_WARN_WEEKLY_GROWTH_BYTES:-3221225472}"
CRIT_WEEKLY_GROWTH_BYTES="${STORAGE_CRIT_WEEKLY_GROWTH_BYTES:-7516192768}"

log() {
    echo "storage-metrics-alert.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

main() {
    [[ -n "$PHP_BIN" ]] || die "php is required"

    local metrics_dir="${STORAGE_METRICS_ROOT}/daily"
    [[ -d "$metrics_dir" ]] || die "metrics directory missing: ${metrics_dir}"

    export METRICS_DIR="$metrics_dir"
    export STATUS_PATH="$STORAGE_METRICS_STATUS_PATH"
    export WARN_DISK="$WARN_DISK_PERCENT"
    export CRIT_DISK="$CRIT_DISK_PERCENT"
    export WARN_GROWTH="$WARN_WEEKLY_GROWTH_BYTES"
    export CRIT_GROWTH="$CRIT_WEEKLY_GROWTH_BYTES"

    "$PHP_BIN" -r '
        $dir = getenv("METRICS_DIR") ?: "";
        $statusPath = getenv("STATUS_PATH") ?: "";
        $warnDisk = (float) (getenv("WARN_DISK") ?: "25");
        $critDisk = (float) (getenv("CRIT_DISK") ?: "28");
        $warnGrowth = (int) (getenv("WARN_GROWTH") ?: "3221225472");
        $critGrowth = (int) (getenv("CRIT_GROWTH") ?: "7516192768");

        $files = glob($dir . "/*.json") ?: [];
        $gz = glob($dir . "/*.json.gz") ?: [];
        rsort($files);
        if ($files === []) {
            fwrite(STDERR, "no metrics snapshots found\n");
            exit(1);
        }

        $latestRaw = file_get_contents($files[0]);
        $latest = json_decode((string) $latestRaw, true);
        if (! is_array($latest)) {
            fwrite(STDERR, "invalid latest metrics json\n");
            exit(1);
        }

        $usedPercent = (float) ($latest["filesystem"]["use_percent"] ?? 0);
        $alerts = [];

        if ($usedPercent >= $critDisk) {
            $alerts[] = [
                "level" => "critical",
                "code" => "disk_percent",
                "message" => "Root filesystem at {$usedPercent}% (critical >= {$critDisk}%)",
            ];
        } elseif ($usedPercent >= $warnDisk) {
            $alerts[] = [
                "level" => "warning",
                "code" => "disk_percent",
                "message" => "Root filesystem at {$usedPercent}% (warning >= {$warnDisk}%)",
            ];
        }

        $weekAgo = null;
        $latestTs = strtotime((string) ($latest["collected_at"] ?? ""));
        foreach ([...$files, ...$gz] as $path) {
            $name = basename($path);
            $date = preg_match("/^(\d{4}-\d{2}-\d{2})/", $name, $m) ? $m[1] : null;
            if ($date === null) {
                continue;
            }
            $ts = strtotime($date . "T00:00:00Z");
            if ($latestTs > 0 && $ts <= ($latestTs - 6 * 86400)) {
                $weekAgo = $path;
                break;
            }
        }

        $growthBytes = null;
        if ($weekAgo !== null) {
            $raw = str_ends_with($weekAgo, ".gz")
                ? gzdecode((string) file_get_contents($weekAgo))
                : file_get_contents($weekAgo);
            $old = json_decode((string) $raw, true);
            if (is_array($old)) {
                $growthBytes = (int) ($latest["filesystem"]["used_bytes"] ?? 0)
                    - (int) ($old["filesystem"]["used_bytes"] ?? 0);
                if ($growthBytes >= $critGrowth) {
                    $alerts[] = [
                        "level" => "critical",
                        "code" => "disk_growth_7d",
                        "message" => "Root filesystem grew {$growthBytes} bytes in ~7 days (critical >= {$critGrowth})",
                    ];
                } elseif ($growthBytes >= $warnGrowth) {
                    $alerts[] = [
                        "level" => "warning",
                        "code" => "disk_growth_7d",
                        "message" => "Root filesystem grew {$growthBytes} bytes in ~7 days (warning >= {$warnGrowth})",
                    ];
                }
            }
        }

        $payload = [
            "version" => 1,
            "generated_at" => gmdate("c"),
            "latest_metrics_file" => basename($files[0]),
            "filesystem_use_percent" => $usedPercent,
            "growth_bytes_7d" => $growthBytes,
            "alerts" => $alerts,
            "telegram_integration" => false,
        ];

        $temp = $statusPath . ".tmp." . getmypid();
        file_put_contents($temp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($temp, 0600);
        rename($temp, $statusPath);
    '

    log "wrote ${STORAGE_METRICS_STATUS_PATH}"
}

main "$@"
