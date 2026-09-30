#!/usr/bin/env bash
#
# Provision an isolated Radium Desk UAT lane on KVM8 for Central Wallet reservation testing.
# Does NOT modify production /var/www/radium-desk or radium_desk database.
#
# Usage:
#   ./tools/commands/provision-desk-uat-kvm.sh [--tag v4.0.168] [--yes]
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DEPLOY_TAG="v4.0.168"
SKIP_CONFIRM=0
WORKTREE_DIR=""

UAT_REMOTE_PROJECT="/var/www/radium-desk-uat"
UAT_DB_NAME="radium_desk_uat"
UAT_DB_USER="radium_desk_uat"
UAT_VHOST="radium-desk-uat"
UAT_HOSTNAME="desk-uat.radiumbox.com"
PHP_BIN="/usr/local/lsws/lsphp84/bin/php"
COMPOSER_BIN="/usr/local/bin/composer"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --tag) DEPLOY_TAG="$2"; shift 2 ;;
        --yes) SKIP_CONFIRM=1; shift ;;
        -h|--help)
            echo "Usage: $(basename "$0") [--tag v4.0.168] [--yes]"
            exit 0
            ;;
        *) print_error "Unknown option: $1"; exit 1 ;;
    esac
done

confirm_provision() {
    if [[ "$SKIP_CONFIRM" -eq 1 ]]; then
        return 0
    fi
    print_warning "This will provision isolated Desk UAT at ${UAT_REMOTE_PROJECT} (DB: ${UAT_DB_NAME})."
    print_warning "Production ${REMOTE_PROJECT} / radium_desk will NOT be modified."
    print_warning "Type 'provision-uat' to continue:"
    read -r answer
    [[ "$answer" == "provision-uat" ]]
}

prepare_worktree() {
    if git -C "$PROJECT_ROOT" rev-parse "$DEPLOY_TAG" >/dev/null 2>&1; then
        WORKTREE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/radium-desk-uat-src.XXXXXX")"
        git -C "$PROJECT_ROOT" worktree add --detach "$WORKTREE_DIR" "$DEPLOY_TAG" >/dev/null
        print_success "Prepared source worktree at ${DEPLOY_TAG}"
    else
        print_error "Tag ${DEPLOY_TAG} not found"
        exit 1
    fi
}

cleanup_worktree() {
    if [[ -n "$WORKTREE_DIR" && -d "$WORKTREE_DIR" ]]; then
        git -C "$PROJECT_ROOT" worktree remove --force "$WORKTREE_DIR" >/dev/null 2>&1 || rm -rf "$WORKTREE_DIR"
    fi
}

uat_ssh() {
    ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=30 "${SSH_USER}@${SSH_HOST}" "$@"
}

uat_sudo() {
    uat_ssh "sudo $*"
}

provision_database() {
    print_warning "Creating isolated UAT database and user (if absent)..."
    uat_sudo bash -s <<'EOSQL'
set -euo pipefail
DB_NAME="radium_desk_uat"
DB_USER="radium_desk_uat"
SECRETS_FILE="/var/www/radium-desk-uat/.uat-db-credentials"
if [[ -f "$SECRETS_FILE" ]]; then
  DB_PASS=$(grep '^DB_PASSWORD=' "$SECRETS_FILE" | cut -d= -f2-)
else
  DB_PASS=$(openssl rand -hex 24)
  install -d -m 700 -o ravi -g ravi /var/www/radium-desk-uat
  printf 'DB_PASSWORD=%s\n' "$DB_PASS" > "$SECRETS_FILE"
  chown ravi:ravi "$SECRETS_FILE"
  chmod 600 "$SECRETS_FILE"
fi
chown ravi:ravi "$SECRETS_FILE" 2>/dev/null || true
mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
mariadb -e "FLUSH PRIVILEGES;"
EOSQL
    print_success "UAT database ready (${UAT_DB_NAME})"
}

write_uat_env() {
    print_warning "Writing UAT .env (secrets remain on server only)..."
    uat_ssh bash -s <<'EOENV'
set -euo pipefail
UAT_ROOT="/var/www/radium-desk-uat"
SECRETS_FILE="${UAT_ROOT}/.uat-db-credentials"
TOKEN_FILE="${UAT_ROOT}/.uat-integration-token"
install -d -m 755 -o ravi -g ravi "$UAT_ROOT"
DB_PASS=$(grep '^DB_PASSWORD=' "$SECRETS_FILE" | cut -d= -f2-)
if [[ -f "$TOKEN_FILE" ]]; then
  UAT_TOKEN=$(cat "$TOKEN_FILE")
else
  UAT_TOKEN=$(openssl rand -hex 32)
  printf '%s' "$UAT_TOKEN" > "$TOKEN_FILE"
  chmod 600 "$TOKEN_FILE"
fi
if [[ -f "${UAT_ROOT}/.env" ]]; then
  exit 0
fi
APP_KEY=$(openssl rand -base64 32)
cat > "${UAT_ROOT}/.env" <<EOF
APP_NAME="Radium Desk UAT"
APP_ENV=staging
APP_KEY=base64:${APP_KEY}
APP_DEBUG=true
APP_URL=http://desk-uat.radiumbox.com

APP_TIMEZONE=Asia/Kolkata
APP_SCHEDULE_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=radium_desk_uat
DB_USERNAME=radium_desk_uat
DB_PASSWORD=${DB_PASS}

BROADCAST_CONNECTION=log
CACHE_STORE=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

CENTRAL_WALLET_ENABLED=true
CENTRAL_WALLET_API_ENABLED=true
CENTRAL_WALLET_RESERVATIONS_ENABLED=true
CENTRAL_WALLET_INTEGRATION_TOKEN=${UAT_TOKEN}
CENTRAL_WALLET_DIRECT_LEDGER_DEBIT_ENABLED=true
CENTRAL_WALLET_RECONCILIATION_ENABLED=false
CENTRAL_WALLET_IDEMPOTENCY_RETENTION_DAYS=7
CENTRAL_WALLET_RESERVATION_TTL_SECONDS=900

DESK_INGEST_ENABLED=false
SHIPROCKET_ENABLED=false
EOF
chmod 600 "${UAT_ROOT}/.env"
EOENV
    print_success "UAT .env configured"
}

rsync_uat_source() {
    print_warning "Rsyncing Desk ${DEPLOY_TAG} source to ${UAT_REMOTE_PROJECT}..."
    rsync -avz \
        -e "ssh -p ${SSH_PORT} -o BatchMode=yes -o ConnectTimeout=30" \
        --delete \
        --exclude '.git/' \
        --exclude '.env' \
        --exclude '.uat-db-credentials' \
        --exclude '.uat-integration-token' \
        --exclude 'node_modules/' \
        --exclude 'vendor/' \
        --exclude 'storage/logs/' \
        --exclude 'storage/framework/' \
        --exclude 'bootstrap/cache/' \
        --exclude 'tests/' \
        --include 'storage/' \
        --include 'storage/app/' \
        --include 'storage/app/private/' \
        --exclude 'storage/app/private/*' \
        --exclude 'storage/app/*' \
        --exclude 'storage/*' \
        --exclude 'public/build/' \
        "${WORKTREE_DIR}/" \
        "${SSH_USER}@${SSH_HOST}:${UAT_REMOTE_PROJECT}/"
    print_success "Source rsync completed"
}

sync_uat_assets() {
    print_warning "Copying Vite build assets from production Desk (static only)..."
    uat_ssh "mkdir -p '${UAT_REMOTE_PROJECT}/public/build' && rsync -a '${REMOTE_PROJECT}/public/build/' '${UAT_REMOTE_PROJECT}/public/build/'"
    print_success "Frontend build assets copied"
}

run_uat_bootstrap() {
    print_warning "Installing dependencies and running UAT migrations..."
    uat_ssh bash -s <<'EOBOOT'
set -euo pipefail
UAT_ROOT="/var/www/radium-desk-uat"
PHP="/usr/local/lsws/lsphp84/bin/php"
COMPOSER="/usr/local/bin/composer"
export PATH="/usr/local/lsws/lsphp84/bin:${PATH}"
cd "$UAT_ROOT"
chmod 755 "$UAT_ROOT"
find "$UAT_ROOT" -type d -exec chmod 755 {} +
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
$PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate --force
$PHP artisan db:seed --class=RolePermissionSeeder --force
$PHP artisan permission:cache-reset
$PHP artisan optimize:clear
$PHP artisan route:cache
# UAT-only hotfix: v4.0.168 closure must capture $entryType for external ledger credits.
sed -i 's/function () use ($cwid, $validated, $correlationId, $sourceSystem): array {/function () use ($cwid, $validated, $correlationId, $sourceSystem, $entryType): array {/' \
  "$UAT_ROOT/app/CentralWallet/Infrastructure/Http/Controllers/WalletController.php"
EOBOOT
    print_success "UAT application bootstrapped"
}

configure_ols_vhost() {
    print_warning "Configuring OpenLiteSpeed vhost for ${UAT_HOSTNAME} (HTTP internal access)..."
    uat_sudo bash -s <<'EOOLS'
set -euo pipefail
UAT_VHOST="radium-desk-uat"
UAT_HOSTNAME="desk-uat.radiumbox.com"
UAT_ROOT="/var/www/radium-desk-uat"
HTTPD="/usr/local/lsws/conf/httpd_config.conf"
BACKUP="/usr/local/lsws/conf/httpd_config.conf.pre-desk-uat-$(date -u +%Y%m%dT%H%M%SZ)"
if [[ ! -f "$BACKUP" ]]; then
  cp -a "$HTTPD" "$BACKUP"
fi
install -d -m 755 "/usr/local/lsws/conf/vhosts/${UAT_VHOST}"
if [[ ! -f "/usr/local/lsws/conf/vhosts/${UAT_VHOST}/vhconf.conf" ]]; then
  cp "/usr/local/lsws/conf/vhosts/radium-desk/vhconf.conf" "/usr/local/lsws/conf/vhosts/${UAT_VHOST}/vhconf.conf"
fi
if ! grep -q "virtualHost ${UAT_VHOST}" "$HTTPD"; then
  awk -v block="
virtualHost ${UAT_VHOST} {
    vhRoot                  ${UAT_ROOT}/
    allowSymbolLink         1
    enableScript            1
    restrained              1
    setUIDMode              2
    chrootMode              0
    configFile              conf/vhosts/${UAT_VHOST}/vhconf.conf
}

" '
    /^virtualHost rdserviceonline \{/ && !done {
      print block
      done=1
    }
    { print }
  ' "$HTTPD" > "${HTTPD}.tmp" && mv "${HTTPD}.tmp" "$HTTPD"
fi
if ! grep -q "map[[:space:]]\+${UAT_VHOST}" "$HTTPD"; then
  sed -i "/map[[:space:]]\+radium-desk desk.radiumbox.com/a\\    map                     ${UAT_VHOST} ${UAT_HOSTNAME}" "$HTTPD"
fi
/usr/local/lsws/bin/lswsctrl reload
EOOLS
    print_success "OLS vhost configured (${UAT_HOSTNAME} via Host header)"
}

main() {
    cd "$PROJECT_ROOT"
    trap cleanup_worktree EXIT

    confirm_provision || { print_error "Cancelled."; exit 1; }

    prepare_worktree
    provision_database
    write_uat_env
    rsync_uat_source
    sync_uat_assets
    run_uat_bootstrap
    configure_ols_vhost

    print_success "Desk UAT lane provisioned at ${UAT_REMOTE_PROJECT}"
    print_warning "Run ./tools/commands/validate-desk-uat-kvm.sh to verify reservation flows."
    print_warning "UAT integration token is stored only in ${UAT_REMOTE_PROJECT}/.uat-integration-token (not printed)."
}

main "$@"
