#!/usr/bin/env bash
#
# Fail closed when the Service Ready Queue contract is absent from the local
# release tree about to be rsynced by deploy-kvm.sh.
#
# Usage:
#   ./tools/commands/verify-ready-queue-contract.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MANIFEST="$PROJECT_ROOT/tools/contracts/ready-queue-service.manifest"

require_file() {
    local relative="$1"

    if [[ ! -f "$PROJECT_ROOT/$relative" ]]; then
        print_error "Service Ready Queue contract missing required file: ${relative}"
        exit 1
    fi
}

require_marker() {
    local relative="$1"
    local pattern="$2"
    local label="$3"

    if ! grep -Fq "$pattern" "$PROJECT_ROOT/$relative"; then
        print_error "Service Ready Queue contract marker missing (${label}): ${relative}"
        exit 1
    fi
}

if [[ ! -f "$MANIFEST" ]]; then
    print_error "Service Ready Queue contract manifest not found: ${MANIFEST}"
    exit 1
fi

while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%%#*}"
    line="$(echo "$line" | xargs)"
    [[ -z "$line" ]] && continue
    require_file "$line"
done < "$MANIFEST"

require_marker \
    "database/seeders/RolePermissionSeeder.php" \
    "PERMISSION_READY_QUEUE_VIEW = 'dashboard.ready_queue.view'" \
    "ready queue permission constant"
require_marker \
    "app/Services/Operations/OperationsRoleService.php" \
    "function canViewReadyQueue(User" \
    "ready queue visibility resolver"
require_marker \
    "app/Services/DashboardPersonalizationService.php" \
    "canViewReadyQueue(\$user)" \
    "ready queue personalization branch"
require_marker \
    "config/operations.php" \
    "'label' => 'Ready Queue'" \
    "ready queue operations label"
require_marker \
    "tests/Feature/ReadyQueueCapabilityAccessTest.php" \
    "test_admin_with_hardware_team_lands_on_ready_queue_dashboard" \
    "hybrid admin ready queue regression test"

print_success "Service Ready Queue contract verified"
