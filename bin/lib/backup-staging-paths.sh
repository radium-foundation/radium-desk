#!/usr/bin/env bash
#
# Shared staging-root path guards for Radium Desk backup scripts.
# Source from bin/backup-*.sh — do not execute directly.
#
# BACKUP_STAGING_ROOT is the only tree where chmod/setfacl on staging output parents is permitted.

backup_staging_paths_log() {
    echo "backup-staging-paths: $*" >&2
}

backup_staging_normalize_root_path() {
    local root="$1"

    if [[ "$root" == "/" ]]; then
        printf '/'
        return 0
    fi

    printf '%s' "${root%/}"
}

backup_staging_paths_canonical_dir() {
    local path="$1"

    if command -v python3 >/dev/null 2>&1; then
        BACKUP_STAGING_CANONICAL_INPUT="$path" python3 -c 'import os; print(os.path.normpath(os.path.abspath(os.environ["BACKUP_STAGING_CANONICAL_INPUT"])))'
        return 0
    fi

    if command -v realpath >/dev/null 2>&1; then
        if realpath -m -- "$path" 2>/dev/null; then
            return 0
        fi
        realpath -- "$path" 2>/dev/null && return 0
    fi

    backup_staging_paths_log "ERROR: python3 or realpath is required for staging path canonicalization"
    return 1
}

backup_staging_paths_forbidden_root_dir() {
    local canon="$1"

    case "$canon" in
        /|/tmp|/private/tmp|/var|/usr|/etc|/home|/root|/bin|/sbin)
            return 0
            ;;
    esac

    [[ "$canon" == /private/tmp/* ]] && return 0

    return 1
}

# Returns 0 when candidate is exactly staging_root or a strict descendant (canonical paths).
backup_staging_path_is_under_root() {
    local candidate="$1"
    local staging_root="$2"
    local canon_candidate canon_root

    [[ -n "$candidate" && -n "$staging_root" ]] || return 1
    [[ "$candidate" == /* ]] || return 1
    [[ "$staging_root" == /* ]] || return 1
    [[ "$candidate" != *..* && "$staging_root" != *..* ]] || return 1

    canon_root="$(backup_staging_paths_canonical_dir "$(backup_staging_normalize_root_path "$staging_root")")" || return 1
    canon_candidate="$(backup_staging_paths_canonical_dir "$candidate")" || return 1

    if backup_staging_paths_forbidden_root_dir "$canon_root"; then
        return 1
    fi

    if [[ "$canon_candidate" == "$canon_root" ]]; then
        return 0
    fi

    [[ "$canon_candidate" == "$canon_root"/* ]]
}

backup_staging_validate_root() {
    local staging_root="$1"

    [[ -n "$staging_root" ]] || {
        backup_staging_paths_log "ERROR: BACKUP_STAGING_ROOT is empty"
        return 1
    }
    [[ "$staging_root" == /* ]] || {
        backup_staging_paths_log "ERROR: BACKUP_STAGING_ROOT must be an absolute path"
        return 1
    }
    [[ "$staging_root" != *..* ]] || {
        backup_staging_paths_log "ERROR: BACKUP_STAGING_ROOT must not contain .."
        return 1
    }

    local canon_root
    canon_root="$(backup_staging_paths_canonical_dir "$(backup_staging_normalize_root_path "$staging_root")")" || return 1
    if backup_staging_paths_forbidden_root_dir "$canon_root"; then
        backup_staging_paths_log "ERROR: BACKUP_STAGING_ROOT is not an approved staging location"
        return 1
    fi

    return 0
}

backup_staging_assert_path_under_root() {
    local path="$1"
    local staging_root="$2"
    local label="${3:-path}"

    [[ -n "$path" ]] || {
        backup_staging_paths_log "ERROR: ${label} is empty"
        return 1
    }
    [[ "$path" == /* ]] || {
        backup_staging_paths_log "ERROR: ${label} must be an absolute path"
        return 1
    }
    [[ "$path" != *..* ]] || {
        backup_staging_paths_log "ERROR: ${label} must not contain .."
        return 1
    }

    if ! backup_staging_path_is_under_root "$path" "$staging_root"; then
        backup_staging_paths_log "ERROR: ${label} must stay under BACKUP_STAGING_ROOT (${staging_root})"
        return 1
    fi

    return 0
}

# mkdir + chmod 700 on the parent of a staging output file (never on /tmp or outside staging).
backup_staging_prepare_output_parent() {
    local output_file="$1"
    local staging_root="$2"
    local parent

    parent="$(dirname "$output_file")"
    if ! backup_staging_assert_path_under_root "$parent" "$staging_root" "output parent directory"; then
        return 1
    fi

    mkdir -p -- "$parent"
    chmod 700 -- "$parent" 2>/dev/null || true
    return 0
}

backup_staging_chmod_700_if_under_root() {
    local dir="$1"
    local staging_root="$2"

    if backup_staging_path_is_under_root "$dir" "$staging_root"; then
        chmod 700 -- "$dir" 2>/dev/null || true
        return 0
    fi

    backup_staging_paths_log "ERROR: refusing chmod 700 outside staging root: ${dir}"
    return 1
}

backup_staging_setfacl_if_under_root() {
    local target="$1"
    local staging_root="$2"
    shift 2

    if ! backup_staging_assert_path_under_root "$target" "$staging_root" "setfacl target"; then
        return 1
    fi

    if ! command -v setfacl >/dev/null 2>&1; then
        backup_staging_paths_log "ERROR: setfacl not available"
        return 1
    fi

    setfacl "$@"
}