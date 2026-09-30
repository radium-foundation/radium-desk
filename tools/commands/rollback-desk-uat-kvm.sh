#!/usr/bin/env bash
#
# Roll back isolated Desk UAT lane (does not touch production Desk).
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

UAT_REMOTE_PROJECT="/var/www/radium-desk-uat"
UAT_DB_NAME="radium_desk_uat"
UAT_DB_USER="radium_desk_uat"
UAT_VHOST="radium-desk-uat"

ssh_uat() {
    ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=30 "${SSH_USER}@${SSH_HOST}" "$@"
}

print_warning "Rolling back Desk UAT lane..."
ssh_uat "sudo bash -s" <<'EOROLL'
set -euo pipefail
HTTPD="/usr/local/lsws/conf/httpd_config.conf"
if grep -q "virtualHost radium-desk-uat" "$HTTPD"; then
  cp -a "$HTTPD" "${HTTPD}.pre-rollback-$(date -u +%Y%m%dT%H%M%SZ)"
  sed -i '/virtualHost radium-desk-uat/,/^}/d' "$HTTPD"
  sed -i '/map[[:space:]]\+radium-desk-uat/d' "$HTTPD"
  /usr/local/lsws/bin/lswsctrl reload
fi
rm -rf /usr/local/lsws/conf/vhosts/radium-desk-uat
mariadb -e "DROP DATABASE IF EXISTS \`radium_desk_uat\`;"
mariadb -e "DROP USER IF EXISTS 'radium_desk_uat'@'localhost';"
rm -rf /var/www/radium-desk-uat
EOROLL
print_success "Desk UAT lane removed"
