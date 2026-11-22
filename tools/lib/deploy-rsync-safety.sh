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

# OpenSSH remote exec does not use a shell; rules such as "--filter" + "P vendor/**" must be
# a single remote argv. bash -lc with one quoted string preserves multi-word rsync rules.
deploy_rsync_ssh_exec_argv() {
    local remote_argv_quoted

    printf -v remote_argv_quoted '%q ' "$@"
    remote_argv_quoted="${remote_argv_quoted% }"
    ssh_exec bash -lc "$(printf '%q' "$remote_argv_quoted")"
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
^\.env\.bak
^\.env\.(mysql|sqlite|sqlite\.backup|local|production|testing\.local)$
^\.git/?$
^\.cursor/
^\.DS_Store$
^database/.*\.sqlite
^node_modules/
^vendor/
^vendor$
^tmp/
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

# Production runtime release identity — never classify as unexpected or allow rsync --delete.
deploy_rsync_is_runtime_release_identity_path() {
    local rel="${1#./}"
    rel="${rel#/}"

    [[ "$rel" == "storage/app/private/release.json" ]]
}

deploy_rsync_is_protected_path() {
    local rel="${1#./}"
    rel="${rel#/}"

    if deploy_rsync_is_runtime_release_identity_path "$rel"; then
        return 0
    fi

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
.env.bak*
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
--include
storage/app/private/cw-pilot-refund-ref-67392-manifest.json
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
--filter
P vendor/**
--filter
P node_modules/**
--exclude
/vendor
--exclude
/tmp/
EOF
}

deploy_kvm_rsync_ssh_command() {
    printf 'ssh -p %s -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new' "$SSH_PORT"
}

deploy_kvm_rsync_remote_target() {
    printf '%s@%s:%s/' "$SSH_USER" "$SSH_HOST" "$REMOTE_PROJECT"
}

# Normalize a relative repo path from rsync output or TSV (trim; reject unsafe paths).
deploy_rsync_normalize_rel_path() {
    local raw="$1"
    local path="$raw"

    path="${path//$'\r'/}"
    path="${path#"${path%%[![:space:]]*}"}"
    path="${path%"${path##*[![:space:]]}"}"

    if [[ -z "$path" ]]; then
        return 1
    fi

    if [[ "$path" == /* ]]; then
        print_error "Refusing absolute deletion path: ${path}"
        return 1
    fi

    if [[ "$path" == *..* ]]; then
        print_error "Refusing path traversal in deletion path: ${path}"
        return 1
    fi

    if [[ "$path" == *$'\t'* ]]; then
        print_error "Refusing tab character in deletion path: ${path}"
        return 1
    fi

    path="${path#./}"
    path="${path#/}"

    if [[ -z "$path" ]]; then
        return 1
    fi

    printf '%s' "$path"
    return 0
}

deploy_rsync_rel_path_is_directory_marker() {
    [[ "${1:-}" == */ ]]
}

deploy_rsync_strip_trailing_slash() {
    local path="${1:-}"
    while [[ "$path" == */ ]]; do
        path="${path%/}"
    done
    printf '%s' "$path"
}

deploy_rsync_parse_classification_tsv_line() {
    local line="$1"
    local classification raw_path rel_path

    line="${line//$'\r'/}"
    [[ -z "${line//[[:space:]]/}" ]] && return 1

    IFS=$'\t' read -r classification raw_path _ <<<"$line"
    classification="${classification#"${classification%%[![:space:]]*}"}"
    classification="${classification%"${classification##*[![:space:]]}"}"

    if [[ -z "$classification" ]]; then
        print_error "Classification TSV line missing classification field"
        return 1
    fi

    case "$classification" in
        expected | unexpected | protected) ;;
        *)
            print_error "Classification TSV has invalid classification: ${classification}"
            return 1
            ;;
    esac

    if ! rel_path="$(deploy_rsync_normalize_rel_path "$raw_path")"; then
        print_error "Classification TSV has invalid path field: ${raw_path:-<empty>}"
        return 1
    fi

    DEPLOY_RSYNC_PARSED_CLASSIFICATION="$classification"
    DEPLOY_RSYNC_PARSED_REL_PATH="$rel_path"
    return 0
}

# Parse rsync --itemize-changes dry-run output for paths rsync would delete.
deploy_rsync_parse_deletion_paths() {
    local dry_run_log="$1"

    awk '
        /^\*deleting / {
            sub(/^\*deleting /, "")
            gsub(/\r$/, "")
            gsub(/^[ \t]+|[ \t]+$/, "")
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

    if ! rel_path="$(deploy_rsync_normalize_rel_path "$rel_path")"; then
        printf 'protected'
        return 0
    fi

    if deploy_rsync_is_runtime_release_identity_path "$rel_path"; then
        printf 'protected'
        return 0
    fi

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

# macOS ships openrsync, which can exit 23 on --dry-run --delete to a remote tree even when
# no *deleting lines are emitted (attribute simulation against production). Linux GNU rsync on
# the KVM is used for the application deletion inventory in that case (staging under /tmp only).
deploy_rsync_operator_is_darwin() {
    [[ "$(uname -s)" == "Darwin" ]]
}

deploy_rsync_use_kvm_linux_application_dry_run() {
    if [[ "${DEPLOY_RSYNC_FORCE_LOCAL_DRY_RUN:-0}" == "1" ]]; then
        return 1
    fi

    if ! deploy_rsync_operator_is_darwin; then
        return 1
    fi

    if [[ "${DEPLOY_MODE:-}" != "kvm" ]]; then
        return 1
    fi

    if [[ -z "${SSH_HOST:-}" || -z "${REMOTE_PROJECT:-}" ]]; then
        return 1
    fi

    if ! declare -f ssh_exec >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

deploy_rsync_stage_release_candidate_on_remote() {
    local staging="$1"
    local -a stage_cmd=()

    ssh_exec "rm -rf '${staging}' && mkdir -p '${staging}'"

    stage_cmd=(rsync -avz)
    stage_cmd+=(-e "$(deploy_kvm_rsync_ssh_command)")

    while IFS= read -r filter_line; do
        [[ -z "$filter_line" ]] && continue
        stage_cmd+=("$filter_line")
    done < <(deploy_kvm_rsync_application_filters)

    stage_cmd+=("${PROJECT_ROOT}/" "${SSH_USER}@${SSH_HOST}:${staging}/")

    deploy_rsync_exec "${stage_cmd[@]}"
}

deploy_rsync_run_application_dry_run_on_kvm_linux() {
    local output_file="$1"
    local staging stamp
    local -a filter_args=() remote_cmd=()

    stamp="${DEPLOY_RSYNC_STAMP:-$(date -u +%Y%m%dT%H%M%SZ)}"
    staging="/tmp/desk-rsync-dryrun-${stamp}"

    # RETURN runs the trap by function name (not as a nested call), so it cannot see
    # function-local variables under set -u. Inline staging path at trap registration time.
    trap "ssh_exec \"rm -rf '${staging}'\" >/dev/null 2>&1 || print_warning \"Could not remove KVM rsync dry-run staging: ${staging}\"" RETURN

    print_warning "Application deletion dry-run: staging candidate on KVM and running GNU rsync --dry-run (${staging})..."

    if ! deploy_rsync_stage_release_candidate_on_remote "$staging"; then
        return 1
    fi

    while IFS= read -r filter_line; do
        [[ -z "$filter_line" ]] && continue
        filter_args+=("$filter_line")
    done < <(deploy_kvm_rsync_application_filters)

    remote_cmd=(rsync -avzi --dry-run --delete)
    for arg in "${filter_args[@]}"; do
        remote_cmd+=("$arg")
    done
    remote_cmd+=("${staging}/" "${REMOTE_PROJECT}/")

    if ! deploy_rsync_ssh_exec_argv "${remote_cmd[@]}" >"$output_file" 2>&1; then
        return 1
    fi

    return 0
}

deploy_rsync_run_application_dry_run() {
    local output_file="$1"
    local -a rsync_cmd=()

    if deploy_rsync_use_kvm_linux_application_dry_run; then
        deploy_rsync_run_application_dry_run_on_kvm_linux "$output_file"
        return $?
    fi

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

deploy_rsync_remote_path_absent() {
    local rel_path="$1"
    local stripped

    stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"

    if ssh_exec "test -e '${REMOTE_PROJECT}/${stripped}'"; then
        return 1
    fi

    if ssh_exec "test -d '${REMOTE_PROJECT}/${stripped}'"; then
        return 1
    fi

    return 0
}

deploy_rsync_remote_is_directory() {
    local rel_path="$1"
    local stripped

    stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
    deploy_rsync_rel_path_is_directory_marker "$rel_path" && return 0
    ssh_exec "test -d '${REMOTE_PROJECT}/${stripped}'"
}

deploy_rsync_remote_file_size() {
    local abs_path="$1"
    local size

    size="$(ssh_exec "stat -c '%s' '${abs_path}'" 2>/dev/null || true)"
    [[ -n "$size" && "$size" =~ ^[0-9]+$ ]] || return 1
    printf '%s' "$size"
    return 0
}

deploy_rsync_path_covered_by_directory_backup() {
    local rel_path="$1"
    local prefix backed

    for backed in "${DEPLOY_RSYNC_BACKED_UP_DIRS[@]:-}"; do
        [[ -z "$backed" ]] && continue
        prefix="${backed%/}/"
        if [[ "$rel_path" == "$backed" || "$rel_path" == "$prefix"* ]]; then
            return 0
        fi
    done

    return 1
}

deploy_rsync_record_directory_backup() {
    local rel_path="$1"
    local stripped

    stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
    DEPLOY_RSYNC_BACKED_UP_DIRS+=("$stripped")
}

deploy_rsync_verify_remote_backup_file() {
    local source_abs="$1"
    local backup_abs="$2"
    local source_size backup_size

    if ! ssh_exec "test -f '${backup_abs}'"; then
        print_error "Backup verification failed: expected regular file missing at ${backup_abs}"
        return 1
    fi

    if ! ssh_exec "test ! -d '${backup_abs}'"; then
        print_error "Backup verification failed: destination is a directory, expected file: ${backup_abs}"
        return 1
    fi

    source_size="$(deploy_rsync_remote_file_size "$source_abs")" || {
        print_error "Backup verification failed: could not read source size for ${source_abs}"
        return 1
    }
    backup_size="$(deploy_rsync_remote_file_size "$backup_abs")" || {
        print_error "Backup verification failed: could not read backup size for ${backup_abs}"
        return 1
    }

    if [[ "$source_size" == "0" ]]; then
        print_error "Backup verification failed: refusing zero-byte source file ${source_abs}"
        return 1
    fi

    if [[ "$backup_size" != "$source_size" ]]; then
        print_error "Backup verification failed: size mismatch for ${backup_abs} (backup=${backup_size}, source=${source_size})"
        return 1
    fi

    return 0
}

deploy_rsync_verify_remote_backup_directory() {
    local backup_abs="$1"

    if ! ssh_exec "test -d '${backup_abs}'"; then
        print_error "Backup verification failed: expected directory missing at ${backup_abs}"
        return 1
    fi

    if ssh_exec "test -n \"\$(find '${backup_abs}' -mindepth 1 -print -quit 2>/dev/null)\""; then
        return 0
    fi

    print_error "Backup verification failed: directory backup is empty at ${backup_abs}"
    return 1
}

deploy_rsync_backup_remote_deletions() {
    local backup_dir="$1"
    local inventory_tsv="$2"
    local line classification rel_path stripped source_abs backup_abs
    local -a verified_payloads=()

    DEPLOY_RSYNC_BACKED_UP_DIRS=()

    if [[ ! -s "$inventory_tsv" ]]; then
        return 0
    fi

    ssh_exec "mkdir -p '${backup_dir}'" || {
        print_error "Failed to create remote deletion backup directory: ${backup_dir}"
        return 1
    }

    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "${line//[[:space:]]/}" ]] && continue

        if ! deploy_rsync_parse_classification_tsv_line "$line"; then
            print_error "Invalid deletion classification record; refusing destructive rsync."
            return 1
        fi

        classification="$DEPLOY_RSYNC_PARSED_CLASSIFICATION"
        rel_path="$DEPLOY_RSYNC_PARSED_REL_PATH"

        if deploy_rsync_is_protected_path "$rel_path"; then
            continue
        fi

        if deploy_rsync_path_covered_by_directory_backup "$rel_path"; then
            continue
        fi

        stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
        source_abs="${REMOTE_PROJECT}/${stripped}"
        backup_abs="${backup_dir}/${stripped}"

        if deploy_rsync_remote_path_absent "$rel_path"; then
            print_warning "Remote path already absent (no backup required): ${rel_path}"
            continue
        fi

        if deploy_rsync_remote_is_directory "$rel_path"; then
            if ! ssh_exec "mkdir -p '${backup_dir}/$(dirname "${stripped}")' && rm -rf '${backup_abs}' && cp -a '${source_abs}' '${backup_abs}'"; then
                print_error "Failed to back up remote directory before deletion: ${rel_path}"
                return 1
            fi
            if ! deploy_rsync_verify_remote_backup_directory "$backup_abs"; then
                return 1
            fi
            deploy_rsync_record_directory_backup "$rel_path"
            verified_payloads+=("${stripped}/")
            continue
        fi

        if ! ssh_exec "test -f '${source_abs}'"; then
            print_error "Remote deletion backup blocked: path is not a regular file: ${rel_path}"
            return 1
        fi

        if ! ssh_exec "mkdir -p '${backup_dir}/$(dirname "${stripped}")' && rm -f '${backup_abs}' && cp -a '${source_abs}' '${backup_abs}'"; then
            print_error "Failed to back up remote file before deletion: ${rel_path}"
            return 1
        fi

        if ! deploy_rsync_verify_remote_backup_file "$source_abs" "$backup_abs"; then
            return 1
        fi

        verified_payloads+=("${stripped}")
    done < "$inventory_tsv"

    if ((${#verified_payloads[@]} > 0)); then
        {
            printf '%s\n' "${verified_payloads[@]}"
        } | ssh_exec "cat > '${backup_dir}/backup-verified-payloads.txt'"
    fi

    if [[ "${#verified_payloads[@]}" -eq 0 ]]; then
        print_warning "No remote deletion payloads required backup (inventory empty or paths already absent)."
        return 0
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
        if ! path="$(deploy_rsync_normalize_rel_path "$path")"; then
            print_error "Deletion dry-run produced an invalid path; refusing to continue."
            rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
            return 1
        fi
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
        if ! path="$(deploy_rsync_normalize_rel_path "$path")"; then
            print_error "Deletion dry-run produced an invalid path; refusing destructive sync."
            rm -f "$dry_run_log" "$build_dry_log" "$classification_tsv"
            return 1
        fi
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

deploy_rsync_local_path_absent() {
    local dest_root="$1"
    local rel_path="$2"
    local stripped

    stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
    [[ ! -e "${dest_root}/${stripped}" ]]
}

deploy_rsync_local_is_directory() {
    local dest_root="$1"
    local rel_path="$2"
    local stripped

    stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
    deploy_rsync_rel_path_is_directory_marker "$rel_path" && return 0
    [[ -d "${dest_root}/${stripped}" ]]
}

deploy_rsync_local_file_size() {
    local path="$1"
    local size

    [[ -f "$path" ]] || return 1
    size="$(wc -c <"$path" | tr -d ' ')"
    [[ "$size" =~ ^[0-9]+$ ]] || return 1
    printf '%s' "$size"
}

deploy_rsync_verify_local_backup_file() {
    local source_path="$1"
    local backup_path="$2"
    local source_size backup_size

    [[ -f "$backup_path" ]] || return 1
    [[ ! -d "$backup_path" ]] || return 1

    source_size="$(deploy_rsync_local_file_size "$source_path")" || return 1
    backup_size="$(deploy_rsync_local_file_size "$backup_path")" || return 1

    [[ "$source_size" -gt 0 ]] || return 1
    [[ "$backup_size" == "$source_size" ]] || return 1
    return 0
}

deploy_rsync_local_backup_deletions() {
    local backup_dir="$1"
    local dest_root="$2"
    local inventory_tsv="$3"
    local line classification rel_path stripped source_path backup_path
    local -a verified_payloads=()

    DEPLOY_RSYNC_BACKED_UP_DIRS=()
    mkdir -p "$backup_dir" || return 1

    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "${line//[[:space:]]/}" ]] && continue

        if ! deploy_rsync_parse_classification_tsv_line "$line"; then
            return 1
        fi

        rel_path="$DEPLOY_RSYNC_PARSED_REL_PATH"

        if deploy_rsync_is_protected_path "$rel_path"; then
            continue
        fi

        if deploy_rsync_path_covered_by_directory_backup "$rel_path"; then
            continue
        fi

        stripped="$(deploy_rsync_strip_trailing_slash "$rel_path")"
        source_path="${dest_root}/${stripped}"
        backup_path="${backup_dir}/${stripped}"

        if deploy_rsync_local_path_absent "$dest_root" "$rel_path"; then
            continue
        fi

        if deploy_rsync_local_is_directory "$dest_root" "$rel_path"; then
            mkdir -p "${backup_dir}/$(dirname "${stripped}")" || return 1
            rm -rf "$backup_path"
            if ! cp -a "$source_path" "$backup_path"; then
                return 1
            fi
            if ! find "$backup_path" -mindepth 1 -print -quit | grep -q .; then
                return 1
            fi
            deploy_rsync_record_directory_backup "$rel_path"
            verified_payloads+=("${stripped}/")
            continue
        fi

        [[ -f "$source_path" ]] || return 1
        mkdir -p "${backup_dir}/$(dirname "${stripped}")" || return 1
        rm -f "$backup_path"
        if ! cp -a "$source_path" "$backup_path"; then
            return 1
        fi
        if ! deploy_rsync_verify_local_backup_file "$source_path" "$backup_path"; then
            return 1
        fi
        verified_payloads+=("${stripped}")
    done < "$inventory_tsv"

    if ((${#verified_payloads[@]} > 0)); then
        printf '%s\n' "${verified_payloads[@]}" >"${backup_dir}/backup-verified-payloads.txt"
    fi

    return 0
}
