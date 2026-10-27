#!/usr/bin/env bash
# Verify a RIP checkpoint directory (local staging copy or absolute production path).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PATH_ARG="${1:?usage: verify-rip-checkpoint.sh <checkpoint-directory>}"

PHP_BIN="${PHP_BIN:-php}"
if command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
elif [[ -x "/Users/ravi/Library/Application Support/Herd/bin/php" ]]; then
  PHP_BIN="/Users/ravi/Library/Application Support/Herd/bin/php"
fi

(cd "${REPO_ROOT}" && "${PHP_BIN}" -n artisan rip:verify-checkpoint "${PATH_ARG}")
