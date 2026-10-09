#!/usr/bin/env bash
# Read-only capture of a production Central Wallet overlay baseline for pre-deploy comparison.
# Does NOT modify production. Does NOT restart services.
#
# Usage:
#   ./tools/commands/capture-overlay-production-baseline.sh <project> <ssh_host> <remote_prod_path> [local_output_dir]
#
# Example (operator machine):
#   ./tools/commands/capture-overlay-production-baseline.sh radiumbox.com 187.127.129.16 /var/www/radiumbox.com ./storage/app/local-baselines/rbox-pre-deploy
#
# After capture, run pre-deploy gate with:
#   export CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT=/absolute/path/to/captured/baseline
#   export CENTRAL_WALLET_RELEASE_GATE_OVERLAY_TARGET_ROOT=/absolute/path/to/local/checkout
#   php artisan central-wallet:verify-release-gate --phase=pre --json
#
set -euo pipefail

PROJECT="${1:?project key e.g. radiumbox.com}"
SSH_HOST="${2:?host}"
REMOTE_PROD="${3:?remote production path}"
OUT="${4:-./storage/app/local-baselines/${PROJECT}-$(date -u +%Y%m%dT%H%M%SZ)}"
SSH_USER="${SSH_USER:-ravi}"
SSH_PORT="${SSH_PORT:-22}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp84/bin/php}"

MANIFEST_PATHS=(
  config/central_wallet.php
  app/Providers/CentralWalletServiceProvider.php
  storage/app/private/runtime-manifest.json
  contracts/central-wallet/v1/managed-file-inventory.json
  contracts/central-wallet/v1/production-dependency-contract.json
)

ssh_cmd() {
  ssh -p "$SSH_PORT" -o BatchMode=yes "${SSH_USER}@${SSH_HOST}" "$@"
}

mkdir -p "$OUT"
echo "capturing project=$PROJECT host=$SSH_HOST remote=$REMOTE_PROD -> $OUT"

{
  echo "project=$PROJECT"
  echo "captured_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "remote_prod=$REMOTE_PROD"
  echo "ssh=${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
} > "$OUT/BASELINE-META.txt"

for rel in "${MANIFEST_PATHS[@]}"; do
  if ssh_cmd "test -e '${REMOTE_PROD}/${rel}'"; then
    mkdir -p "$OUT/$(dirname "$rel")"
    rsync -az -e "ssh -p ${SSH_PORT} -o BatchMode=yes" \
      "${SSH_USER}@${SSH_HOST}:${REMOTE_PROD}/${rel}" "$OUT/$(dirname "$rel")/"
  fi
done

# Managed inventory files (hashes only metadata — full tree via inventory list)
if ssh_cmd "test -f '${REMOTE_PROD}/contracts/central-wallet/v1/managed-file-inventory.json'"; then
  ssh_cmd "cd '${REMOTE_PROD}' && ${PHP_BIN} artisan central-wallet:verify-overlay-integrity --json" \
    > "$OUT/overlay-integrity-at-capture.json" 2>/dev/null || true
fi

if ssh_cmd "test -f '${REMOTE_PROD}/storage/app/private/runtime-manifest.json'"; then
  ssh_cmd "cd '${REMOTE_PROD}' && ${PHP_BIN} -r 'echo json_encode(json_decode(file_get_contents(\"storage/app/private/runtime-manifest.json\"), true)[\"release_identity\"] ?? \"missing\");'" \
    > "$OUT/release-identity-at-capture.txt" 2>/dev/null || true
fi

echo "baseline_ready=$OUT"
echo "Set CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT to: $(cd "$OUT" && pwd)"
