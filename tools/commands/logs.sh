#!/usr/bin/env bash
#
# Tail the remote Laravel log file.
#
# Usage:
#   desk logs          Follow laravel.log (default)
#   desk logs 200      Show last 200 lines without following

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

LOG_FILE="$REMOTE_PROJECT/storage/logs/laravel.log"
lines="${1:-}"

if [[ -n "$lines" && "$lines" =~ ^[0-9]+$ ]]; then
    ssh_exec "test -f '$LOG_FILE' && tail -n '$lines' '$LOG_FILE' || echo 'Log file not found: $LOG_FILE'"
else
    print_warning "Tailing ${LOG_FILE} (Ctrl+C to stop)..."
    ssh_exec "test -f '$LOG_FILE' && tail -f '$LOG_FILE' || echo 'Log file not found: $LOG_FILE'"
fi
