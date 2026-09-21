#!/usr/bin/env bash
#
# Radium Desk / KVM8 — read-only storage metrics collection.
#
# Writes one JSON snapshot per UTC day under STORAGE_METRICS_ROOT/daily/.
# Compresses snapshots older than one day. Does not modify applications.
#
set -euo pipefail

STORAGE_METRICS_ROOT="${STORAGE_METRICS_ROOT:-/var/backups/storage-metrics}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

log() {
    echo "storage-metrics-collect.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

dir_size_bytes() {
    local path="$1"

    if [[ ! -e "$path" ]]; then
        printf '0'
        return 0
    fi

    if du -sb "$path" >/dev/null 2>&1; then
        du -sb "$path" | awk '{print $1}'
    else
        du -sk "$path" | awk '{print $1 * 1024}'
    fi
}

count_files() {
    local path="$1"

    if [[ ! -d "$path" ]]; then
        printf '0'
        return 0
    fi

    find "$path" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l | tr -d ' '
}

df_json() {
    df -P / | awk 'NR==2 {
        gsub(/%/,"",$5);
        printf "{\"filesystem\":\"%s\",\"size_bytes\":%s,\"used_bytes\":%s,\"avail_bytes\":%s,\"use_percent\":%s}",
            $1, $2*1024, $3*1024, $4*1024, $5
    }'
}

df_inode_json() {
    df -Pi / | awk 'NR==2 {
        gsub(/%/,"",$5);
        printf "{\"filesystem\":\"%s\",\"inodes_total\":%s,\"inodes_used\":%s,\"inodes_free\":%s,\"iuse_percent\":%s}",
            $1, $2, $3, $4, $5
    }'
}

main() {
    [[ -n "$PHP_BIN" ]] || die "php is required to write metrics JSON"

    local day utc ts output_dir output_path temp_path
    day="$(date -u +%Y-%m-%d)"
    utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    output_dir="${STORAGE_METRICS_ROOT}/daily"
    output_path="${output_dir}/${day}.json"
    temp_path="${output_path}.tmp.$$"

    mkdir -p "$output_dir"
    chmod 700 "$STORAGE_METRICS_ROOT" 2>/dev/null || true
    chmod 700 "$output_dir" 2>/dev/null || true

    export METRICS_UTC="$utc"
    export METRICS_DF="$(df_json)"
    export METRICS_INODES="$(df_inode_json)"
    export METRICS_DESK_BACKUPS="$(dir_size_bytes /var/backups/radium-desk/runs)"
    export METRICS_DESK_BACKUP_COUNT="$(count_files /var/backups/radium-desk/runs)"
    export METRICS_RADIUMBOX_BACKUPS="$(dir_size_bytes /var/backups/radiumbox-prod)"
    export METRICS_RDSERVICE_BACKUPS="$(dir_size_bytes /var/backups/rdservice-in)"
    export METRICS_MYSQL="$(dir_size_bytes /var/lib/mysql)"
    export METRICS_DESK_LOGS="$(dir_size_bytes /var/www/radium-desk/storage/logs)"
    export METRICS_WWW="$(dir_size_bytes /var/www)"

    "$PHP_BIN" -r '
        $payload = [
            "version" => 1,
            "collected_at" => getenv("METRICS_UTC") ?: gmdate("c"),
            "filesystem" => json_decode(getenv("METRICS_DF") ?: "{}", true),
            "inodes" => json_decode(getenv("METRICS_INODES") ?: "{}", true),
            "paths" => [
                "desk_backup_runs_bytes" => (int) (getenv("METRICS_DESK_BACKUPS") ?: "0"),
                "desk_backup_run_count" => (int) (getenv("METRICS_DESK_BACKUP_COUNT") ?: "0"),
                "radiumbox_prod_backups_bytes" => (int) (getenv("METRICS_RADIUMBOX_BACKUPS") ?: "0"),
                "rdservice_in_backups_bytes" => (int) (getenv("METRICS_RDSERVICE_BACKUPS") ?: "0"),
                "mysql_datadir_bytes" => (int) (getenv("METRICS_MYSQL") ?: "0"),
                "desk_logs_bytes" => (int) (getenv("METRICS_DESK_LOGS") ?: "0"),
                "var_www_bytes" => (int) (getenv("METRICS_WWW") ?: "0"),
            ],
        ];
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    ' >"$temp_path"

    chmod 600 "$temp_path"
    mv -f "$temp_path" "$output_path"

    find "$output_dir" -type f -name '*.json.gz' -mtime +365 -delete 2>/dev/null || true

    local older
    for older in "$output_dir"/*.json; do
        [[ -f "$older" ]] || continue
        [[ "$older" == "$output_path" ]] && continue
        if [[ "$(basename "$older" .json)" != "$day" ]]; then
            gzip -f "$older" 2>/dev/null || true
        fi
    done

    log "wrote ${output_path}"
}

main "$@"
