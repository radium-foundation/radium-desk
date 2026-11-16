#!/usr/bin/env bash
# P-16-02 — focused permanent regression bundle (NOT full suite). Safe for Cursor when phpunit guard allows focused runs.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

[[ -x vendor/bin/phpunit ]] || { echo "REFUSE: run composer install first" >&2; exit 3; }

exec ./vendor/bin/phpunit --group permanent_regression "$@"