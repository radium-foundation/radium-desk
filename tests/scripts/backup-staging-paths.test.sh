#!/usr/bin/env bash
#
# Regression tests for bin/lib/backup-staging-paths.sh and staging permission guards.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../../bin/lib/backup-staging-paths.sh
source "${ROOT}/bin/lib/backup-staging-paths.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

STAGING="$(mktemp -d "${TMPDIR:-/tmp}/radium-staging-guard-XXXXXX")"
trap 'rm -rf "$STAGING"' EXIT

backup_staging_validate_root "$STAGING" || fail "valid staging root rejected"
set +e
backup_staging_validate_root "/tmp"
TMP_EC=$?
backup_staging_validate_root "/"
ROOT_EC=$?
set -e
[[ "$TMP_EC" -ne 0 ]] || fail "expected /tmp staging root rejected"
[[ "$ROOT_EC" -ne 0 ]] || fail "expected / staging root rejected"
pass "staging root validation"

nested="${STAGING}/runs/20260101T000000Z"
backup_staging_path_is_under_root "$nested" "$STAGING" || fail "nested path should be under staging"
set +e
backup_staging_path_is_under_root "${STAGING}-sibling/foo" "$STAGING"
SIB_EC=$?
backup_staging_path_is_under_root "/tmp/out.json" "$STAGING"
TMP_UNDER_EC=$?
set -e
[[ "$SIB_EC" -ne 0 ]] || fail "prefix sibling must not match staging root"
[[ "$TMP_UNDER_EC" -ne 0 ]] || fail "/tmp must not be under staging"
pass "canonical under-root checks"

CHMOD_LOG="$(mktemp "${TMPDIR:-/tmp}/chmod-mock-log-XXXXXX")"
SAFE_TMP="$(mktemp -d "${TMPDIR:-/tmp}/radium-safe-tmp-XXXXXX")"
chmod 1777 "$SAFE_TMP"
before="$(stat -f '%Mp%Lp' "$SAFE_TMP" 2>/dev/null || stat -c '%a' "$SAFE_TMP")"

PATH="${ROOT}/tests/scripts/fixtures/backup-mocks:$PATH"
export CHMOD_MOCK_LOG="$CHMOD_LOG"

out="${STAGING}/cloud-inventory.json"
backup_staging_prepare_output_parent "$out" "$STAGING" || fail "prepare parent failed for valid staging path"
grep -q "chmod 700.*${STAGING}" "$CHMOD_LOG" || fail "expected chmod 700 logged for staging parent"

set +e
backup_staging_prepare_output_parent "/tmp/evil.json" "$STAGING"
TMP_PREP_EC=$?
set -e
[[ "$TMP_PREP_EC" -ne 0 ]] || fail "must refuse /tmp output parent"

[[ "$(stat -f '%Mp%Lp' "$SAFE_TMP" 2>/dev/null || stat -c '%a' "$SAFE_TMP")" == "$before" ]] \
    || fail "unrelated tmp directory mode changed"
pass "prepare_output_parent containment"

chmod 700 "$STAGING/subdir" 2>/dev/null || true
CHMOD_LOG2="$(mktemp "${TMPDIR:-/tmp}/chmod-mock-log2-XXXXXX")"
export CHMOD_MOCK_LOG="$CHMOD_LOG2"
backup_staging_chmod_700_if_under_root "${STAGING}/subdir" "$STAGING" || fail "chmod under staging failed"
grep -q "chmod 700" "$CHMOD_LOG2" || fail "chmod not logged"

export CHMOD_MOCK_LOG="$(mktemp "${TMPDIR:-/tmp}/chmod-mock-log3-XXXXXX")"
set +e
backup_staging_chmod_700_if_under_root "/tmp" "$STAGING"
TMP_CHMOD_EC=$?
set -e
[[ "$TMP_CHMOD_EC" -ne 0 ]] || fail "chmod on /tmp should be refused"
! grep -q 'chmod 700 /tmp' "$CHMOD_MOCK_LOG" 2>/dev/null || fail "chmod must not run on /tmp"
pass "chmod guard refuses /tmp"

echo "All backup-staging-paths checks passed."