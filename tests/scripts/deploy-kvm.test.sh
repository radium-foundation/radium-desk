#!/usr/bin/env bash
#
# Static checks for tools/commands/deploy-kvm.sh (no production deploy).
#
# Run: bash tests/scripts/deploy-kvm.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/tools/commands/deploy-kvm.sh"
SAFETY_LIB="$ROOT/tools/lib/deploy-rsync-safety.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$SCRIPT" ]] || fail "deploy-kvm.sh missing"
[[ -f "$SAFETY_LIB" ]] || fail "deploy-rsync-safety.sh missing"
bash -n "$SCRIPT" || fail "deploy-kvm.sh syntax check failed"
bash -n "$SAFETY_LIB" || fail "deploy-rsync-safety.sh syntax check failed"
pass "deploy-kvm.sh syntax valid"
pass "deploy-rsync-safety.sh syntax valid"

grep -q 'deploy-rsync-safety.sh' "$SCRIPT" || fail "must source deploy-rsync-safety.sh"
grep -q 'deploy_rsync_run_deletion_safety_gate' "$SCRIPT" || fail "must run deletion safety gate before live rsync"
grep -q 'deploy_rsync_analyze_deletions' "$SCRIPT" || fail "must analyze deletions during deploy --dry-run"
grep -q 'delete-unexpected' "$SAFETY_LIB" || fail "must require explicit delete-unexpected approval"
grep -q 'deploy-backups' "$SAFETY_LIB" || fail "must persist deletion inventory under deploy-backups"
grep -q 'storage/app/backups/deploy-rsync-safety' "$SAFETY_LIB" || fail "must back up deletions remotely before sync"
grep -q 'deploy_rsync_init_dry_run_command' "$SAFETY_LIB" || fail "must centralize dry-run rsync argv construction"
grep -q 'deploy_rsync_assert_dry_run_command' "$SAFETY_LIB" || fail "must assert dry-run rsync argv before execution"
grep -q '\-\-dry-run' "$SAFETY_LIB" || fail "must include --dry-run in safety library"
pass "deploy rsync deletion safety integration present"

grep -q 'DEPLOY_MODE' "$SCRIPT" || fail "must enforce DEPLOY_MODE"
grep -q 'redis-vps-preinstall-inspection.md' "$SCRIPT" || fail "must allow known untracked doc"
grep -q '\-\-exclude.*\.env' "$SAFETY_LIB" || fail "must exclude remote .env"
grep -q '\-\-exclude.*\.git/' "$SAFETY_LIB" || fail "must exclude .git"
grep -q '\-\-exclude.*node_modules/' "$SAFETY_LIB" || fail "must exclude node_modules"
grep -q '\-\-exclude.*vendor/' "$SAFETY_LIB" || fail "must exclude vendor"
grep -q '\-\-exclude.*storage/logs/' "$SAFETY_LIB" || fail "must exclude storage/logs"
grep -q '\-\-exclude.*storage/framework/' "$SAFETY_LIB" || fail "must exclude storage/framework"
grep -q 'CHANGELOG.md' "$SCRIPT" || fail "must validate CHANGELOG.md"
grep -q 'describe --exact-match' "$SCRIPT" || fail "must require exact release tag on HEAD"
grep -q 'validate_dry_run_candidate' "$SCRIPT" || fail "must validate read-only dry-run candidate separately from release tag"
grep -q 'exact semver tag is not required' "$SCRIPT" || fail "dry-run must document tag exemption"
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

grep -q 'verify_hardware_dashboard_contract' "$SCRIPT" \
    || fail "must verify Hardware Dashboard P-302 contract before deploy"
grep -q 'verify-hardware-dashboard-contract.sh' "$SCRIPT" \
    || fail "must invoke verify-hardware-dashboard-contract.sh"
grep -q 'verify_ready_queue_contract' "$SCRIPT" \
    || fail "must verify Service Ready Queue contract before deploy"
grep -q 'verify-ready-queue-contract.sh' "$SCRIPT" \
    || fail "must invoke verify-ready-queue-contract.sh"

grep -q '\-\-exclude.*bootstrap/cache/' "$SAFETY_LIB" \
    || fail "must exclude bootstrap/cache from rsync"

# --- release.json rsync filter regression (static ordering) ---

extract_rsync_filters() {
    awk '
        /^deploy_kvm_rsync_application_filters\(\)/ { in_fn=1; next }
        in_fn && /^}/ { exit }
        in_fn && /^--(include|exclude)$/ {
            getline
            gsub(/^[[:space:]]+/, "", $0)
            printf "'\''%s'\''\n", $0
            next
        }
    ' "$SAFETY_LIB"
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
    || fail "bootstrap/cache/ exclude must be part of application rsync filter set in deploy-rsync-safety.sh"

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

# --- legacy deployed-commit.txt deprecation (P-25-09-44) ---

grep -q 'remove_legacy_deployed_commit_marker' "$SCRIPT" \
    || fail "must remove legacy deployed-commit.txt marker after post-sync"
grep -q 'storage/app/deployed-commit.txt' "$SCRIPT" \
    || fail "must reference legacy deployed-commit.txt path for cleanup"
grep -q 'remove_legacy_deployed_commit_marker' "$SCRIPT" \
    && grep -q 'run_remote_post_sync' "$SCRIPT" \
    || fail "must call legacy marker cleanup near post-sync"

LEGACY_CLEANUP_BLOCK="$(awk '/^remove_legacy_deployed_commit_marker\(\)/,/^}/' "$SCRIPT")"
[[ -n "$LEGACY_CLEANUP_BLOCK" ]] || fail "could not extract remove_legacy_deployed_commit_marker from deploy-kvm.sh"
echo "$LEGACY_CLEANUP_BLOCK" | grep -q "rm -f" \
    || fail "legacy marker cleanup must remove file with rm -f"
echo "$LEGACY_CLEANUP_BLOCK" | grep -q 'release.json' \
    || fail "legacy marker cleanup must reference release.json as authoritative"
if echo "$LEGACY_CLEANUP_BLOCK" | grep -qE 'echo.*deployed-commit|file_put_contents|>.*deployed-commit'; then
    fail "must not write or recreate deployed-commit.txt"
fi

RSYNC_FILTER_TEXT="$(extract_rsync_filters | tr '\n' ' ')"
if [[ "$RSYNC_FILTER_TEXT" == *"deployed-commit"* ]]; then
    fail "must not rsync deployed-commit.txt"
fi

pass "legacy deployed-commit.txt deprecation guard present"

echo "All deploy-kvm static checks passed."
