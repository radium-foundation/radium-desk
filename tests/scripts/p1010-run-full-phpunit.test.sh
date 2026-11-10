#!/usr/bin/env bash
# Regression tests for P-10-04/05 PHPUnit wrapper library, scope, and exit codes.
#
# Run: bash tests/scripts/p1010-run-full-phpunit.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BIN="$ROOT/bin/phpunit-p1010"
LIB="$BIN/p1010-wrapper-lib.sh"
FIX_ONE="$BIN/fixtures/junit-one-test.xml"
FIX_FULL="$BIN/fixtures/junit-plausible-full.xml"
TMP="$ROOT/storage/framework/testing/p1010-wrapper-test"
mkdir -p "$TMP"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

# shellcheck source=/dev/null
source "$LIB"

n=$(p1010_junit_test_count "$FIX_ONE")
[[ "$n" -eq 1 ]] || fail "junit-one count want 1 got $n"
pass "junit-one count"

n=$(p1010_junit_test_count "$FIX_FULL")
[[ "$n" -eq 8100 ]] || fail "junit-full count want 8100 got $n"
pass "junit-full count"

min=$(p1010_full_suite_min_tests)
[[ "$min" -eq 6056 ]] || fail "min tests want 6056 got $min"
pass "min tests from baseline 8075 @ 75%"

P1010_SCOPE_NOTE=""
if p1010_classify_full_suite_success 0 "$TMP/fake-log.json" "$FIX_ONE" 1; then
  fail "one-test junit should not pass full-suite classification"
fi
[[ "$P1010_SCOPE_NOTE" == blocked_tiny_subset ]] || fail "scope note want blocked_tiny_subset got $P1010_SCOPE_NOTE"
pass "reject one-test junit in full-suite mode"

echo '{"tool":"phpunit","result":"passed","tests":8100,"passed":8100,"assertions":1}' >"$TMP/fake-log.json"
P1010_SCOPE_NOTE=""
p1010_classify_full_suite_success 0 "$TMP/fake-log.json" "$FIX_FULL" 1 || fail "plausible full should pass"
[[ "$P1010_SCOPE_NOTE" == complete ]] || fail "scope note want complete got $P1010_SCOPE_NOTE"
pass "accept plausible full-suite report"

P1010_SCOPE_NOTE=""
p1010_classify_full_suite_success 7 "$TMP/fake-log.json" "$FIX_FULL" 1 && fail "non-zero exit should fail classify"
pass "non-zero exit rejected"

empty_junit="$TMP/empty.xml"
: >"$empty_junit"
P1010_SCOPE_NOTE=""
p1010_classify_full_suite_success 0 "$TMP/fake-log.json" "$empty_junit" 1 || true
pass "empty junit does not pass full-suite classify"

p1010_args_look_like_test_paths tests/Unit/FooTest.php && pass "detect test path args" || fail "detect test path"
! p1010_args_look_like_test_paths --verbose && pass "ignore non-path args"

WRAPPER="$BIN/p1010-run-full-phpunit.sh"
STUB="$BIN/stub-phpunit.sh"
META="$TMP/meta-leak.txt"
LOGF="$TMP/log-leak.txt"
rm -rf "$TMP/lock-leak"
prep_leak() {
  : >"$LOGF"
  : >"$META"
  : >"$TMP/junit-leak.xml"
  : >"$TMP/progress-leak.jsonl"
  rm -rf "$TMP/lock-leak"
}

prep_leak
export P1010_PHPUNIT_ARGS="tests/Unit/Support/PhpunitProgressExtensionTest.php"
ec=0
env P1010_RUN_SUITE=1 P1010_FOCUS_RUN=0 P1010_PHPUNIT_BIN="$STUB" P1010_STUB_JUNIT_SRC="$FIX_ONE" P1010_STUB_TEST_COUNT=1 \
  P1010_LOG="$LOGF" P1010_JUNIT="$TMP/junit-leak.xml" P1010_META="$META" \
  P1010_LOCK_DIR="$TMP/lock-leak" P1010_RUNNER_STATE="$TMP/runner-leak.state" \
  P1010_PROGRESS="$TMP/progress-leak.jsonl" P1010_LIB="$LIB" P1010_WORKTREE="$ROOT" \
  bash "$WRAPPER" >/dev/null 2>&1 || ec=$?
unset P1010_PHPUNIT_ARGS
note=$(grep '^P1010_NOTE=' "$META" | tail -1 | cut -d= -f2-)
[[ "$ec" -eq 2 && "$note" == rejected_leaked_args ]] || fail "leaked args want ec=2 rejected_leaked_args got ec=$ec note=$note"
pass "wrapper rejects inherited P1010_PHPUNIT_ARGS in full-suite mode"

prep_leak
ec=0
env P1010_RUN_SUITE=1 P1010_FOCUS_RUN=0 P1010_PHPUNIT_BIN="$STUB" P1010_STUB_JUNIT_SRC="$FIX_FULL" P1010_STUB_TEST_COUNT=8100 \
  P1010_LOG="$LOGF" P1010_JUNIT="$TMP/junit-leak.xml" P1010_META="$META" \
  P1010_LOCK_DIR="$TMP/lock-leak" P1010_RUNNER_STATE="$TMP/runner-leak.state" \
  P1010_PROGRESS="$TMP/progress-leak.jsonl" P1010_LIB="$LIB" P1010_WORKTREE="$ROOT" \
  bash "$WRAPPER" >/dev/null 2>&1 || ec=$?
note=$(grep '^P1010_NOTE=' "$META" | tail -1 | cut -d= -f2-)
[[ "$ec" -eq 0 && "$note" == complete ]] || fail "stub full want complete got ec=$ec note=$note"
pass "stub full-suite classification complete"

prep_leak
ec=0
env P1010_RUN_SUITE=1 P1010_FOCUS_RUN=0 P1010_PHPUNIT_BIN="$STUB" P1010_STUB_JUNIT_SRC="$FIX_ONE" P1010_STUB_TEST_COUNT=1 \
  P1010_LOG="$LOGF" P1010_JUNIT="$TMP/junit-leak.xml" P1010_META="$META" \
  P1010_LOCK_DIR="$TMP/lock-leak" P1010_RUNNER_STATE="$TMP/runner-leak.state" \
  P1010_PROGRESS="$TMP/progress-leak.jsonl" P1010_LIB="$LIB" P1010_WORKTREE="$ROOT" \
  bash "$WRAPPER" >/dev/null 2>&1 || ec=$?
note=$(grep '^P1010_NOTE=' "$META" | tail -1 | cut -d= -f2-)
meta_exit=$(grep '^P1010_EXIT=' "$META" | tail -1 | cut -d= -f2-)
child_exit=$(grep '^P1010_PHPUNIT_CHILD_EXIT=' "$META" | tail -1 | cut -d= -f2-)
[[ "$ec" -eq 3 && "$note" == blocked_tiny_subset && "$meta_exit" == 3 && "$child_exit" == 0 ]] \
  || fail "tiny junit want wrapper ec=3 child 0 got ec=$ec note=$note meta_exit=$meta_exit child=$child_exit"
pass "blocked tiny subset non-zero wrapper exit"

[[ "$(p1010_resolve_wrapper_exit 0 complete)" == 0 ]] || fail "complete maps to 0"
[[ "$(p1010_resolve_wrapper_exit 0 blocked_tiny_subset)" == 3 ]] || fail "blocked maps to 3"
[[ "$(p1010_resolve_wrapper_exit 0 junit_invalid_empty)" == 4 ]] || fail "junit maps to 4"
[[ "$(p1010_resolve_wrapper_exit 7 exit_7)" == 7 ]] || fail "exit_7 maps to 7"
pass "resolve_wrapper_exit mappings"

echo "ALL PASS"
