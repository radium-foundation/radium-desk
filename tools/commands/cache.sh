#!/usr/bin/env bash
#
# Clear and rebuild Laravel caches on the remote server.
#
# Runs optimize:clear followed by optimize to ensure
# config, routes, views, and events are freshly cached.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

print_warning "Clearing remote Laravel caches..."

php_exec optimize:clear
print_success "Caches cleared"

print_warning "Rebuilding optimized caches..."
php_exec optimize
print_success "Caches rebuilt"

print_success "Remote cache refresh completed"
