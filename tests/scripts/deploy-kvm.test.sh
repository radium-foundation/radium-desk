#!/usr/bin/env bash
#
# Static checks for tools/commands/deploy-kvm.sh (no production deploy).
#
# Run: bash tests/scripts/deploy-kvm.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/tools/commands/deploy-kvm.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$SCRIPT" ]] || fail "deploy-kvm.sh missing"
bash -n "$SCRIPT" || fail "deploy-kvm.sh syntax check failed"
pass "deploy-kvm.sh syntax valid"

grep -q 'DEPLOY_MODE' "$SCRIPT" || fail "must enforce DEPLOY_MODE"
grep -q 'redis-vps-preinstall-inspection.md' "$SCRIPT" || fail "must allow known untracked doc"
grep -q '\-\-exclude.*\.env' "$SCRIPT" || fail "must exclude remote .env"
grep -q '\-\-exclude.*\.git/' "$SCRIPT" || fail "must exclude .git"
grep -q '\-\-exclude.*node_modules/' "$SCRIPT" || fail "must exclude node_modules"
grep -q '\-\-exclude.*vendor/' "$SCRIPT" || fail "must exclude vendor"
grep -q '\-\-exclude.*storage/logs/' "$SCRIPT" || fail "must exclude storage/logs"
grep -q '\-\-exclude.*storage/framework/' "$SCRIPT" || fail "must exclude storage/framework"
grep -q 'CHANGELOG.md' "$SCRIPT" || fail "must validate CHANGELOG.md"
grep -q 'describe --exact-match' "$SCRIPT" || fail "must require exact release tag on HEAD"
grep -q 'sync_kvm_public_build' "$SCRIPT" || fail "must sync KVM public/build"
grep -q 'kvm_restart_supervisor_worker' "$SCRIPT" || fail "must restart KVM supervisor worker"
grep -q 'kvm_health_check' "$SCRIPT" || fail "must run KVM health check"
grep -q 'kvm_verify_vite_assets' "$SCRIPT" || fail "must verify KVM Vite assets"
if grep -vE '^\s*#' "$SCRIPT" | grep -qE '\bgit pull\b'; then
    fail "must not use remote git pull"
fi
if grep -vE '^\s*#' "$SCRIPT" | grep -qE '\bgit reset\b'; then
    fail "must not use remote git reset"
fi
grep -q 'generate_shared_hosting_index' "$SCRIPT" && fail "must not use shared-hosting index generation"
grep -q 'LEGACY_REMOTE_PUBLIC' "$SCRIPT" && fail "must not target legacy public_html"
grep -q 'radium-backup' "$SCRIPT" && fail "must not reference backup paths"
grep -q '/root/.radium-backup.env' "$SCRIPT" && fail "must not reference backup env"

pass "deploy-kvm safety guards present"

grep -q '\-\-exclude.*bootstrap/cache/' "$SCRIPT" \
    || fail "must exclude bootstrap/cache from rsync"

# --- release.json rsync filter regression (static ordering) ---

extract_rsync_filters() {
    awk '
        /rsync_args\+=\(/ { in_block=1; next }
        in_block && /^[[:space:]]*\)[[:space:]]*$/ { exit }
        in_block && /--(include|exclude)/ {
            if (match($0, /'\''[^'\'']+'\''/)) {
                print substr($0, RSTART, RLENGTH)
            }
        }
    ' "$SCRIPT"
}

RSYNC_FILTERS=()
while IFS= read -r filter; do
    RSYNC_FILTERS+=("$filter")
done < <(extract_rsync_filters)

[[ "${#RSYNC_FILTERS[@]}" -gt 0 ]] || fail "could not extract rsync filters from deploy-kvm.sh"

find_filter_index() {
    local pattern="$1"
    local i filter

    for i in "${!RSYNC_FILTERS[@]}"; do
        filter="${RSYNC_FILTERS[$i]}"
        if [[ "$filter" == "$pattern" ]]; then
            echo "$i"
            return 0
        fi
    done

    return 1
}

release_json_idx="$(find_filter_index "'storage/app/private/release.json'")" \
    || fail "missing --include for storage/app/private/release.json"
private_exclude_idx="$(find_filter_index "'storage/app/private/*'")" \
    || fail "missing --exclude for storage/app/private/*"
app_exclude_idx="$(find_filter_index "'storage/app/*'")" \
    || fail "missing --exclude for storage/app/*"
storage_exclude_idx="$(find_filter_index "'storage/*'")" \
    || fail "missing --exclude for storage/*"
logs_exclude_idx="$(find_filter_index "'storage/logs/'")" \
    || fail "missing --exclude for storage/logs/"
framework_exclude_idx="$(find_filter_index "'storage/framework/'")" \
    || fail "missing --exclude for storage/framework/"

for parent in "'storage/'" "'storage/app/'" "'storage/app/private/'"; do
    parent_idx="$(find_filter_index "$parent")" \
        || fail "missing parent --include ${parent}"
    if [[ "$parent_idx" -ge "$release_json_idx" ]]; then
        fail "parent include ${parent} must appear before release.json include"
    fi
done

if [[ "$release_json_idx" -ge "$private_exclude_idx" ]]; then
    fail "release.json include must appear before storage/app/private/* exclude"
fi

if [[ "$private_exclude_idx" -ge "$app_exclude_idx" ]]; then
    fail "storage/app/private/* exclude must appear before storage/app/* exclude"
fi

if [[ "$app_exclude_idx" -ge "$storage_exclude_idx" ]]; then
    fail "storage/app/* exclude must appear before storage/* exclude"
fi

if [[ "$logs_exclude_idx" -ge "$release_json_idx" ]]; then
    fail "storage/logs/ exclude should appear before release.json include"
fi

if [[ "$framework_exclude_idx" -ge "$release_json_idx" ]]; then
    fail "storage/framework/ exclude should appear before release.json include"
fi

storage_includes=()
for filter in "${RSYNC_FILTERS[@]}"; do
    case "$filter" in
        "'storage/'"|"'storage/app/'"|"'storage/app/private/'"|"'storage/app/private/release.json'")
            storage_includes+=("$filter")
            ;;
    esac
done

[[ "${#storage_includes[@]}" -eq 4 ]] || fail "expected exactly 4 storage include rules (parents + release.json)"

pass "release.json rsync filter ordering valid"

find_filter_index "'bootstrap/cache/'" >/dev/null \
    || fail "bootstrap/cache/ exclude must be part of application rsync filter set"

pass "bootstrap/cache rsync protection present"

# --- fix_remote_ownership regression (v4.0.47 incident) ---

OWNERSHIP_BLOCK="$(awk '/^fix_remote_ownership\(\)/,/^}/' "$SCRIPT")"

[[ -n "$OWNERSHIP_BLOCK" ]] || fail "could not extract fix_remote_ownership from deploy-kvm.sh"

echo "$OWNERSHIP_BLOCK" | grep -q 'SSH_USER' \
    || fail "fix_remote_ownership must use SSH_USER for ownership"
echo "$OWNERSHIP_BLOCK" | grep -q 'chown -R ravi:ravi' \
    && fail "fix_remote_ownership must not hardcode chown -R ravi:ravi"
echo "$OWNERSHIP_BLOCK" | grep -q 'storage/logs' \
    || fail "fix_remote_ownership must reference storage/logs skip"
echo "$OWNERSHIP_BLOCK" | grep -q 'node_modules' \
    || fail "fix_remote_ownership must reference node_modules skip"
echo "$OWNERSHIP_BLOCK" | grep -q '\-prune' \
    || fail "fix_remote_ownership must prune excluded paths during ownership traversal"

pass "fix_remote_ownership skips excluded Supervisor logs and node_modules"

echo "All deploy-kvm static checks passed."
