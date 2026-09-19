#!/usr/bin/env bash
# Targeted KVM overlay for Desk storefront catalog price sync files.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
EXPECTED_COMMIT="${EXPECTED_COMMIT:-ccc8c75e}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="${REMOTE_PROJECT}/storage/app/backups/desk-catalog-price-sync-${STAMP}"

FILES=(
  app/Enums/CatalogPriceSyncStatus.php
  app/Jobs/SyncStorefrontCatalogPriceJob.php
  app/Models/CatalogPriceSyncLog.php
  app/Services/Inventory/CatalogPriceSyncService.php
  app/Services/RadiumBox/RadiumBoxCatalogPriceClient.php
  app/Services/RadiumBox/RadiumBoxCatalogPriceSyncException.php
  app/Http/Controllers/Inventory/ProductController.php
  config/hardware_fulfilment.php
  config/radiumbox.php
  database/migrations/2026_09_19_120000_create_catalog_price_sync_logs_table.php
  resources/views/inventory/products/edit.blade.php
  resources/views/inventory/products/partials/storefront-sync-status.blade.php
  routes/web.php
)

verify_local_commit() {
  local head short_expected
  head="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
  short_expected="$(git -C "$PROJECT_ROOT" rev-parse --short "$EXPECTED_COMMIT")"
  if [[ "$head" != "$EXPECTED_COMMIT" && "$head" != ${short_expected}* ]]; then
    print_error "HEAD ${head} != EXPECTED_COMMIT ${EXPECTED_COMMIT}"
    exit 1
  fi
}

backup_and_overlay() {
  local file tmp staging="/tmp/radium-desk-catalog-price-sync-${STAMP}"

  verify_local_commit
  ssh_exec "mkdir -p '${BACKUP_DIR}' '${staging}'"

  for file in "${FILES[@]}"; do
    ssh_exec "mkdir -p '${BACKUP_DIR}/$(dirname "${file}")' '${staging}/$(dirname "${file}")'"
    ssh_exec "if [[ -f '${REMOTE_PROJECT}/${file}' ]]; then cp '${REMOTE_PROJECT}/${file}' '${BACKUP_DIR}/${file}'; fi"
    tmp="$(mktemp)"
    git -C "$PROJECT_ROOT" show "HEAD:${file}" > "${tmp}"
    scp -P "$SSH_PORT" -q "${tmp}" "${SSH_USER}@${SSH_HOST}:${staging}/${file}"
    rm -f "${tmp}"
    ssh_exec "mkdir -p '${REMOTE_PROJECT}/$(dirname "${file}")' && cp '${staging}/${file}' '${REMOTE_PROJECT}/${file}'"
  done

  ssh_exec "rm -rf '${staging}'"
}

deploy() {
  backup_and_overlay
  print_warning "Running Desk migration and cache rebuild..."
  php_exec migrate --force --path=database/migrations/2026_09_19_120000_create_catalog_price_sync_logs_table.php
  php_exec optimize:clear
  php_exec route:cache
  kvm_restart_supervisor_worker
  print_success "Desk catalog price sync overlay deployed. Backup: ${BACKUP_DIR}"
}

case "${1:-deploy}" in
  deploy) deploy ;;
  *)
    echo "Usage: $(basename "$0") deploy" >&2
    exit 1
    ;;
esac
