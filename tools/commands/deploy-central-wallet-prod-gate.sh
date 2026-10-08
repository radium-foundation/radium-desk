#!/usr/bin/env bash
# Surgical production overlay: Central Wallet release-gate / reliability tooling ONLY.
# Does NOT deploy unrelated branch changes. Does NOT modify .env checkout cohorts.
set -euo pipefail

SSH_HOST="${SSH_HOST:-187.127.129.16}"
SSH_USER="${SSH_USER:-ravi}"
SSH_PORT="${SSH_PORT:-22}"
PHP_BIN="/usr/local/lsws/lsphp84/bin/php"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_ROOT=""

DESK_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RDIN_ROOT="$(cd "$DESK_ROOT/../rdservice.in" && pwd)"
RBOX_ROOT="$(cd "$DESK_ROOT/../radiumbox.com" && pwd)"
RDNET_ROOT="$(cd "$DESK_ROOT/../rdservice.net" && pwd)"

RSYNC=(rsync -az -e "ssh -p ${SSH_PORT} -o BatchMode=yes")

remote() {
  ssh -p "$SSH_PORT" -o BatchMode=yes "${SSH_USER}@${SSH_HOST}" "$@"
}

backup_paths() {
  local prod="$1"
  local backup="${prod}/storage/app/backups/central-wallet-gate-prod-${STAMP}"
  shift
  remote "mkdir -p '${backup}' && for p in $*; do
    if [ -e '${prod}/'\$p ]; then
      mkdir -p '${backup}/'\"\$(dirname \"\$p\")\"
      cp -a '${prod}/'\"\$p\" '${backup}/'\"\$p\" 2>/dev/null || true
    fi
  done && echo 'backup=${backup}'"
}

deploy_desk() {
  local PROD="/var/www/radium-desk"
  echo "== Deploy radium-desk production CW gate =="
  backup_paths "$PROD" \
    app/CentralWallet/Reliability \
    app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php \
    app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php \
    app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php \
    app/Providers/CentralWalletServiceProvider.php \
    config/central_wallet.php \
    contracts/central-wallet

  (cd "$DESK_ROOT" && "${RSYNC[@]}" app/CentralWallet/Reliability/ "${SSH_USER}@${SSH_HOST}:${PROD}/app/CentralWallet/Reliability/")
  (cd "$DESK_ROOT" && "${RSYNC[@]}" \
    app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php \
    app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php \
    app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php \
    "${SSH_USER}@${SSH_HOST}:${PROD}/app/Console/Commands/")
  (cd "$DESK_ROOT" && "${RSYNC[@]}" app/Providers/CentralWalletServiceProvider.php "${SSH_USER}@${SSH_HOST}:${PROD}/app/Providers/")
  (cd "$DESK_ROOT" && "${RSYNC[@]}" config/central_wallet.php "${SSH_USER}@${SSH_HOST}:${PROD}/config/")
  remote "mkdir -p ${PROD}/contracts"
  (cd "$DESK_ROOT" && "${RSYNC[@]}" contracts/central-wallet/ "${SSH_USER}@${SSH_HOST}:${PROD}/contracts/central-wallet/")

  remote "$PHP_BIN ${PROD}/artisan optimize:clear"
}

deploy_consumer() {
  local ROOT="$1" PROD="$2" PROJECT_KEY="$3" PROMPT_ID="$4"
  echo "== Deploy ${PROJECT_KEY} production CW gate =="
  backup_paths "$PROD" \
    app/CentralWallet/Reliability \
    app/CentralWallet/Support/CentralWalletSpokeFailureSemantics.php \
    app/CentralWallet/Support/CentralWalletSpokeTrustPolicy.php \
    app/CentralWallet/Support/CentralWalletSpokeStateModel.php \
    app/CentralWallet/Support/TrustedVerificationMethod.php \
    app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php \
    app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php \
    app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php \
    app/Providers/CentralWalletServiceProvider.php \
    config/central_wallet.php \
    contracts/central-wallet

  (cd "$ROOT" && "${RSYNC[@]}" app/CentralWallet/Reliability/ "${SSH_USER}@${SSH_HOST}:${PROD}/app/CentralWallet/Reliability/")
  (cd "$ROOT" && "${RSYNC[@]}" \
    app/CentralWallet/Support/CentralWalletSpokeFailureSemantics.php \
    app/CentralWallet/Support/CentralWalletSpokeTrustPolicy.php \
    app/CentralWallet/Support/CentralWalletSpokeStateModel.php \
    app/CentralWallet/Support/TrustedVerificationMethod.php \
    "${SSH_USER}@${SSH_HOST}:${PROD}/app/CentralWallet/Support/")
  (cd "$ROOT" && "${RSYNC[@]}" \
    app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php \
    app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php \
    app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php \
    "${SSH_USER}@${SSH_HOST}:${PROD}/app/Console/Commands/")
  (cd "$ROOT" && "${RSYNC[@]}" app/Providers/CentralWalletServiceProvider.php "${SSH_USER}@${SSH_HOST}:${PROD}/app/Providers/")
  (cd "$ROOT" && "${RSYNC[@]}" config/central_wallet.php "${SSH_USER}@${SSH_HOST}:${PROD}/config/")
  remote "mkdir -p ${PROD}/contracts"
  (cd "$ROOT" && "${RSYNC[@]}" contracts/central-wallet/ "${SSH_USER}@${SSH_HOST}:${PROD}/contracts/central-wallet/")

  remote "$PHP_BIN ${PROD}/artisan optimize:clear"
}

case "${1:-all}" in
  desk) deploy_desk ;;
  rdin) deploy_consumer "$RDIN_ROOT" "/var/www/rdservice.in" "rdservice.in" "P-04-10-25" ;;
  rbox) deploy_consumer "$RBOX_ROOT" "/var/www/radiumbox.com" "radiumbox.com" "P-02-10-115" ;;
  rdnet) deploy_consumer "$RDNET_ROOT" "/var/www/rdservice.net" "rdservice.net" "RDServiceNet-P-04-10-22" ;;
  all)
    deploy_desk
    deploy_consumer "$RDIN_ROOT" "/var/www/rdservice.in" "rdservice.in" "P-04-10-25"
    deploy_consumer "$RBOX_ROOT" "/var/www/radiumbox.com" "radiumbox.com" "P-02-10-115"
    deploy_consumer "$RDNET_ROOT" "/var/www/rdservice.net" "rdservice.net" "RDServiceNet-P-04-10-22"
    ;;
  *) echo "Usage: $0 [desk|rdin|rbox|rdnet|all]" >&2; exit 1 ;;
esac

echo "deploy_complete stamp=${STAMP}"
