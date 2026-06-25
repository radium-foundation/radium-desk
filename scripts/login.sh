#!/usr/bin/env bash

set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT_DIR/scripts/config.sh"

ssh -t -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
"cd '$REMOTE_PROJECT' && exec bash -l"
