#!/usr/bin/env bash
# UAT-only overlay: Desk wallet-visibility + release gate tooling.
# Does NOT touch /var/www/radium-desk (production).
set -euo pipefail

SSH_HOST="${SSH_HOST:-187.127.129.16}"
SSH_USER="${SSH_USER:-ravi}"
SSH_PORT="${SSH_PORT:-22}"
DESK_UAT="/var/www/radium-desk-uat"
RDIN_UAT="/var/www/rdservice-in-uat"
PHP_BIN="/usr/local/lsws/lsphp84/bin/php"

DESK_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RDIN_ROOT="$(cd "$DESK_ROOT/../rdservice.in" && pwd)"

RSYNC=(rsync -az -e "ssh -p ${SSH_PORT} -o BatchMode=yes")

desk_rsync() {
  (cd "$DESK_ROOT" && "${RSYNC[@]}" "$@" "${SSH_USER}@${SSH_HOST}:${DESK_UAT}/")
}

rdin_rsync() {
  (cd "$RDIN_ROOT" && "${RSYNC[@]}" "$@" "${SSH_USER}@${SSH_HOST}:${RDIN_UAT}/")
}

echo "== Deploy Desk UAT wallet-visibility + release gate =="
desk_rsync app/CentralWallet/Application/WalletVisibilityService.php
desk_rsync app/CentralWallet/Infrastructure/Http/Controllers/WalletVisibilityController.php
desk_rsync app/CentralWallet/Reliability/
desk_rsync app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php
desk_rsync app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php
desk_rsync app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php
desk_rsync routes/central_wallet.php
desk_rsync app/Providers/CentralWalletServiceProvider.php
desk_rsync config/central_wallet.php
desk_rsync contracts/central-wallet/

echo "== Deploy rdin UAT release gate + contract =="
rdin_rsync app/CentralWallet/Reliability/
rdin_rsync app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php
rdin_rsync app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php
rdin_rsync app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php
rdin_rsync app/Providers/CentralWalletServiceProvider.php
rdin_rsync config/central_wallet.php
rdin_rsync contracts/central-wallet/

echo "== Configure UAT env (non-production) =="
ssh -p "$SSH_PORT" -o BatchMode=yes "${SSH_USER}@${SSH_HOST}" bash -s <<'EOF'
set -euo pipefail
PHP=/usr/local/lsws/lsphp84/bin/php
DESK_UAT="/var/www/radium-desk-uat"
RDIN_UAT="/var/www/rdservice-in-uat"

set_env() {
  local file="$1" key="$2" val="$3"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

set_env "$DESK_UAT/.env" "CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED" "true"

DESK_TOKEN=$(grep '^CENTRAL_WALLET_INTEGRATION_TOKEN=' "$DESK_UAT/.env" | cut -d= -f2- | tr -d '"')
PROBE_EMAIL=$($PHP "$RDIN_UAT/artisan" tinker --execute='echo optional(App\Models\User::find(990001))->email ?? "";' 2>/dev/null | tail -1 | tr -d '\r')

if [[ -z "$DESK_TOKEN" || -z "$PROBE_EMAIL" ]]; then
  echo "BLOCKER: missing UAT token or probe user email" >&2
  exit 1
fi

for envfile in "$DESK_UAT/.env" "$RDIN_UAT/.env"; do
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_PROBE_BASE_URL" "http://desk-uat.radiumbox.com"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_PROBE_TOKEN" "$DESK_TOKEN"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_PROBE_SITE_CODE" "rdservice.in"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_PROBE_LOCAL_USER_ID" "990001"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_PROBE_EMAIL" "$PROBE_EMAIL"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_RECONCILIATION_ENABLED" "true"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_DESK_ENV_PATH" "$DESK_UAT/.env"
  set_env "$envfile" "CENTRAL_WALLET_RELEASE_GATE_SPOKE_ENV_PATH" "$RDIN_UAT/.env"
done

$PHP "$DESK_UAT/artisan" optimize:clear
$PHP "$RDIN_UAT/artisan" optimize:clear
echo "uat_env_configured"
EOF

echo "== Write runtime manifests =="
ssh -p "$SSH_PORT" -o BatchMode=yes "${SSH_USER}@${SSH_HOST}" bash -s <<'EOF'
set -euo pipefail
PHP=/usr/local/lsws/lsphp84/bin/php
DESK_UAT="/var/www/radium-desk-uat"
RDIN_UAT="/var/www/rdservice-in-uat"
cd "$DESK_UAT"
$PHP artisan central-wallet:write-runtime-manifest --deployment-type=overlay --environment=uat --git-sha=uat-gate-overlay --overlay-prompt-id=RadiumDesk-P-04-10-122
cd "$RDIN_UAT"
$PHP artisan central-wallet:write-runtime-manifest --deployment-type=overlay --environment=uat --project=rdservice.in --git-sha=uat-gate-overlay --overlay-prompt-id=P-04-10-23
EOF

echo "deploy_complete"
