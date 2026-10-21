#!/usr/bin/env bash
# Local validation for RIP checkpoint ID policy (no SSH / production).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CAPTURE="${ROOT}/tools/commands/capture-release-recovery-checkpoint.sh"

bash -n "${CAPTURE}"
bash -n "${ROOT}/tools/commands/capture-release-recovery-checkpoint-remote.sh"
bash -n "${ROOT}/tools/lib/rip-capture-archive.sh"

OUT="$("${CAPTURE}" --dry-run --change pre-deploy-gate --git-sha 7441de0d)"
echo "${OUT}"

echo "${OUT}" | grep -q '^checkpoint_id=RIP-'
echo "${OUT}" | grep -q '^manifest_checkpoint_id=RIP-'
echo "${OUT}" | grep -q 'radium-desk'
echo "${OUT}" | grep -q 'private_checkpoint_path=storage/app/private/release-checkpoints/RIP-'

ID="$(echo "${OUT}" | sed -n 's/^checkpoint_id=//p')"
DIR="$(echo "${OUT}" | sed -n 's|^private_checkpoint_path=storage/app/private/release-checkpoints/||p')"
[[ "${DIR}" == "${ID}" ]]

(cd "${ROOT}" && php -n artisan rip:compose-checkpoint-id --change=smoke-test --git-sha=7441de0d | grep -q 'RIP-.*-radium-desk-smoke-test-7441de0')

if "${CAPTURE}" --dry-run --change 'Bad Change' --git-sha 7441de0d 2>/dev/null; then
  echo "expected invalid change slug to fail" >&2
  exit 1
fi

echo "validate-rip-checkpoint-id: OK"
