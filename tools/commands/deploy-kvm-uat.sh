#!/usr/bin/env bash
#
# Deploy to kvm UAT only (/var/www/radium-desk-uat). Refuses production paths.
#
# Usage: ./tools/commands/deploy-kvm-uat.sh [--dry-run] [--yes]
# Or:    desk deploy-kvm-uat [--dry-run] [--yes]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DESK_UAT_REMOTE="/var/www/radium-desk-uat"
PRODUCTION_REMOTE="/var/www/radium-desk"

if [[ "${DEPLOY_KVM_REMOTE_PROJECT:-}" == "${PRODUCTION_REMOTE}" ]]; then
    echo "Refusing deploy: DEPLOY_KVM_REMOTE_PROJECT points at production." >&2
    exit 2
fi

export REMOTE_PROJECT="${DEPLOY_KVM_REMOTE_PROJECT:-$DESK_UAT_REMOTE}"
export DEPLOY_KVM_REMOTE_PROJECT="$REMOTE_PROJECT"

case "$REMOTE_PROJECT" in
    "$DESK_UAT_REMOTE"|*radium-desk-uat*)
        ;;
    *)
        echo "Refusing deploy: REMOTE_PROJECT must be the UAT tree (got: ${REMOTE_PROJECT})." >&2
        exit 2
        ;;
esac

echo "UAT deploy target locked: ${REMOTE_PROJECT} (production ${PRODUCTION_REMOTE} blocked)."

# UAT deploy: explicit no DB mutations; do not restart production supervisor worker.
export DEPLOY_RUN_MIGRATIONS=0
export DEPLOY_RUN_ROLE_PERMISSION_SEED=0
export DEPLOY_KVM_SKIP_SUPERVISOR_RESTART=1
export DEPLOY_KVM_UAT_SKIP_RELEASE_TAG=1

exec "$SCRIPT_DIR/deploy-kvm.sh" "$@"