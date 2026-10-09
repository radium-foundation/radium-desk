#!/usr/bin/env bash
#
# Rsync deletion safety for KVM deploy (pre-dry-run inventory, gate, backup, audit).
# Sourced by deploy-kvm.sh — do not execute directly.

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "deploy-rsync-safety.sh must be sourced, not executed directly." >&2
    exit 1
fi

# Wrapper for rsync execution (tests may override to capture argv).
deploy_rsync_exec() {
    "$@"
}

# Central dry-run rsync base flags. Every analyze/dry-run path must use this.
deploy_rsync_init_dry_run_command() {
    DEPLOY_RSYNC_DRY_RUN_BASE=(rsync -avzi --dry-run --delete)
}

deploy_rsync_assert_dry_run_command() {
    local -a cmd=("$@")
    local saw_dry_run=0 saw_delete=0

    for arg in "${cmd[@]}"; do
        [[ "$arg" == "--dry-run" ]] && saw_dry_run=1
        [[ "$arg" == "--delete" ]] && saw_delete=1
    done

    if [[ "$saw_dry_run" -ne 1 || "$saw_delete" -ne 1 ]]; then
        print_error "Refusing rsync analyze command without --dry-run and --delete"
        return 1
    fi

    return 0
}

# --- Protected paths (rsync excludes + explicit persistent production state) ---

deploy_rsync_protected_patterns() {
    cat <<'EOF'
^\.env$
^\.env\.(mysql|sqlite|sqlite\.backup|local|production|testing\.local)$
^\.git/?$
^\.cursor/
^\.DS_Store$
^database/.*\.sqlite
^node_modules/
^vendor/
^storage/logs/
^storage/framework/
^bootstrap/cache/
^tests/
^public/build/
^storage/app/backups/
^storage/app/deploy-backups/
^storage/app/private/
^storage/app/public/
^storage/app/google/
^storage/app/tmp/
^storage/app/[^/]+$
^storage/[^/]+$
^docs/ca-evidence/
^assets/
EOF
}

deploy_rsync_is_protected_path() {
    local rel="${1#./}"
    rel="${rel#/}"

    while IFS= read -r pattern; do
        [[ -z "$pattern" ]] && continue
        if [[ "$rel" =~ $pattern ]]; then
            return 0
        fi
    done < <(deploy_rsync_protected_patterns)

    return 1
}

# Build rsync argument tail (filters + source/dest) shared by dry-run and live sync.
deploy_kvm_rsync_application_filters() {
    cat <<'EOF'
--exclude
.git/
--exclude
.git
--exclude
.env
--exclude
.env.mysql
--exclude
.env.sqlite
--exclude
.env.sqlite.backup
--exclude
.env.local
--exclude
.env.production
--exclude
.env.testing.local
--exclude
.cursor/
--exclude
.DS_Store
--exclude
database/*.sqlite
--exclude
database/*.sqlite*
--exclude
node_modules/
--exclude
vendor/
--exclude
storage/logs/
--exclude
storage/framework/
--exclude
bootstrap/cache/
--exclude
tests/
--include
storage/
--include
storage/app/
--include
storage/app/private/
--include
storage/app/private/release.json
--exclude
storage/app/private/*
--exclude
storage/app/*
--exclude
storage/*
--exclude
public/build/
--exclude
docs/ca-evidence/
--exclude
assets/
EOF
}

deploy_kvm_rsync_ssh_command() {
    printf 'ssh -p %s -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new' "$SSH_PORT"
}

deploy_kvm_rsync_remote_target() {
    printf '%s@%s:%s/' "$SSH_USER" "$SSH_HOST" "$REMOTE_PROJECT"
}

# Parse rsync --itemize-changes dry-run output for paths rsync would delete.
deploy_rsync_parse_deletion_paths() {
    local dry_run_log="$1"

    awk '
        /^\*deleting / {
            sub(/^\*deleting /, "")
            if ($0 != "") {
                print $0
            }
        }
    ' "$dry_run_log" | sort -u
}

# public/build rsync is rooted at public/build/, so deletion paths need that prefix for classification.
deploy_rsync_parse_public_build_deletion_paths() {
    local dry_run_log="$1"

    deploy_rsync_parse_deletion_paths "$dry_run_log" | while IFS= read -r path; do
        [[ -z "$path" ]] && continue
        printf 'public/build/%s\n' "${path#./}"
    done
}

deploy_rsync_previous_release_tag() {
    local current_tag="$1"
    local tags tag

    tags="$(git -C "$PROJECT_ROOT" tag -l 'v*' --sort=-v:refname)"
    while IFS= read -r tag; do
        [[ -z "$tag" ]] && continue
        if [[ "$tag" == "$current_tag" ]]; then
            continue
        fi
        if git -C "$PROJECT_ROOT" merge-base --is-ancestor "$tag" HEAD 2>/dev/null; then
            printf '%s' "$tag"
            return 0
        fi
    done <<< "$tags"

    return 1
}

deploy_rsync_classify_deletion_path() {
    local rel_path="$1"
    local head_ref="$2"
    local previous_tag="${3:-}"

    rel_path="${rel_path#./}"
    rel_path="${rel_path#/}"

    if [[ "$rel_path" == public/build/* ]]; then
        printf 'expected'
        return 0
    fi

    if deploy_rsync_is_protected_path "$rel_path"; then
        printf 'protected'
        return 0
    fi

    if git -C "$PROJECT_ROOT" cat-file -e "${head_ref}:${rel_path}" 2>/dev/null; then
        printf 'protected'
        return 0
    fi

    if [[ -n "$previous_tag" ]] && git -C "$PROJECT_ROOT" cat-file -e "${previous_tag}:${rel_path}" 2>/dev/null; then
        printf 'expected'
        return 0
    fi

    printf 'unexpected'
}

deploy_rsync_run_application_dry_run() {
    local output_file="$1"
    local -a rsync_cmd=()

    deploy_rsync_init_dry_run_command
    rsync_cmd=("${DEPLOY_RSYNC_DRY_RUN_BASE[@]}")
    deploy_rsync_assert_dry_run_command "${rsync_cmd[@]}" || return 1

    rsync_cmd+=(-e "$(deploy_kvm_rsync_ssh_command)")

    while IFS= read -r filter_line; do
        [[ -z "$filter_line" ]] && continue
        rsync_cmd+=("$filter_line")
    done < <(deploy_kvm_rsync_application_filters)

    rsync_cmd+=("${PROJECT_ROOT}/" "$(deploy_kvm_rsync_remote_target)")

    deploy_rsync_exec "${rsync_cmd[@]}" >"$output_file" 2>&1
}

deploy_rsync_run_public_build_dry_run() {
    local output_file="$1"
    local -a rsync_cmd=()

    deploy_rsync_init_dry_run_command
    rsync_cmd=("${DEPLOY_RSYNC_DRY_RUN_BASE[@]}")
    deploy_rsync_assert_dry_run_command "${rsync_cmd[@]}" || return 1

    rsync_cmd+=(
        -e "$(deploy_kvm_rsync_ssh_command)"
        "${PROJECT_ROOT}/public/build/"
        "${SSH_USER}@${SSH_HOST}:${REMOTE_PROJECT}/public/build/"
    )

    deploy_rsync_exec "${rsync_cmd[@]}" >"$output_file" 2>&1
}

deploy_rsync_write_inventory() {
    local inventory_dir="$1"
    local stamp="$2"
    local current_tag="$3"
    local previous_tag="$4"
    local dry_run_log="$5"
    local summary_tsv="$6"

    mkdir -p "$inventory_dir"

    cp "$dry_run_log" "${inventory_dir}/rsync-dry-run-${stamp}.log"
    cp "$summary_tsv" "${inventory_dir}/deletion-classification-${stamp}.tsv"

    {
        echo "timestamp=${stamp}"
        echo "release_tag=${current_tag}"
        echo "previous_tag=${previous_tag:-none}"
        echo "project_root=${PROJECT_ROOT}"
        echo "remote_target=$(deploy_kvm_rsync_remote_target)"
        echo "dry_run_log=rsync-dry-run-${stamp}.log"
        echo "classification=deletion-classification-${stamp}.tsv"
    } >"${inventory_dir}/manifest-${stamp}.txt"
}

deploy_rsync_backup_remote_deletions() {
    local backup_dir="$1"
    local inventory_tsv="$2"
    local rel_path

    if [[ ! -s "$inventory_tsv" ]]; then
        return 0
    fi

    ssh_exec "mkdir -p '${backup_dir}'"

    while IFS=$'\t' read -r _classification rel_path _; do
        [[ -z "$rel_path" ]] && continue
        if deploy_rsync_is_protected_path "$rel_path"; then
            continue
        fi

        if ! ssh_exec "test -e '${REMOTE_PROJECT}/${rel_path}'"; then
            print_warning "Skipping backup for missing remote path: ${rel_path}"
            continue
        fi

        if ! ssh_exec "mkdir -p '${backup_dir}/$(dirname "${rel_path}")' && cp -a '${REMOTE_PROJECT}/${rel_path}' '${backup_dir}/${rel_path}'"; then
            print_error "Failed to back up remote path before deletion: ${rel_path}"
            return 1
        fi
    done < "$inventory_tsv"

    if ! ssh_exec "cp '${backup_dir}/../deletion-classification-${DEPLOY_RSYNC_STAMP:-unknown}.tsv' '${backup_dir}/' 2>/dev/null || true"; then
        :
    fi

    print_success "Remote deletion backup completed: ${backup_dir}"
    return 0
}

deploy_rsync_confirm_unexpected_deletions() {
    local unexpected_count="$1"
    local inventory_file="$2"
    local answer

    if [[ "$unexpected_count" -le 0 ]]; then
        return 0
    fi

    if [[ "${DEPLOY_RSYNC_FORCE_UNEXPECTED:-0}" == "1" ]]; then
        print_warning "DEPLOY_RSYNC_FORCE_UNEXPECTED=1 — skipping interactive unexpected-deletion prompt (test mode only)"
        return 0
    fi

    print_error "Unexpected production-only application deletions detected: ${unexpected_count}"
    print_warning "Review inventory: ${inventory_file}"
    print_warning "Type 'delete-unexpected' to approve deleting these files, or anything else to abort:"

    if [[ ! -t 0 ]]; then
        print_error "Refusing to delete unexpected files without an interactive TTY confirmation."
        return 1
    fi

    read -r answer
    if [[ "$answer" != "delete-unexpected" ]]; then
        print_error "Deployment aborted: unexpected deletions not approved."
        return 1
    fi

    print_success "Unexpected deletions explicitly approved by operator."
    return 0
}

# Analyze deletions only (dry-run + inventory). Used by deploy --dry-run.
deploy_rsync_analyze_deletions() {
    local stamp current_tag previous_tag head_ref
    local dry_run_log build_dry_log classification_tsv
    local path classification
    local total=0 expected=0 unexpected=0 protected=0
    local inventory_root inventory_dir

    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    head_ref="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
    current_tag="$(git -C "$PROJECT_ROOT" describe --exact-match --tags HEAD 2>/dev/null || true)"
    previous_tag="$(deploy_rsync_previous_release_tag "$current_tag" 2>/dev/null || true)"

    inventory_root="${PROJECT_ROOT}/storage/app/deploy-backups"
    inventory_dir="${inventory_root}/rsync-deletion-inventory-${stamp}"
    mkdir -p "$inventory_dir"

    dry_run_log="$(mktemp)"
    build_dry_log="$(mktemp)"
    classification_tsv="$(mktemp)"

    if ! deploy_rsync_run_application_dry_run "$dry_run_log"; then
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 1
    fi

    if ! deploy_rsync_run_public_build_dry_run "$build_dry_log"; then
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 1
    fi

    {
        deploy_rsync_parse_deletion_paths "$dry_run_log"
        deploy_rsync_parse_public_build_deletion_paths "$build_dry_log"
    } | sort -u >"${inventory_dir}/deletion-paths-${stamp}.txt"

    while IFS= read -r path; do
        [[ -z "$path" ]] && continue
        classification="$(deploy_rsync_classify_deletion_path "$path" "$head_ref" "$previous_tag")"
        printf '%s\t%s\n' "$classification" "$path" >>"$classification_tsv"
        total=$((total + 1))
        case "$classification" in
            expected) expected=$((expected + 1)) ;;
            unexpected) unexpected=$((unexpected + 1)) ;;
            protected) protected=$((protected + 1)) ;;
        esac
    done < "${inventory_dir}/deletion-paths-${stamp}.txt"

    deploy_rsync_write_inventory "$inventory_dir" "$stamp" "$current_tag" "$previous_tag" "$dry_run_log" "$classification_tsv"
    cp "$build_dry_log" "${inventory_dir}/rsync-dry-run-public-build-${stamp}.log"

    print_warning "Deletion inventory: total=${total}, expected=${expected}, unexpected=${unexpected}, protected=${protected}"
    print_warning "Persistent audit directory: ${inventory_dir}"

    if [[ "$unexpected" -gt 0 ]]; then
        print_warning "Unexpected deletions would require explicit 'delete-unexpected' approval before live deploy."
    fi

    rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
    DEPLOY_RSYNC_INVENTORY_DIR="$inventory_dir"
    return 0
}

# Full pre-destructive-sync safety gate. Sets DEPLOY_RSYNC_INVENTORY_DIR on success.
deploy_rsync_run_deletion_safety_gate() {
    local stamp current_tag previous_tag head_ref
    local dry_run_log build_dry_log classification_tsv
    local -a deletion_paths=()
    local path classification
    local total=0 expected=0 unexpected=0 protected=0
    local inventory_root inventory_dir remote_backup_dir

    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    DEPLOY_RSYNC_STAMP="$stamp"

    head_ref="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
    current_tag="$(git -C "$PROJECT_ROOT" describe --exact-match --tags HEAD 2>/dev/null || true)"
    previous_tag="$(deploy_rsync_previous_release_tag "$current_tag" 2>/dev/null || true)"

    inventory_root="${PROJECT_ROOT}/storage/app/deploy-backups"
    inventory_dir="${inventory_root}/rsync-deletion-inventory-${stamp}"
    mkdir -p "$inventory_dir"

    dry_run_log="$(mktemp)"
    build_dry_log="$(mktemp)"
    classification_tsv="$(mktemp)"

    print_warning "Running pre-deploy rsync deletion dry-run (application tree)..."
    if ! deploy_rsync_run_application_dry_run "$dry_run_log"; then
        print_error "Application rsync dry-run failed."
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 1
    fi

    print_warning "Running pre-deploy rsync deletion dry-run (public/build)..."
    if ! deploy_rsync_run_public_build_dry_run "$build_dry_log"; then
        print_error "public/build rsync dry-run failed."
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 1
    fi

    {
        deploy_rsync_parse_deletion_paths "$dry_run_log"
        deploy_rsync_parse_public_build_deletion_paths "$build_dry_log"
    } | sort -u >"${inventory_dir}/deletion-paths-${stamp}.txt"

    while IFS= read -r path; do
        [[ -z "$path" ]] && continue
        classification="$(deploy_rsync_classify_deletion_path "$path" "$head_ref" "$previous_tag")"
        printf '%s\t%s\n' "$classification" "$path" >>"$classification_tsv"
        total=$((total + 1))
        case "$classification" in
            expected) expected=$((expected + 1)) ;;
            unexpected) unexpected=$((unexpected + 1)) ;;
            protected) protected=$((protected + 1)) ;;
        esac
    done < "${inventory_dir}/deletion-paths-${stamp}.txt"

    deploy_rsync_write_inventory "$inventory_dir" "$stamp" "$current_tag" "$previous_tag" "$dry_run_log" "$classification_tsv"
    cp "$build_dry_log" "${inventory_dir}/rsync-dry-run-public-build-${stamp}.log"

    {
        echo "total_deletions=${total}"
        echo "expected=${expected}"
        echo "unexpected=${unexpected}"
        echo "protected=${protected}"
    } >"${inventory_dir}/summary-${stamp}.txt"

    print_warning "Deletion inventory: total=${total}, expected=${expected}, unexpected=${unexpected}, protected=${protected}"
    print_warning "Persistent audit directory: ${inventory_dir}"

    if [[ "$protected" -gt 0 ]]; then
        print_error "Protected paths appeared in rsync deletion output — refusing to continue."
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 1
    fi

    if [[ "$total" -eq 0 ]]; then
        print_success "No application deletions detected — safe to synchronize."
        DEPLOY_RSYNC_INVENTORY_DIR="$inventory_dir"
        rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
        return 0
    fi

    if [[ "$unexpected" -gt 0 ]]; then
        if ! deploy_rsync_confirm_unexpected_deletions "$unexpected" "$classification_tsv"; then
            rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
            return 1
        fi
    else
        print_success "Only expected release-tree deletions detected (${expected})."
    fi

    if [[ "${DEPLOY_RSYNC_SKIP_BACKUP:-0}" == "1" ]]; then
        print_warning "DEPLOY_RSYNC_SKIP_BACKUP=1 — skipping remote deletion backup (test mode only)"
    else
        remote_backup_dir="${REMOTE_PROJECT}/storage/app/backups/deploy-rsync-safety-${stamp}"
        ssh_exec "mkdir -p '${remote_backup_dir}'"
        scp -P "$SSH_PORT" -q "$classification_tsv" "${SSH_USER}@${SSH_HOST}:${remote_backup_dir}/deletion-classification-${stamp}.tsv"

        if ! deploy_rsync_backup_remote_deletions "$remote_backup_dir" "$classification_tsv"; then
            rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
            return 1
        fi

        print_success "Remote pre-delete backup: ${remote_backup_dir}"
    fi

    DEPLOY_RSYNC_INVENTORY_DIR="$inventory_dir"
    rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
    return 0
}

# Local test helpers (no SSH) -------------------------------------------------

deploy_rsync_local_run_dry_run() {
    local source_root="$1"
    local dest_root="$2"
    local output_file="$3"

    mkdir -p "$dest_root"
    local -a rsync_cmd=()

    deploy_rsync_init_dry_run_command
    rsync_cmd=("${DEPLOY_RSYNC_DRY_RUN_BASE[@]}")
    deploy_rsync_assert_dry_run_command "${rsync_cmd[@]}" || return 1

    while IFS= read -r filter_line; do
        [[ -z "$filter_line" ]] && continue
        rsync_cmd+=("$filter_line")
    done < <(deploy_kvm_rsync_application_filters)

    rsync_cmd+=("${source_root}/" "${dest_root}/")

    deploy_rsync_exec "${rsync_cmd[@]}" >"$output_file" 2>&1
}

deploy_rsync_local_backup_deletions() {
    local backup_dir="$1"
    local dest_root="$2"
    local inventory_tsv="$3"
    local rel_path

    mkdir -p "$backup_dir"

    while IFS=$'\t' read -r _classification rel_path _; do
        [[ -z "$rel_path" ]] && continue
        if deploy_rsync_is_protected_path "$rel_path"; then
            continue
        fi
        if [[ ! -e "${dest_root}/${rel_path}" ]]; then
            continue
        fi
        mkdir -p "${backup_dir}/$(dirname "${rel_path}")"
        if ! cp -a "${dest_root}/${rel_path}" "${backup_dir}/${rel_path}"; then
            return 1
        fi
    done < "$inventory_tsv"

    return 0
}
