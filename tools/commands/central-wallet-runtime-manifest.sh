#!/usr/bin/env bash
set -euo pipefail

# Write Central Wallet runtime manifest after git deploy or named-file overlay.
# Usage:
#   ./tools/commands/central-wallet-runtime-manifest.sh git
#   ./tools/commands/central-wallet-runtime-manifest.sh overlay RadiumDesk-P-04-10-117 ae25e178 storage/app/private/overlays/example

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

DEPLOYMENT_TYPE="${1:-git}"
PROMPT_ID="${2:-}"
SOURCE_COMMIT="${3:-}"
ROLLBACK_REF="${4:-}"

ARGS=(--deployment-type="$DEPLOYMENT_TYPE" --project=radium-desk --environment="${APP_ENV:-production}")

if [[ -n "$PROMPT_ID" ]]; then
  ARGS+=(--overlay-prompt-id="$PROMPT_ID")
fi

if [[ -n "$SOURCE_COMMIT" ]]; then
  ARGS+=(--source-commit="$SOURCE_COMMIT")
fi

if [[ -n "$ROLLBACK_REF" ]]; then
  ARGS+=(--rollback-reference="$ROLLBACK_REF")
fi

if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  ARGS+=(--git-sha="$(git -C "$ROOT" rev-parse HEAD)")
  ARGS+=(--git-branch="$(git -C "$ROOT" branch --show-current)")
fi

(cd "$ROOT" && "$PHP_BIN" artisan central-wallet:write-runtime-manifest "${ARGS[@]}")

echo "Central Wallet runtime manifest updated."
