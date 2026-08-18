#!/usr/bin/env bash
#
# Radium Desk — local backup generation (Phase 1: staging only).
#
# Produces encrypted database dump + encrypted critical-secrets bundle under a
# local staging directory. Does not upload, schedule, prune, or alert.
#
# Encryption is mandatory and fail-closed:
#   - gpg symmetric: BACKUP_ENCRYPTION_PASSPHRASE or BACKUP_ENCRYPTION_PASSPHRASE_FILE
#   - age public-key:  BACKUP_AGE_RECIPIENT (and age binary on PATH)
#
# See docs/backup-runbook.md
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-$(command -v mysqldump || true)}"
MYSQL_BIN="${MYSQL_BIN:-$(command -v mysql || true)}"
GZIP_BIN="${GZIP_BIN:-$(command -v gzip || true)}"
GPG_BIN="${GPG_BIN:-$(command -v gpg || true)}"
AGE_BIN="${AGE_BIN:-$(command -v age || true)}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

BACKUP_STAGING_ROOT="${BACKUP_STAGING_ROOT:-/var/backups/radium-desk}"

WORK_PARENT=""
MYSQL_CNF=""
TEMP_DIR=""
RUN_SUCCEEDED=0

log() {
    echo "backup-run.sh: $*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

cleanup_temp() {
    if [[ -n "$MYSQL_CNF" && -f "$MYSQL_CNF" ]]; then
        rm -f "$MYSQL_CNF"
        MYSQL_CNF=""
    fi

    if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" ]]; then
        rm -rf "$TEMP_DIR"
        TEMP_DIR=""
    fi
}

on_exit() {
    local exit_code=$?

    if [[ "$RUN_SUCCEEDED" -eq 1 ]]; then
        return
    fi

    if [[ "$exit_code" -ne 0 ]]; then
        cleanup_temp
    fi
}

trap on_exit EXIT

read_env_value() {
    local key="$1"
    local file="$ROOT/.env"

    [[ -f "$file" ]] || return 1

    local line
    line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
    [[ -n "$line" ]] || return 1

    local value="${line#*=}"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    printf '%s' "$value"
}

file_size_bytes() {
    local path="$1"

    if stat -c '%s' "$path" >/dev/null 2>&1; then
        stat -c '%s' "$path"
    else
        stat -f '%z' "$path"
    fi
}

sha256_file() {
    local path="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{print $1}'
    else
        shasum -a 256 "$path" | awk '{print $1}'
    fi
}

utc_timestamp() {
    date -u +%Y%m%dT%H%M%SZ
}

utc_iso8601() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

read_passphrase() {
    if [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE:-}" ]]; then
        printf '%s' "$BACKUP_ENCRYPTION_PASSPHRASE"
        return 0
    fi

    if [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}" ]] && [[ -r "${BACKUP_ENCRYPTION_PASSPHRASE_FILE}" ]]; then
        tr -d '\n\r' < "${BACKUP_ENCRYPTION_PASSPHRASE_FILE}"
        return 0
    fi

    die "GPG encryption requires BACKUP_ENCRYPTION_PASSPHRASE or BACKUP_ENCRYPTION_PASSPHRASE_FILE"
}

resolve_encryption_method() {
    if [[ -n "${BACKUP_ENCRYPTION_METHOD:-}" ]]; then
        case "$BACKUP_ENCRYPTION_METHOD" in
            gpg|age)
                ENCRYPTION_METHOD="$BACKUP_ENCRYPTION_METHOD"
                ;;
            *)
                die "Unsupported BACKUP_ENCRYPTION_METHOD: ${BACKUP_ENCRYPTION_METHOD}"
                ;;
        esac
    elif [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]]; then
        ENCRYPTION_METHOD="age"
    elif [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE:-}" || -n "${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}" ]]; then
        ENCRYPTION_METHOD="gpg"
    else
        die "Encryption not configured. Set BACKUP_ENCRYPTION_PASSPHRASE_FILE, BACKUP_ENCRYPTION_PASSPHRASE, or BACKUP_AGE_RECIPIENT."
    fi

    case "$ENCRYPTION_METHOD" in
        gpg)
            [[ -n "$GPG_BIN" ]] || die "gpg is required for encryption but was not found on PATH"
            ;;
        age)
            [[ -n "$AGE_BIN" ]] || die "age is required for encryption but was not found on PATH"
            [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]] || die "BACKUP_AGE_RECIPIENT is required for age encryption"
            ;;
    esac
}

encrypt_file() {
    local input="$1"
    local output="$2"

    case "$ENCRYPTION_METHOD" in
        gpg)
            local passphrase
            passphrase="$(read_passphrase)"
            "$GPG_BIN" --batch --yes --symmetric \
                --cipher-algo AES256 \
                --passphrase "$passphrase" \
                --output "$output" \
                "$input"
            ;;
        age)
            "$AGE_BIN" -r "$BACKUP_AGE_RECIPIENT" -o "$output" "$input"
            ;;
    esac

    [[ -f "$output" ]] || die "Encryption produced no output file"
}

encryption_label() {
    case "$ENCRYPTION_METHOD" in
        gpg) printf '%s' "gpg-aes256-symmetric" ;;
        age) printf '%s' "age-public-key" ;;
    esac
}

write_mysql_defaults() {
    local host="$1"
    local port="$2"
    local user="$3"
    local password="$4"
    local database="$5"

    MYSQL_CNF="$(mktemp "${TMPDIR:-/tmp}/radium-backup-mysql.XXXXXX")"
    chmod 600 "$MYSQL_CNF"

    {
        printf '[client]\n'
        printf 'user=%s\n' "$user"
        printf 'password=%s\n' "$password"
        if [[ -n "$host" ]]; then
            printf 'host=%s\n' "$host"
        fi
        if [[ -n "$port" ]]; then
            printf 'port=%s\n' "$port"
        fi
        if [[ -n "$database" ]]; then
            printf 'database=%s\n' "$database"
        fi
    } >"$MYSQL_CNF"
}

read_release_metadata() {
    local manifest_path="$ROOT/storage/app/private/release.json"

    if [[ ! -f "$manifest_path" ]]; then
        APP_VERSION=""
        APP_BUILD=""
        APP_DEPLOYED_AT=""
        return 0
    fi

    if [[ -z "$PHP_BIN" ]]; then
        APP_VERSION=""
        APP_BUILD=""
        APP_DEPLOYED_AT=""
        return 0
    fi

    APP_VERSION="$("$PHP_BIN" -r '
        $path = $argv[1];
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) { exit(0); }
        echo isset($data["version"]) ? (string) $data["version"] : "";
    ' "$manifest_path")"

    APP_BUILD="$("$PHP_BIN" -r '
        $path = $argv[1];
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) { exit(0); }
        echo isset($data["build"]) ? (string) $data["build"] : "";
    ' "$manifest_path")"

    APP_DEPLOYED_AT="$("$PHP_BIN" -r '
        $path = $argv[1];
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) { exit(0); }
        echo isset($data["deployed_at"]) ? (string) $data["deployed_at"] : "";
    ' "$manifest_path")"
}

read_database_engine_version() {
    DB_ENGINE_VERSION=""

    if [[ -z "$MYSQL_BIN" || -z "$MYSQL_CNF" ]]; then
        return 0
    fi

    DB_ENGINE_VERSION="$("$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" -N -e "SELECT VERSION();" 2>/dev/null || true)"
}

write_manifest() {
    local manifest_path="$1"
    local backup_id="$2"
    local created_at="$3"
    local db_name="$4"
    local db_artifact="$5"
    local db_size="$6"
    local db_sha="$7"
    local secrets_artifact="$8"
    local secrets_size="$9"
    local secrets_sha="${10}"

    if [[ -z "$PHP_BIN" ]]; then
        die "php is required to write the backup manifest"
    fi

    export BACKUP_MANIFEST_BACKUP_ID="$backup_id"
    export BACKUP_MANIFEST_CREATED_AT="$created_at"
    export BACKUP_MANIFEST_PHASE="local_staging"
    export BACKUP_MANIFEST_APP_VERSION="${APP_VERSION:-}"
    export BACKUP_MANIFEST_APP_BUILD="${APP_BUILD:-}"
    export BACKUP_MANIFEST_APP_DEPLOYED_AT="${APP_DEPLOYED_AT:-}"
    export BACKUP_MANIFEST_DB_NAME="$db_name"
    export BACKUP_MANIFEST_DB_ENGINE_VERSION="${DB_ENGINE_VERSION:-}"
    export BACKUP_MANIFEST_DB_ARTIFACT="$db_artifact"
    export BACKUP_MANIFEST_DB_SIZE="$db_size"
    export BACKUP_MANIFEST_DB_SHA="$db_sha"
    export BACKUP_MANIFEST_SECRETS_ARTIFACT="$secrets_artifact"
    export BACKUP_MANIFEST_SECRETS_SIZE="$secrets_size"
    export BACKUP_MANIFEST_SECRETS_SHA="$secrets_sha"
    export BACKUP_MANIFEST_ENCRYPTION="$(encryption_label)"

    "$PHP_BIN" -r '
        $manifest = [
            "backup_id" => getenv("BACKUP_MANIFEST_BACKUP_ID") ?: "",
            "created_at" => getenv("BACKUP_MANIFEST_CREATED_AT") ?: "",
            "phase" => getenv("BACKUP_MANIFEST_PHASE") ?: "local_staging",
            "application" => [
                "version" => getenv("BACKUP_MANIFEST_APP_VERSION") ?: null,
                "build" => getenv("BACKUP_MANIFEST_APP_BUILD") ?: null,
                "deployed_at" => getenv("BACKUP_MANIFEST_APP_DEPLOYED_AT") ?: null,
            ],
            "database" => [
                "name" => getenv("BACKUP_MANIFEST_DB_NAME") ?: "",
                "engine" => "mariadb",
                "engine_version" => getenv("BACKUP_MANIFEST_DB_ENGINE_VERSION") ?: null,
            ],
            "artifacts" => [
                [
                    "role" => "database",
                    "filename" => getenv("BACKUP_MANIFEST_DB_ARTIFACT") ?: "",
                    "size_bytes" => (int) getenv("BACKUP_MANIFEST_DB_SIZE"),
                    "sha256" => getenv("BACKUP_MANIFEST_DB_SHA") ?: "",
                    "encryption" => getenv("BACKUP_MANIFEST_ENCRYPTION") ?: "",
                ],
                [
                    "role" => "secrets",
                    "filename" => getenv("BACKUP_MANIFEST_SECRETS_ARTIFACT") ?: "",
                    "size_bytes" => (int) getenv("BACKUP_MANIFEST_SECRETS_SIZE"),
                    "sha256" => getenv("BACKUP_MANIFEST_SECRETS_SHA") ?: "",
                    "encryption" => getenv("BACKUP_MANIFEST_ENCRYPTION") ?: "",
                ],
            ],
        ];

        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' >"$manifest_path"
}

ensure_staging_root() {
    if [[ ! -d "$BACKUP_STAGING_ROOT" ]]; then
        mkdir -p "$BACKUP_STAGING_ROOT"
    fi

    chmod 700 "$BACKUP_STAGING_ROOT" 2>/dev/null || true

    WORK_PARENT="${BACKUP_STAGING_ROOT}/work"
    mkdir -p "$WORK_PARENT"
    chmod 700 "$WORK_PARENT" 2>/dev/null || true

    mkdir -p "${BACKUP_STAGING_ROOT}/runs"
    chmod 700 "${BACKUP_STAGING_ROOT}/runs" 2>/dev/null || true
}

main() {
    [[ -n "$MYSQLDUMP_BIN" ]] || die "mysqldump was not found on PATH"
    [[ -n "$GZIP_BIN" ]] || die "gzip was not found on PATH"

    resolve_encryption_method

    local db_host db_port db_name db_user db_password
    db_host="$(read_env_value DB_HOST || true)"
    db_port="$(read_env_value DB_PORT || true)"
    db_name="$(read_env_value DB_DATABASE || true)"
    db_user="$(read_env_value DB_USERNAME || true)"
    db_password="$(read_env_value DB_PASSWORD || true)"

    [[ -n "$db_name" ]] || die "DB_DATABASE is missing from .env"
    [[ -n "$db_user" ]] || die "DB_USERNAME is missing from .env"
    [[ -n "$db_password" ]] || die "DB_PASSWORD is missing from .env"

    if [[ -z "$db_port" ]]; then
        db_port="3306"
    fi

    ensure_staging_root

    local backup_id created_at
    backup_id="$(utc_timestamp)"
    created_at="$(utc_iso8601)"

    TEMP_DIR="$(mktemp -d "${WORK_PARENT}/backup-${backup_id}-XXXXXX")"
    chmod 700 "$TEMP_DIR"

    write_mysql_defaults "$db_host" "$db_port" "$db_user" "$db_password" "$db_name"
    read_release_metadata
    read_database_engine_version

    local db_plain db_gz db_enc secrets_plain secrets_enc
    local db_ext secrets_ext

    case "$ENCRYPTION_METHOD" in
        gpg) db_ext="gpg"; secrets_ext="gpg" ;;
        age) db_ext="age"; secrets_ext="age" ;;
    esac

    db_plain="${TEMP_DIR}/database.sql"
    db_gz="${TEMP_DIR}/database.sql.gz"
    db_enc="${TEMP_DIR}/database.sql.gz.${db_ext}"
    secrets_plain="${TEMP_DIR}/secrets.tar.gz"
    secrets_enc="${TEMP_DIR}/secrets.tar.gz.${secrets_ext}"

    log "starting backup ${backup_id} (database=${db_name}, encryption=${ENCRYPTION_METHOD})"

  # Database dump
    if ! "$MYSQLDUMP_BIN" --defaults-extra-file="$MYSQL_CNF" \
        --single-transaction \
        --quick \
        "$db_name" >"$db_plain"; then
        die "mysqldump failed"
    fi

    "$GZIP_BIN" -c "$db_plain" >"$db_gz"
    rm -f "$db_plain"

    encrypt_file "$db_gz" "$db_enc"
    rm -f "$db_gz"

    if [[ -f "$db_gz" ]]; then
        die "Plaintext database gzip artifact still present after encryption"
    fi

  # Critical secrets bundle (.env + storage/app/google/*.json only)
    local secrets_paths=()
    if [[ -f "$ROOT/.env" ]]; then
        secrets_paths+=(".env")
    fi

    local google_json
    for google_json in "$ROOT/storage/app/google/"*.json; do
        [[ -e "$google_json" ]] || continue
        secrets_paths+=("storage/app/google/$(basename "$google_json")")
    done

    [[ "${#secrets_paths[@]}" -gt 0 ]] || die "No critical secrets found (.env or storage/app/google/*.json)"

    tar -czf "$secrets_plain" -C "$ROOT" "${secrets_paths[@]}"

    encrypt_file "$secrets_plain" "$secrets_enc"
    rm -f "$secrets_plain"

    if [[ -f "$secrets_plain" ]]; then
        die "Plaintext secrets bundle still present after encryption"
    fi

    local final_run_dir="${BACKUP_STAGING_ROOT}/runs/${backup_id}"
    if [[ -e "$final_run_dir" ]]; then
        die "Backup run directory already exists: ${final_run_dir}"
    fi

    mkdir -p "$final_run_dir"
    chmod 700 "$final_run_dir"

    local final_db_name="database.sql.gz.${db_ext}"
    local final_secrets_name="secrets.tar.gz.${secrets_ext}"

    mv "$db_enc" "${final_run_dir}/${final_db_name}"
    mv "$secrets_enc" "${final_run_dir}/${final_secrets_name}"

    chmod 600 "${final_run_dir}/${final_db_name}"
    chmod 600 "${final_run_dir}/${final_secrets_name}"

    local db_size secrets_size db_sha secrets_sha
    db_size="$(file_size_bytes "${final_run_dir}/${final_db_name}")"
    secrets_size="$(file_size_bytes "${final_run_dir}/${final_secrets_name}")"
    db_sha="$(sha256_file "${final_run_dir}/${final_db_name}")"
    secrets_sha="$(sha256_file "${final_run_dir}/${final_secrets_name}")"

    write_manifest \
        "${final_run_dir}/manifest.json" \
        "$backup_id" \
        "$created_at" \
        "$db_name" \
        "$final_db_name" \
        "$db_size" \
        "$db_sha" \
        "$final_secrets_name" \
        "$secrets_size" \
        "$secrets_sha"

    chmod 600 "${final_run_dir}/manifest.json"

    RUN_SUCCEEDED=1
  # Drop EXIT trap cleanup for temp dir — run succeeded; remove temp work dir only.
    trap - EXIT
    rm -rf "$TEMP_DIR"
    TEMP_DIR=""
    rm -f "$MYSQL_CNF"
    MYSQL_CNF=""

    log "backup completed: ${final_run_dir}"
}

main "$@"
