#!/usr/bin/env bash
# P-10-04 wrapper simulation battery (+ scope / classification cases)
set -euo pipefail

BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$BIN/../.." && pwd)"
WRAPPER="$BIN/p1010-run-full-phpunit.sh"
STUB="$BIN/stub-phpunit.sh"
FIXTURE_ONE="$BIN/fixtures/junit-one-test.xml"
FIXTURE_FULL="$BIN/fixtures/junit-plausible-full.xml"
ROOT=/tmp/p1010-sim
mkdir -p "$ROOT"
LOG="$ROOT/simulation.log"
: >"$LOG"
pass=0
fail=0

prep() {
  rm -rf "$ROOT/lockdir" "$ROOT/runner.state"
  : >"$ROOT/phpunit.log"
  : >"$ROOT/junit.xml"
  : >"$ROOT/meta.txt"
  : >"$ROOT/wrapper.log"
  : >"$ROOT/heartbeat.log"
  : >"$ROOT/progress.jsonl"
}

run_wrapper_isolated() {
  (
    unset P1010_PHPUNIT_ARGS P1010_SIM_CMD P1010_RUN_SUITE P1010_FOCUS_RUN 2>/dev/null || true
    export P1010_WORKTREE="${P1010_WORKTREE:-$REPO_ROOT}"
    export P1010_PHPUNIT_XML="${P1010_PHPUNIT_XML:-$REPO_ROOT/phpunit.xml}"
    export P1010_PHP_CLI_INI=/tmp/p1010-php-cli-for-phpunit.ini
    export P1010_LOG="$ROOT/phpunit.log"
    export P1010_JUNIT="$ROOT/junit.xml"
    export P1010_META="$ROOT/meta.txt"
    export P1010_HEARTBEAT="$ROOT/heartbeat.log"
    export P1010_WRAPPER_LOG="$ROOT/wrapper.log"
    export P1010_PROGRESS="$ROOT/progress.jsonl"
    export P1010_LOCK_DIR="$ROOT/lockdir"
    export P1010_RUNNER_STATE="$ROOT/runner.state"
    export P1010_LIB="$BIN/p1010-wrapper-lib.sh"
  env "$@"
  )
}

record() {
  local name="$1" expect_ec="$2" expect_note="$3"
  shift 3
  prep
  local ec=0
  run_wrapper_isolated "$@" >>"$LOG" 2>&1 || ec=$?
  local note
  note=$(grep '^P1010_NOTE=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
  local fin
  fin=$(grep '^P1010_FINALIZED=' "$ROOT/meta.txt" 2>/dev/null | tail -1 || true)
  if [[ "$ec" -eq "$expect_ec" ]] && [[ "$note" == "$expect_note" ]] && [[ -n "$fin" ]]; then
    echo "PASS $name ec=$ec note=$note" | tee -a "$LOG"
    pass=$((pass + 1))
  else
    echo "FAIL $name ec=$ec (want $expect_ec) note=$note (want $expect_note) fin=$fin" | tee -a "$LOG"
    fail=$((fail + 1))
  fi
}

record normal_success 0 complete \
  env P1010_SIM_CMD='printf "%s\n" "OK (1 test, 1 assertion)" "Tests: 1, Assertions: 1"; printf "%s" "<?xml version=\"1.0\"?><testsuites tests=\"1\"></testsuites>" >'"$ROOT/junit.xml"'; exit 0' P1010_RUN_SUITE=0 "$WRAPPER"

record nonzero_exit 7 exit_7 env P1010_SIM_CMD='echo FAIL; exit 7' P1010_RUN_SUITE=0 "$WRAPPER"

record wall_timeout 124 wall_clock_cap env P1010_WALL_CAP_SEC=2 P1010_SIM_CMD='sleep 30' P1010_RUN_SUITE=0 "$WRAPPER"

prep
mkdir -p "$ROOT/lockdir"
echo 1 >"$ROOT/lockdir/pid"
ec=0
run_wrapper_isolated env P1010_LOCK_DIR="$ROOT/lockdir" P1010_RUN_SUITE=0 "$WRAPPER" >>"$LOG" 2>&1 || ec=$?
note=$(grep '^P1010_NOTE=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
fin=$(grep '^P1010_FINALIZED=' "$ROOT/meta.txt" 2>/dev/null | tail -1 || true)
if [[ "$ec" -eq 2 ]] && [[ "$note" == lock_busy ]] && [[ -n "$fin" ]]; then
  echo "PASS duplicate_lock ec=$ec note=$note" | tee -a "$LOG"
  pass=$((pass + 1))
else
  echo "FAIL duplicate_lock ec=$ec note=$note" | tee -a "$LOG"
  fail=$((fail + 1))
fi

record invalid_junit 4 junit_invalid_empty env P1010_SIM_CMD='echo Tests: 1, Assertions: 1; exit 0' P1010_RUN_SUITE=0 "$WRAPPER"

prep
ec=0
run_wrapper_isolated env P1010_WALL_CAP_SEC=120 P1010_HEARTBEAT_SEC=1 P1010_FOCUS_RUN=1 \
  P1010_PHPUNIT_ARGS="tests/Unit/Support/PhpunitProgressExtensionTest.php" \
  "$WRAPPER" >>"$LOG" 2>&1 || ec=$?
note=$(grep '^P1010_NOTE=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
junit_ok=0
grep -qE '</testsuites>|</testsuite>' "$ROOT/junit.xml" 2>/dev/null && [[ -s "$ROOT/junit.xml" ]] && junit_ok=1
scope=$(grep '^P1010_RUN_SCOPE=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
if [[ "$ec" -eq 0 ]] && grep -q 'phpunit_alive=1' "$ROOT/heartbeat.log" && [[ -s "$ROOT/progress.jsonl" ]] && [[ "$junit_ok" -eq 1 ]] && [[ "$note" == complete ]] && [[ "$scope" == focus ]]; then
  echo "PASS progress_heartbeat ec=$ec note=$note scope=$scope" | tee -a "$LOG"
  pass=$((pass + 1))
else
  echo "FAIL progress_heartbeat ec=$ec note=$note scope=$scope heartbeat=$(wc -l <"$ROOT/heartbeat.log")" | tee -a "$LOG"
  fail=$((fail + 1))
fi

prep
/opt/homebrew/bin/php -r 'sleep(8);' &
UNREL=$!
run_wrapper_isolated env P1010_SIM_CMD='sleep 1' P1010_RUN_SUITE=0 "$WRAPPER" >>"$LOG" 2>&1 || true
if kill -0 "$UNREL" 2>/dev/null; then
  echo "PASS unrelated_survives" | tee -a "$LOG"
  pass=$((pass + 1))
else
  echo "FAIL unrelated_survives" | tee -a "$LOG"
  fail=$((fail + 1))
fi
kill "$UNREL" 2>/dev/null || true
wait "$UNREL" 2>/dev/null || true

record leaked_args_rejected 2 rejected_leaked_args \
  env P1010_PHPUNIT_ARGS="tests/Unit/Support/PhpunitProgressExtensionTest.php" P1010_RUN_SUITE=1 \
  P1010_PHPUNIT_BIN="$STUB" P1010_STUB_JUNIT_SRC="$FIXTURE_ONE" P1010_STUB_TEST_COUNT=1 "$WRAPPER"

record tiny_junit_blocked 3 blocked_tiny_subset \
  env P1010_RUN_SUITE=1 P1010_PHPUNIT_BIN="$STUB" \
  P1010_STUB_JUNIT_SRC="$FIXTURE_ONE" P1010_STUB_TEST_COUNT=1 "$WRAPPER"

record plausible_full_complete 0 complete \
  env P1010_RUN_SUITE=1 P1010_PHPUNIT_BIN="$STUB" \
  P1010_STUB_JUNIT_SRC="$FIXTURE_FULL" P1010_STUB_TEST_COUNT=8100 "$WRAPPER"

prep
ec=0
export P1010_PHPUNIT_ARGS="tests/Unit/EvilLeakTest.php"
run_wrapper_isolated env P1010_RUN_SUITE=1 P1010_PHPUNIT_BIN="$STUB" \
  P1010_STUB_JUNIT_SRC="$FIXTURE_FULL" P1010_STUB_TEST_COUNT=8100 "$WRAPPER" >>"$LOG" 2>&1 || ec=$?
unset P1010_PHPUNIT_ARGS
note=$(grep '^P1010_NOTE=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
eff=$(grep '^P1010_EFFECTIVE_PHPUNIT_ARGS=' "$ROOT/meta.txt" 2>/dev/null | tail -1 | cut -d= -f2-)
if [[ "$ec" -eq 0 ]] && [[ "$note" == complete ]] && [[ "$eff" == "<none>" ]]; then
  echo "PASS leakage_subprocess_isolated ec=$ec note=$note effective=$eff" | tee -a "$LOG"
  pass=$((pass + 1))
else
  echo "FAIL leakage_subprocess_isolated ec=$ec note=$note effective=$eff" | tee -a "$LOG"
  fail=$((fail + 1))
fi

record focus_explicit_args 0 complete \
  env P1010_FOCUS_RUN=1 P1010_PHPUNIT_ARGS="tests/Unit/Support/PhpunitProgressExtensionTest.php" \
  P1010_WALL_CAP_SEC=120 "$WRAPPER"

echo "SIMMARY pass=$pass fail=$fail log=$LOG" | tee -a "$LOG"
[[ "$fail" -eq 0 ]]
