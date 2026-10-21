#!/usr/bin/env bash
# Capture a private Radium Desk recovery checkpoint on KVM production (read-only preflight + capture).
#
# Checkpoint ID (Regression Integration Policy):
#   RIP-DDMMYY-HHMM-radium-desk-<short-change>-<commit7>
#
# Artifacts (production host only):
#   storage/app/private/release-checkpoints/<checkpoint_id>/
#
# Usage:
#   ./tools/commands/capture-release-recovery-checkpoint.sh \
#     --change pre-v4124 \
#     --git-sha 7441de0 \
#     [--status deployed] \
#     [--dry-run]
#
# Does NOT deploy, migrate, or mutate customer data.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REMOTE_BODY="${SCRIPT_DIR}/capture-release-recovery-checkpoint-remote.sh"
ARCHIVE_LIB="${SCRIPT_DIR}/../lib/rip-capture-archive.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=tools/lib/rip-capture-operator-remote.sh
source "${SCRIPT_DIR}/../lib/rip-capture-operator-remote.sh"

RIP_CAPTURE_SSH_BIN="${RIP_CAPTURE_SSH_BIN:-ssh}"
RIP_CAPTURE_SCP_BIN="${RIP_CAPTURE_SCP_BIN:-scp}"

DEPLOY_HOST="${DESK_DEPLOY_HOST:-ravi@187.127.129.16}"
LARAVEL_ROOT="${DESK_LARAVEL_ROOT:-/var/www/radium-desk}"
PHP_BIN="${DESK_PHP_BIN:-/usr/local/lsws/lsphp84/bin/php}"
PROJECT_KEY="radium-desk"
CHANGE=""
GIT_SHA=""
STATUS="deployed"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --change) CHANGE="${2:?}"; shift 2 ;;
    --git-sha) GIT_SHA="${2:?}"; shift 2 ;;
    --status) STATUS="${2:?}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help)
      sed -n '1,22p' "$0"
      exit 0
      ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$CHANGE" && -n "$GIT_SHA" ]] || {
  echo "required: --change <short-change> --git-sha <short-or-full-sha>" >&2
  exit 2
}

SHORT_SHA="${GIT_SHA:0:7}"
FULL_SHA="$(git -C "${REPO_ROOT}" rev-parse "${GIT_SHA}^{commit}" 2>/dev/null || true)"
if [[ -z "$FULL_SHA" ]]; then
  FULL_SHA="$(git -C "${REPO_ROOT}" rev-parse "${SHORT_SHA}^{commit}" 2>/dev/null || true)"
fi
if [[ -z "$FULL_SHA" ]]; then
  FULL_SHA="${GIT_SHA}"
fi

RIP_STAMP="$(TZ=Asia/Kolkata date +%d%m%y-%H%M)"
CHECKPOINT_ID="RIP-${RIP_STAMP}-${PROJECT_KEY}-${CHANGE}-${SHORT_SHA}"

if [[ ! "${CHECKPOINT_ID}" =~ ^RIP-[0-9]{6}-[0-9]{4}-radium-desk-[a-z0-9][a-z0-9-]*[a-z0-9]-[0-9a-f]{7}$ ]] \
  && [[ ! "${CHECKPOINT_ID}" =~ ^RIP-[0-9]{6}-[0-9]{4}-radium-desk-[a-z0-9]-[0-9a-f]{7}$ ]]; then
  echo "invalid checkpoint_id (RIP policy): ${CHECKPOINT_ID}" >&2
  exit 2
fi

if [[ ! "${CHANGE}" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]]; then
  echo "invalid change slug for RIP policy" >&2
  exit 2
fi

echo "checkpoint_id=${CHECKPOINT_ID}"
echo "manifest_checkpoint_id=${CHECKPOINT_ID}"
echo "ledger_checkpoint_id=${CHECKPOINT_ID}"
echo "private_checkpoint_path=storage/app/private/release-checkpoints/${CHECKPOINT_ID}"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry_run=true"
  exit 0
fi

remote() {
  "${RIP_CAPTURE_SSH_BIN}" -o BatchMode=yes "${DEPLOY_HOST}" "$@"
}

echo "==> Pre-flight verify (read-only)"
remote -- bash -s -- "${LARAVEL_ROOT}" "${PHP_BIN}" <<'VERIFY'
set -euo pipefail
LARAVEL_ROOT="$1"
PHP_BIN="$2"
test -d "${LARAVEL_ROOT}"
test -f "${LARAVEL_ROOT}/artisan"
test -d "${LARAVEL_ROOT}/storage/app/private"
test -f "${LARAVEL_ROOT}/storage/app/private/release.json" || echo "warn: release.json missing"
"${PHP_BIN}" "${LARAVEL_ROOT}/artisan" migrate:status --no-ansi 2>/dev/null | grep -E 'Pending|Ran' | tail -3 || true
VERIFY

[[ -f "${REMOTE_BODY}" ]] || { echo "missing remote body: ${REMOTE_BODY}" >&2; exit 2; }
[[ -f "${ARCHIVE_LIB}" ]] || { echo "missing archive helper: ${ARCHIVE_LIB}" >&2; exit 2; }

if remote -- test -e "${LARAVEL_ROOT}/storage/app/private/release-checkpoints/${CHECKPOINT_ID}"; then
  echo "checkpoint already exists — will not overwrite: ${CHECKPOINT_ID}" >&2
  exit 2
fi

trap rip_capture_operator_remote_cleanup EXIT INT TERM

echo "==> Stage RIP capture tooling on ${DEPLOY_HOST}"
if ! rip_capture_operator_remote_stage; then
  echo "rip-capture: failed to stage remote capture scripts" >&2
  exit 1
fi

echo "==> Capture checkpoint ${CHECKPOINT_ID} on ${DEPLOY_HOST}"
if ! rip_capture_operator_remote_invoke \
  "${CHECKPOINT_ID}" "${CHANGE}" "${SHORT_SHA}" "${FULL_SHA}" "${STATUS}" \
  "${LARAVEL_ROOT}" "${PHP_BIN}"; then
  echo "rip-capture: remote capture failed" >&2
  exit 1
fi

rip_capture_operator_remote_cleanup
trap - EXIT INT TERM

echo "==> Verify (operator machine path via SSH)"
remote -- env \
  "LARAVEL_ROOT=${LARAVEL_ROOT}" \
  "PHP_BIN=${PHP_BIN}" \
  "CHECKPOINT_ID=${CHECKPOINT_ID}" \
  bash -c 'cd "$LARAVEL_ROOT" && exec "$PHP_BIN" artisan rip:verify-checkpoint "storage/app/private/release-checkpoints/${CHECKPOINT_ID}"'

echo "==> Record checkpoint in docs/cursor-prompt-ledger.md (private path only)."
