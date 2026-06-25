#!/usr/bin/env bash
#
# Remote server and deployment configuration for desk toolkit.
# Edit these values to match your production environment.

# SSH connection
SSH_HOST="193.203.185.119"
SSH_PORT="65002"
SSH_USER="u215544208"

# Remote paths
REMOTE_PROJECT="/home/u215544208/laravel/radium-desk"
REMOTE_PUBLIC="/home/u215544208/domains/desk.radiumbox.com/public_html"

# Runtime binaries on the remote server
PHP_BIN="/opt/alt/php84/usr/bin/php"
COMPOSER_BIN="/usr/local/bin/composer"

# Git branch required for deployment
DEFAULT_BRANCH="main"
