#!/usr/bin/env bash
#
# Open an interactive SSH session on the remote server,
# starting in the Laravel project directory.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

ssh -t -p "$SSH_PORT" \
    -o ConnectTimeout=15 \
    -o StrictHostKeyChecking=accept-new \
    "$SSH_USER@$SSH_HOST" \
    "cd '$REMOTE_PROJECT' && exec bash -l"
