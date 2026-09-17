#!/usr/bin/env bash
#
# Fail closed when the P-07-09-302 Hardware Dashboard contract is absent from
# the local release tree about to be rsynced by deploy-kvm.sh.
#
# Usage:
#   ./tools/commands/verify-hardware-dashboard-contract.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MANIFEST="$PROJECT_ROOT/tools/contracts/hardware-dashboard-p302.manifest"

require_file() {
    local relative="$1"

    if [[ ! -f "$PROJECT_ROOT/$relative" ]]; then
        print_error "Hardware Dashboard contract missing required file: ${relative}"
        exit 1
    fi
}

require_marker() {
    local relative="$1"
    local pattern="$2"
    local label="$3"

    if ! grep -Fq "$pattern" "$PROJECT_ROOT/$relative"; then
        print_error "Hardware Dashboard contract marker missing (${label}): ${relative}"
        exit 1
    fi
}

if [[ ! -f "$MANIFEST" ]]; then
    print_error "Hardware Dashboard contract manifest not found: ${MANIFEST}"
    exit 1
fi

while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%%#*}"
    line="$(echo "$line" | xargs)"
    [[ -z "$line" ]] && continue
    require_file "$line"
done < "$MANIFEST"

require_marker \
    "app/Enums/HardwareWorkspaceFilter.php" \
    "case NeedsAction = 'needs_action';" \
    "Needs Action filter"
require_marker \
    "app/Enums/HardwareWorkspaceFilter.php" \
    "case ReadyForPickup = 'ready_for_pickup';" \
    "Ready for Pickup filter"
require_marker \
    "app/Enums/HardwareWorkspaceFilter.php" \
    "case OutForPickup = 'out_for_pickup';" \
    "Out for Pickup filter"
require_marker \
    "app/Services/HardwareFulfilment/HardwareDashboardWorkspace.php" \
    "return HardwareWorkspaceFilter::NeedsAction;" \
    "default Needs Action filter"
require_marker \
    "app/Providers/AppServiceProvider.php" \
    "HardwareNeedsActionSqlQuery::class" \
    "Needs Action SQL query binding"
require_marker \
    "routes/web.php" \
    "dashboard.live.hardware" \
    "live hardware endpoint route"
require_marker \
    "app/Http/Controllers/DashboardLiveController.php" \
    "public function hardware(Request" \
    "live hardware controller action"
require_marker \
    "resources/views/dashboard/partials/hardware-workspace-row.blade.php" \
    "compactTimelineDisplay()" \
    "received/last-activity datetime rendering"
require_marker \
    "app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php" \
    "function compactTimelineDisplay()" \
    "compact order/last-activity timeline"
require_marker \
    "resources/views/dashboard/partials/hardware-workspace-nav.blade.php" \
    "needsActionFilters()" \
    "hardware workspace navigation filters"
require_marker \
    "resources/views/dashboard/partials/recent-service-cases.blade.php" \
    "QUEUE_HARDWARE" \
    "hardware workspace navigation routing"

print_success "Hardware Dashboard P-302 contract verified"
