#!/usr/bin/env bash
#
# Operator-side staging and invocation of RIP capture scripts on a remote host.
# Sourced by capture-release-recovery-checkpoint.sh (not executed directly).
#
# Requires: DEPLOY_HOST, RIP_CAPTURE_SSH_BIN, RIP_CAPTURE_SCP_BIN,
#           REMOTE_BODY, ARCHIVE_LIB paths set by caller.

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "rip-capture-operator-remote.sh must be sourced, not executed directly." >&2
    exit 1
fi

RIP_CAPTURE_REMOTE_TOOL_STAGE=""

rip_capture_operator_remote_cleanup() {
    if [[ -z "${RIP_CAPTURE_REMOTE_TOOL_STAGE}" ]]; then
        return 0
    fi

    "${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" -- \
        rm -rf -- "${RIP_CAPTURE_REMOTE_TOOL_STAGE}" 2>/dev/null || true
    RIP_CAPTURE_REMOTE_TOOL_STAGE=""
}

rip_capture_operator_remote_stage() {
    local remote_script="${REMOTE_BODY:?}"
    local archive_lib="${ARCHIVE_LIB:?}"

    rip_capture_operator_remote_cleanup

    RIP_CAPTURE_REMOTE_TOOL_STAGE="$("${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" -- \
        mktemp -d /tmp/rip-capture-tools.XXXXXX)"

    if [[ -z "${RIP_CAPTURE_REMOTE_TOOL_STAGE}" ]]; then
        echo "rip-capture: failed to create remote staging directory" >&2
        return 1
    fi

    local remote_commands="${RIP_CAPTURE_REMOTE_TOOL_STAGE}/tools/commands"
    local remote_lib="${RIP_CAPTURE_REMOTE_TOOL_STAGE}/tools/lib"

    "${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" -- \
        mkdir -p -- "${remote_commands}" "${remote_lib}"

    "${RIP_CAPTURE_SCP_BIN}" -o BatchMode=yes \
        "${remote_script}" \
        "${DEPLOY_HOST}:${remote_commands}/capture-release-recovery-checkpoint-remote.sh"

    "${RIP_CAPTURE_SCP_BIN}" -o BatchMode=yes \
        "${archive_lib}" \
        "${DEPLOY_HOST}:${remote_lib}/rip-capture-archive.sh"

    "${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" -- \
        chmod 750 -- "${remote_commands}/capture-release-recovery-checkpoint-remote.sh" \
        "${remote_lib}/rip-capture-archive.sh"

    return 0
}

rip_capture_operator_remote_script_path() {
    if [[ -z "${RIP_CAPTURE_REMOTE_TOOL_STAGE}" ]]; then
        echo "rip-capture: remote tool stage is not initialized" >&2
        return 1
    fi

    printf '%s/tools/commands/capture-release-recovery-checkpoint-remote.sh' "${RIP_CAPTURE_REMOTE_TOOL_STAGE}"
}

rip_capture_operator_remote_invoke() {
    local remote_script_path
    remote_script_path="$(rip_capture_operator_remote_script_path)" || return 1

    "${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" -- \
        bash -- "${remote_script_path}" \
        "$1" "$2" "$3" "$4" "$5" "$6" "$7"
}
