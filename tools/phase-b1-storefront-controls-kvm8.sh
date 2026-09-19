#!/usr/bin/env bash
# Overlay Desk Phase B-1 storefront controls onto KVM8 production Laravel.
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-ravi@187.127.129.16}"
LARAVEL_ROOT="${LARAVEL_ROOT:-/var/www/radium-desk}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
EXPECTED_COMMIT="${EXPECTED_COMMIT:-}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="${LARAVEL_ROOT}/storage/app/backups/phase-b1-storefront-controls-${STAMP}"

FILES=(
  app/Http/Controllers/Inventory/ProductController.php
  app/Models/InventoryProduct.php
  app/Services/Inventory/CatalogPriceSyncService.php
  app/Services/RadiumBox/RadiumBoxCatalogPriceClient.php
  database/migrations/2026_09_19_130000_add_radiumbox_storefront_controls_to_inventory_products.php
  resources/views/inventory/products/partials/form.blade.php
  resources/views/inventory/products/partials/storefront-controls.blade.php
)

remote() {
  ssh -o BatchMode=yes "${DEPLOY_HOST}" "$@"
}

verify_local_commit() {
  if [[ -z "${EXPECTED_COMMIT}" ]]; then
    EXPECTED_COMMIT="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
  fi
  local head
  head="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
  if [[ "${head}" != "${EXPECTED_COMMIT}" && "${head}" != "${EXPECTED_COMMIT:0:7}"* && "${EXPECTED_COMMIT}" != "${head:0:7}"* ]]; then
    echo "ERROR: repo HEAD ${head} does not match EXPECTED_COMMIT ${EXPECTED_COMMIT}" >&2
    exit 1
  fi
}

backup_and_overlay() {
  local staging="/tmp/radium-desk-phase-b1-${STAMP}" file tmp
  verify_local_commit
  remote "mkdir -p '${BACKUP_DIR}' '${staging}'"
  for file in "${FILES[@]}"; do
    remote "mkdir -p '${BACKUP_DIR}/$(dirname "${file}")' '${staging}/$(dirname "${file}")'"
    remote "if [[ -f '${LARAVEL_ROOT}/${file}' ]]; then cp '${LARAVEL_ROOT}/${file}' '${BACKUP_DIR}/${file}'; fi"
    tmp="$(mktemp)"
    git -C "${REPO_ROOT}" show "HEAD:${file}" > "${tmp}"
    scp -q "${tmp}" "${DEPLOY_HOST}:${staging}/${file}"
    rm -f "${tmp}"
    remote "mkdir -p '${LARAVEL_ROOT}/$(dirname "${file}")' && cp '${staging}/${file}' '${LARAVEL_ROOT}/${file}'"
  done
  remote "rm -rf '${staging}'"
}

deploy() {
  backup_and_overlay
  remote bash -s -- "${LARAVEL_ROOT}" <<'REMOTE'
set -euo pipefail
ROOT="$1"
PHP="/usr/local/lsws/lsphp84/bin/php"
cd "${ROOT}"
"${PHP}" artisan migrate --force --path=database/migrations/2026_09_19_130000_add_radiumbox_storefront_controls_to_inventory_products.php
"${PHP}" artisan config:clear
"${PHP}" artisan route:clear
test -f resources/views/inventory/products/partials/storefront-controls.blade.php
REMOTE
  echo "Desk Phase B-1 overlay complete. Backup: ${BACKUP_DIR}"
  echo "Deployed commit: ${EXPECTED_COMMIT}"
}

case "${1:-}" in
  deploy) deploy ;;
  *) echo "Usage: $0 deploy"; exit 1 ;;
esac
