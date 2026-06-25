#!/usr/bin/env bash

set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT_DIR/scripts/config.sh"

ssh_exec() {
    ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "$@"
}

php_exec() {
    ssh_exec "cd '$REMOTE_PROJECT' && $PHP_BIN artisan $*"
}

composer_exec() {
    ssh_exec "cd '$REMOTE_PROJECT' && $PHP_BIN $COMPOSER_BIN $*"
}
