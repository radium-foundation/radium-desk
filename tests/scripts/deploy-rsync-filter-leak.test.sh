#!/usr/bin/env bash
#
# Regression: dev artifacts must not appear in rsync filter output.
#
# Run: bash tests/scripts/deploy-rsync-filter-leak.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SAFETY_LIB="$ROOT/tools/lib/deploy-rsync-safety.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

print_warning() { :; }
print_error() { echo "ERROR: $*" >&2; }
print_success() { :; }

# shellcheck source=tools/lib/deploy-rsync-safety.sh
source "$SAFETY_LIB"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

src="$TMP/src"
dest="$TMP/dest"
log="$TMP/rsync.log"

mkdir -p "$src/database" "$src/docs/fixtures" "$src/.git" "$src/.cursor"
touch "$src/.DS_Store" "$src/.env.sqlite" "$src/.env.mysql" \
    "$src/database/database.sqlite" "$src/database/testing.sqlite" \
    "$src/docs/.DS_Store" "$src/docs/fixtures/.DS_Store"
echo 'keep' >"$src/README.md"
echo 'example' >"$src/.env.example"

deploy_rsync_local_run_dry_run "$src" "$dest" "$log"

rsync_log_has_path() {
    grep -Eq "(^| )${1}(/|$)" "$log"
}

for leaked in .DS_Store .env.sqlite .env.mysql .git database/database.sqlite database/testing.sqlite; do
    if rsync_log_has_path "$leaked"; then
        fail "rsync dry-run output must not include dev artifact: ${leaked}"
    fi
done

grep -Fq 'README.md' "$log" || fail "expected normal release file in dry-run output"
grep -Fq '.env.example' "$log" || fail ".env.example must remain deployable"

pass "dev artifacts excluded from rsync dry-run output"
