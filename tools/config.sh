#!/usr/bin/env bash
#
# Remote server and deployment configuration for desk toolkit.
# Edit these values to match your production environment.

# --- Current production: Hostinger KVM (desk ssh, logs, cache) ---
SSH_HOST="187.127.129.16"
SSH_PORT="22"
SSH_USER="ravi"
REMOTE_PROJECT="/var/www/radium-desk"

# Runtime binaries on the KVM
PHP_BIN="/usr/local/lsws/lsphp84/bin/php"
COMPOSER_BIN="/usr/local/bin/composer"

# --- KVM deployment configuration (Phase 2.1) ---
# Consumed by Phase 2.2+ deploy tooling. Legacy desk deploy/doctor/rollback
# still use LEGACY_REMOTE_PUBLIC and related paths below until migrated.
DEPLOY_MODE="kvm"
SUPERVISOR_PROGRAM="radium-desk-queue-worker"
REMOTE_PUBLIC="${REMOTE_PROJECT}/public"

# --- Legacy shared-hosting deploy (desk deploy / doctor / rollback only) ---
# NOT the current production app host. Hostinger Shared Cloud backup storage
# (u215544208, SSH port 65002) is configured separately on the KVM in
# /root/.radium-backup.env — do not change backup settings here.
LEGACY_REMOTE_PUBLIC="/home/u215544208/domains/desk.radiumbox.com/public_html"
INDEX_VENDOR_PATH="/home/u215544208/laravel/radium-desk/vendor/autoload.php"
INDEX_BOOTSTRAP_PATH="/home/u215544208/laravel/radium-desk/bootstrap/app.php"
SCHEDULE_RUN_WRAPPER="/home/u215544208/laravel/radium-desk/bin/schedule-run.sh"

# Git branch required for deployment
DEFAULT_BRANCH="main"
