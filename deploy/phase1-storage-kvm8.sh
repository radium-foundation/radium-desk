#!/usr/bin/env bash
#
# Deploy Phase 1 storage lifecycle scripts to KVM8 /var/www/radium-desk
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${KVM8_HOST:-deskvps}"
REMOTE_ROOT="${KVM8_RADIUM_DESK_ROOT:-/var/www/radium-desk}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

log() { echo "phase1-storage-kvm8.sh: $*" >&2; }

FILES=(
    bin/backup-prune-local.sh
    bin/storage-metrics-collect.sh
    bin/storage-metrics-alert.sh
)

for rel in "${FILES[@]}"; do
    src="${ROOT}/${rel}"
    [[ -f "$src" ]] || { log "missing ${src}"; exit 1; }
    dest="${REMOTE_ROOT}/${rel}"
    log "deploy ${rel} → ${dest}"
    scp -q "$src" "${REMOTE}:${dest}.pre-phase1-${STAMP}"
    ssh "$REMOTE" "install -m 755 ${dest}.pre-phase1-${STAMP} ${dest}"
done

logrotate_src="${ROOT}/deploy/logrotate-radium-desk-laravel.conf"
scp -q "$logrotate_src" "${REMOTE}:/tmp/logrotate-radium-desk-laravel.${STAMP}"
ssh "$REMOTE" "sudo cp /tmp/logrotate-radium-desk-laravel.${STAMP} /etc/logrotate.d/radium-desk-laravel && sudo chmod 644 /etc/logrotate.d/radium-desk-laravel"

ssh "$REMOTE" "sudo mkdir -p /var/backups/storage-metrics/daily && sudo chown -R ravi:ravi /var/backups/storage-metrics && sudo chmod 700 /var/backups/storage-metrics"

log "deploy complete (${STAMP})"
