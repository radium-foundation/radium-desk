#!/usr/bin/env bash
#
# Shared RIP application archive rules (sourced by capture scripts and tests).
#
# Archives use an explicit path allowlist (not "archive entire tree minus excludes").
# release.json is captured only as release.json.snapshot beside the archive.
#
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "rip-capture-archive.sh must be sourced, not executed directly." >&2
    exit 1
fi

rip_capture_min_free_mb() {
    echo "${RIP_CAPTURE_MIN_FREE_MB:-1024}"
}

rip_capture_check_disk_space() {
    local target_path="$1"
    local min_mb
    min_mb="$(rip_capture_min_free_mb)"
    local parent
    parent="$(dirname "${target_path}")"
    mkdir -p "${parent}" 2>/dev/null || true

    if ! df -Pm "${parent}" >/dev/null 2>&1; then
        echo "rip-capture: cannot determine free disk space for ${parent}" >&2
        return 1
    fi

    local avail
    avail="$(df -Pm "${parent}" | awk 'NR==2 {print $4}')"

    if [[ -z "${avail}" || "${avail}" -lt "${min_mb}" ]]; then
        echo "rip-capture: insufficient disk space on ${parent} (need >= ${min_mb} MiB free, have ${avail:-unknown})" >&2
        return 1
    fi

    return 0
}

# Top-level paths relative to Laravel root (reviewed deployable application surface).
rip_capture_tar_include_paths() {
    cat <<'EOF'
app
bootstrap/app.php
bootstrap/providers.php
config
database
public
resources
routes
tools
contracts
docs
artisan
composer.json
composer.lock
package.json
package-lock.json
vite.config.js
tailwind.config.js
postcss.config.js
pint.json
jsconfig.json
EOF
}

rip_capture_collect_existing_include_paths() {
    local laravel_root="$1"
    local rel

    while IFS= read -r rel; do
        [[ -z "${rel}" ]] && continue
        if [[ -e "${laravel_root}/${rel}" ]]; then
            printf '%s\n' "${rel}"
        fi
    done < <(rip_capture_tar_include_paths)
}

rip_capture_build_application_archive() {
    local laravel_root="$1"
    local archive_path="$2"

    if [[ ! -d "${laravel_root}" ]]; then
        echo "rip-capture: laravel root does not exist: ${laravel_root}" >&2
        return 1
    fi

    local -a include_paths=()
    local rel

    while IFS= read -r rel; do
        include_paths+=("${rel}")
    done < <(rip_capture_collect_existing_include_paths "${laravel_root}")

    if [[ ${#include_paths[@]} -eq 0 ]]; then
        echo "rip-capture: no allowlisted paths exist under ${laravel_root}" >&2
        return 1
    fi

    local -a tar_args=(
        -C "${laravel_root}" -cf -
        --exclude='database/*.sqlite'
        --exclude='database/*.sqlite-journal'
    )
    local path

    for path in "${include_paths[@]}"; do
        tar_args+=("${path}")
    done

    if ! tar "${tar_args[@]}" | zstd -T0 -19 -q -o "${archive_path}"; then
        echo "rip-capture: tar or zstd failed while building ${archive_path}" >&2
        rm -f "${archive_path}"
        return 1
    fi

    if [[ ! -s "${archive_path}" ]]; then
        echo "rip-capture: archive is empty or missing: ${archive_path}" >&2
        rm -f "${archive_path}"
        return 1
    fi

    if ! rip_capture_assert_archive_policy "${archive_path}"; then
        rm -f "${archive_path}"
        return 1
    fi

    return 0
}

rip_capture_list_archive_paths() {
    local archive_path="$1"
    zstd -d -c "${archive_path}" | tar -tf -
}

rip_capture_assert_archive_policy() {
    local archive_path="$1"
    local listing
    listing="$(rip_capture_list_archive_paths "${archive_path}")" || return 1

    local line
    while IFS= read -r line; do
        [[ -z "${line}" ]] && continue
        line="${line%/}"
        if ! rip_capture_path_allowed_in_archive "${line}"; then
            echo "rip-capture: archive policy violation (path not allowlisted): ${line}" >&2
            return 1
        fi
    done <<< "${listing}"

    local forbidden
    while IFS= read -r forbidden; do
        [[ -z "${forbidden}" ]] && continue
        if echo "${listing}" | grep -qE "${forbidden}"; then
            echo "rip-capture: archive policy violation (forbidden pattern ${forbidden})" >&2
            return 1
        fi
    done <<'EOF'
(^|/)\.env
(^|/)storage/
(^|/)vendor/
(^|/)node_modules/
(^|/)\.git/
(^|/)tests/
(^|/)\.cursor/
(^|/)bootstrap/cache/
database/.*\.sqlite
EOF

    return 0
}

rip_capture_path_allowed_in_archive() {
    local path="$1"

    case "${path}" in
        app|app/*|config|config/*|public|public/*|resources|resources/*|routes|routes/*|tools|tools/*|contracts|contracts/*|docs|docs/*)
            return 0
            ;;
        database|database/migrations|database/migrations/*|database/seeders|database/seeders/*|database/factories|database/factories/*)
            return 0
            ;;
        bootstrap/app.php|bootstrap/providers.php)
            return 0
            ;;
        artisan|composer.json|composer.lock|package.json|package-lock.json|vite.config.js|tailwind.config.js|postcss.config.js|pint.json|jsconfig.json)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

rip_capture_copy_release_json_snapshot() {
    local laravel_root="$1"
    local staging_dir="$2"
    local src="${laravel_root}/storage/app/private/release.json"
    local dest="${staging_dir}/release.json.snapshot"

    if [[ ! -f "${src}" ]]; then
        return 0
    fi

    cp "${src}" "${dest}"
    chmod 640 "${dest}"
}
