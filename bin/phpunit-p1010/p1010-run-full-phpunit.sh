#!/usr/bin/env bash
# P-10-05 full PHPUnit wrapper — scope isolation, classification, exit-code alignment.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=p1010-wrapper-lib.sh
source "${P1010_LIB:-$SCRIPT_DIR/p1010-wrapper-lib.sh}"

WT="${P1010_WORKTREE:-/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p09-02-phpunit-full}"
CFG="${P1010_PHPUNIT_XML:-/tmp/phpunit-p1010-run.xml}"
PHP_INI="${P1010_PHP_CLI_INI:-/tmp/p1010-php-cli-for-phpunit.ini}"
LOG="${P1010_LOG:-/tmp/p1010-phpunit.log}"
JUNIT="${P1010_JUNIT:-/tmp/p1010-junit.xml}"
META="${P1010_META:-/tmp/p1010-phpunit-meta.txt}"
HEARTBEAT="${P1010_HEARTBEAT:-/tmp/p1010-heartbeat.log}"
WRAPPER_LOG="${P1010_WRAPPER_LOG:-/tmp/p1010-wrapper.log}"
PROGRESS="${P1010_PROGRESS:-/tmp/p1010-phpunit-progress.jsonl}"
LOCK_DIR="${P1010_LOCK_DIR:-/tmp/p1010-full-phpunit.lockdir}"
RUNNER_STATE="${P1010_RUNNER_STATE:-/tmp/p1010-runner.state}"
RUN_CHILD_FILE="${P1010_RUN_CHILD_FILE:-$LOCK_DIR/run_child.pid}"
PHPUNIT_PID_FILE="${P1010_PHPUNIT_PID_FILE:-$LOCK_DIR/phpunit.pid}"
TREE_FILE="${P1010_TREE_FILE:-$LOCK_DIR/process-tree.tsv}"
WALL_CAP_SEC="${P1010_WALL_CAP_SEC:-7200}"
HEARTBEAT_SEC="${P1010_HEARTBEAT_SEC:-60}"
RUN_SUITE="${P1010_RUN_SUITE:-0}"
FOCUS_RUN="${P1010_FOCUS_RUN:-0}"

PHP_BIN="${P1010_PHP_BIN:-/opt/homebrew/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php || true)"
fi

export PATH="/opt/homebrew/bin:/usr/local/bin:${HOME}/Library/Application Support/Herd/bin:$PATH"

PHPUNIT_BIN="${P1010_PHPUNIT_BIN:-$WT/vendor/bin/phpunit}"
PHPUNIT_CMD_ARGS=()
RUN_SCOPE="sim"
LEAKED_ARGS_DETECTED=""

if [[ "$FOCUS_RUN" == "1" && "$RUN_SUITE" == "1" ]]; then
  RUN_SCOPE="invalid"
  RUN_NOTE="rejected_conflicting_modes"
elif [[ "$FOCUS_RUN" == "1" ]]; then
  RUN_SCOPE="focus"
  if [[ -n "${P1010_PHPUNIT_ARGS:-}" ]]; then
    # shellcheck disable=SC2206
    PHPUNIT_CMD_ARGS=(${P1010_PHPUNIT_ARGS})
  fi
elif [[ "$RUN_SUITE" == "1" ]]; then
  RUN_SCOPE="full"
  if [[ -n "${P1010_PHPUNIT_ARGS:-}" ]]; then
    LEAKED_ARGS_DETECTED="${P1010_PHPUNIT_ARGS}"
    RUN_NOTE="rejected_leaked_args"
    unset P1010_PHPUNIT_ARGS
  else
    unset P1010_PHPUNIT_ARGS
    if [[ -n "${P1010_FULL_SUITE_PHPUNIT_ARGS:-}" ]]; then
      if [[ "${P1010_FULL_SUITE_ALLOW_EXTRA_ARGS:-0}" != "1" ]]; then
        RUN_NOTE="rejected_undocumented_args"
      else
        # shellcheck disable=SC2206
        PHPUNIT_CMD_ARGS=(${P1010_FULL_SUITE_PHPUNIT_ARGS})
        if [[ ${#PHPUNIT_CMD_ARGS[@]} -gt 0 ]] && p1010_args_look_like_test_paths "${PHPUNIT_CMD_ARGS[@]}"; then
          RUN_NOTE="rejected_explicit_test_args"
        fi
      fi
    fi
  fi
else
  RUN_SCOPE="sim"
  unset P1010_PHPUNIT_ARGS
fi

RUN_CHILD_PID=""
HEART_PID=""
FINALIZED=0
RUN_EXIT=127
WRAPPER_FINAL_EXIT=127
PHPUNIT_CHILD_EXIT=127
RUN_NOTE="${RUN_NOTE:-unknown}"
JUNIT_VALID="unknown"

log_wrap() { echo "$(date -Iseconds) $*" >>"$WRAPPER_LOG"; }

validate_junit() {
  if [[ ! -s "$JUNIT" ]]; then
    JUNIT_VALID="invalid_empty"
    return 1
  fi
  if ! grep -qE '</testsuites>|</testsuite>' "$JUNIT" 2>/dev/null; then
    JUNIT_VALID="invalid_incomplete"
    return 1
  fi
  JUNIT_VALID="valid"
  return 0
}

classify_note() {
  local ec="$1"
  P1010_SCOPE_NOTE=""
  if grep -q 'Allowed memory size' "$LOG" 2>/dev/null; then
    RUN_NOTE="oom"
  elif grep -qi 'Maximum execution time' "$LOG" 2>/dev/null; then
    RUN_NOTE="max_execution_time"
  elif [[ "$ec" -eq 124 ]]; then
    RUN_NOTE="wall_clock_cap"
  elif [[ "$ec" -eq 143 ]] || [[ "$ec" -eq 130 ]]; then
    RUN_NOTE="signalled_${ec}"
  elif [[ "$ec" -eq 0 ]]; then
    if [[ "$RUN_SUITE" == "1" ]]; then
      if ! validate_junit; then
        RUN_NOTE="junit_${JUNIT_VALID}"
      elif ! p1010_classify_full_suite_success 0 "$LOG" "$JUNIT" "$RUN_SUITE"; then
        RUN_NOTE="${P1010_SCOPE_NOTE:-blocked_tiny_subset}"
      else
        RUN_NOTE="${P1010_SCOPE_NOTE:-complete}"
      fi
    elif grep -qE '^(OK |Tests:)' "$LOG" 2>/dev/null && validate_junit; then
      RUN_NOTE="complete"
    elif grep -qE '"tool":"phpunit"' "$LOG" 2>/dev/null && validate_junit; then
      RUN_NOTE="complete"
    elif [[ "$FOCUS_RUN" == "1" ]] && validate_junit; then
      RUN_NOTE="complete"
    elif [[ "$RUN_SUITE" == "1" ]]; then
      RUN_NOTE="junit_${JUNIT_VALID}"
    elif ! grep -qE '^(OK |Tests:)' "$LOG" 2>/dev/null; then
      RUN_NOTE="incomplete_no_summary"
    elif ! validate_junit; then
      RUN_NOTE="junit_${JUNIT_VALID}"
    else
      RUN_NOTE="incomplete_no_summary"
    fi
  else
    RUN_NOTE="exit_${ec}"
  fi
}

last_progress_line() {
  [[ -f "$PROGRESS" ]] || return 0
  tail -1 "$PROGRESS" 2>/dev/null || true
}

tree_descendants_of() {
  local parent="$1"
  local child
  for child in $(pgrep -P "$parent" 2>/dev/null || true); do
    echo "$child"
    tree_descendants_of "$child"
  done
}

find_phpunit_pid() {
  local root="$1"
  local d cmd
  [[ -z "$root" ]] && return 0
  for d in "$root" $(tree_descendants_of "$root" 2>/dev/null); do
    kill -0 "$d" 2>/dev/null || continue
    cmd=$(ps -p "$d" -o args= 2>/dev/null || ps -o command= -p "$d" 2>/dev/null || true)
    if [[ "$cmd" == *vendor/bin/phpunit* ]] || [[ "$cmd" == *"/phpunit "* ]] || [[ "$cmd" == *" phpunit "* ]] || [[ "$cmd" == *stub-phpunit* ]]; then
      echo "$d"
      return 0
    fi
  done
  echo ""
}

resolve_phpunit_pid() {
  local run_root="$1"
  local pid=""
  if [[ -f "$PHPUNIT_PID_FILE" ]]; then
    pid=$(tr -d ' ' <"$PHPUNIT_PID_FILE" 2>/dev/null || true)
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      echo "$pid"
      return 0
    fi
  fi
  pid=$(find_phpunit_pid "$run_root")
  if [[ -n "$pid" ]]; then
    echo "$pid" >"$PHPUNIT_PID_FILE"
  fi
  echo "$pid"
}

wait_for_phpunit_pid() {
  local root="$1"
  local i pid=""
  for i in $(seq 1 50); do
    pid=$(find_phpunit_pid "$root")
    if [[ -n "$pid" ]]; then
      echo "$pid" >"$PHPUNIT_PID_FILE"
      return 0
    fi
    sleep 0.1
  done
  return 1
}

max_rss_in_tree() {
  local root="$1"
  local max=0 rss
  for d in "$root" $(tree_descendants_of "$root" 2>/dev/null); do
    rss=$(ps -o rss= -p "$d" 2>/dev/null | tr -d ' ' || echo 0)
    [[ "$rss" -gt "$max" ]] && max="$rss"
  done
  echo "$max"
}

resolve_run_root_pid() {
  if [[ -f "$RUN_CHILD_FILE" ]]; then
    cat "$RUN_CHILD_FILE" 2>/dev/null || true
    return
  fi
  echo "${RUN_CHILD_PID:-}"
}

finalize_meta() {
  local ec="$1"
  [[ "$FINALIZED" -eq 1 ]] && return 0
  if grep -q '^P1010_EXIT=' "$META" 2>/dev/null; then
    FINALIZED=1
    return 0
  fi
  FINALIZED=1
  if [[ "$RUN_NOTE" != "lock_busy" && "$RUN_NOTE" != rejected_* ]]; then
    classify_note "$ec"
  fi
  validate_junit || true
  PHPUNIT_CHILD_EXIT="$ec"
  WRAPPER_FINAL_EXIT="$(p1010_resolve_wrapper_exit "$ec" "$RUN_NOTE")"
  local junit_tests
  junit_tests=$(p1010_junit_test_count "$JUNIT")
  if [[ -f "$TREE_FILE" ]]; then
    { echo "P1010_TREE_BEGIN"; cat "$TREE_FILE"; echo "P1010_TREE_END"; } >>"$META"
  fi
  {
    echo "P1010_END=$(date -Iseconds)"
    echo "P1010_PHPUNIT_CHILD_EXIT=$PHPUNIT_CHILD_EXIT"
    echo "P1010_EXIT=$WRAPPER_FINAL_EXIT"
    echo "P1010_NOTE=$RUN_NOTE"
    echo "P1010_JUNIT_VALID=$JUNIT_VALID"
    echo "P1010_JUNIT_TESTS=$junit_tests"
    echo "P1010_JUNIT_BYTES=$(wc -c <"$JUNIT" 2>/dev/null | tr -d ' ')"
    echo "P1010_LOG_BYTES=$(wc -c <"$LOG" 2>/dev/null | tr -d ' ')"
    echo "P1010_PROGRESS_BYTES=$(wc -c <"$PROGRESS" 2>/dev/null | tr -d ' ')"
    echo "P1010_PROGRESS_LAST=$(last_progress_line | head -c 500)"
    echo "P1010_FINALIZED=1"
    echo "P1010_LOG=$LOG"
    echo "P1010_JUNIT=$JUNIT"
    echo "P1010_PROGRESS=$PROGRESS"
    echo "P1010_RUN_SCOPE=$RUN_SCOPE"
    echo "P1010_FULL_SUITE_MIN_TESTS=$(p1010_full_suite_min_tests)"
    echo "P1010_LEAKED_ARGS=${LEAKED_ARGS_DETECTED:-}"
    if [[ ${#PHPUNIT_CMD_ARGS[@]} -gt 0 ]]; then
      echo "P1010_EFFECTIVE_PHPUNIT_ARGS=${PHPUNIT_CMD_ARGS[*]}"
    else
      echo "P1010_EFFECTIVE_PHPUNIT_ARGS=<none>"
    fi
  } >>"$META"
  log_wrap "finalize child_ec=$PHPUNIT_CHILD_EXIT wrapper_ec=$WRAPPER_FINAL_EXIT note=$RUN_NOTE junit=$JUNIT_VALID junit_tests=$junit_tests"
  rm -f "$RUNNER_STATE" "$RUN_CHILD_FILE" "$PHPUNIT_PID_FILE"
  rm -rf "$LOCK_DIR"
  [[ -n "${HEART_PID:-}" ]] && kill "$HEART_PID" 2>/dev/null || true
  wait "$HEART_PID" 2>/dev/null || true
}

record_process_tree() {
  local root_pid="$1"
  mkdir -p "$(dirname "$TREE_FILE")"
  : >"$TREE_FILE"
  {
    echo -e "role\tpid\tppid\tcmd"
    echo -e "wrapper\t$$\t$PPID\tp1010-run-full-phpunit.sh"
    echo -e "run_root\t${root_pid}\t$$\t${RUN_ROLE:-timeout}"
  } >>"$TREE_FILE"
  for child in $(tree_descendants_of "$root_pid"); do
    cmd=$(ps -o command= -p "$child" 2>/dev/null | head -c 120 || echo "?")
    echo -e "child\t${child}\t${root_pid}\t${cmd}" >>"$TREE_FILE"
  done
  echo "$root_pid" >"$LOCK_DIR/run.pid"
}

verify_no_descendants() {
  local root="$1"
  local n=0
  for d in $(tree_descendants_of "$root" 2>/dev/null); do
    if kill -0 "$d" 2>/dev/null; then
      n=$((n + 1))
    fi
  done
  echo "$n"
}

kill_run_tree() {
  local root="$1"
  for d in $(tree_descendants_of "$root" 2>/dev/null); do
    kill -TERM "$d" 2>/dev/null || true
  done
  kill -TERM "$root" 2>/dev/null || true
  sleep 2
  for d in $(tree_descendants_of "$root" 2>/dev/null); do
    kill -KILL "$d" 2>/dev/null || true
  done
  kill -KILL "$root" 2>/dev/null || true
}

heartbeat_loop() {
  while [[ -f "$RUNNER_STATE" ]]; do
    {
      local log_bytes junit_bytes progress_bytes progress_mtime=0
      local run_root phpunit_pid=0 timeout_alive=0 phpunit_alive=0 wrapper_alive=0 rss_kb=0
      local progress_last summary_line=""
      log_bytes=$(wc -c <"$LOG" 2>/dev/null | tr -d ' ' || echo 0)
      junit_bytes=$(wc -c <"$JUNIT" 2>/dev/null | tr -d ' ' || echo 0)
      progress_bytes=$(wc -c <"$PROGRESS" 2>/dev/null | tr -d ' ' || echo 0)
      [[ -f "$PROGRESS" ]] && progress_mtime=$(stat -f %m "$PROGRESS" 2>/dev/null || echo 0)
      wrapper_alive=0
      kill -0 "$$" 2>/dev/null && wrapper_alive=1
      run_root=$(resolve_run_root_pid)
      if [[ -n "$run_root" ]] && kill -0 "$run_root" 2>/dev/null; then
        timeout_alive=1
        phpunit_pid=$(resolve_phpunit_pid "$run_root")
        if [[ -n "$phpunit_pid" ]] && kill -0 "$phpunit_pid" 2>/dev/null; then
          phpunit_alive=1
          rss_kb=$(max_rss_in_tree "$phpunit_pid")
        else
          rss_kb=$(max_rss_in_tree "$run_root")
        fi
      fi
      progress_last=$(last_progress_line | head -c 180)
      summary_line=$(grep -E 'Tests:|OK \(|FAILURES!|ERRORS!|Fatal error:|Maximum execution time' "$LOG" 2>/dev/null | tail -1 || true)
      echo "P1010_HEARTBEAT=$(date -Iseconds) wrapper_alive=$wrapper_alive timeout_alive=$timeout_alive phpunit_alive=$phpunit_alive run_root=${run_root:-0} phpunit_pid=${phpunit_pid:-0} log_bytes=$log_bytes junit_bytes=$junit_bytes progress_bytes=$progress_bytes progress_mtime=$progress_mtime rss_kb=$rss_kb progress_last=${progress_last} log_last=${summary_line:0:100}"
    } >>"$HEARTBEAT"
    sleep "$HEARTBEAT_SEC"
  done
}

log_phpunit_command() {
  local quoted=""
  local a
  local extra_cmd=""
  if [[ ${#PHPUNIT_CMD_ARGS[@]} -gt 0 ]]; then
    for a in "${PHPUNIT_CMD_ARGS[@]}"; do
      quoted+="$(printf '%q ' "$a")"
    done
    extra_cmd=$(printf '%q ' "${PHPUNIT_CMD_ARGS[@]}")
  fi
  log_wrap "run_scope=$RUN_SCOPE phpunit_bin=$PHPUNIT_BIN args=${quoted:-<suite-xml-only>}"
  echo "P1010_RUN_SCOPE=$RUN_SCOPE" >>"$META"
  echo "P1010_PHPUNIT_BIN=$PHPUNIT_BIN" >>"$META"
  if [[ ${#PHPUNIT_CMD_ARGS[@]} -gt 0 ]]; then
    echo "P1010_EFFECTIVE_PHPUNIT_ARGS=${PHPUNIT_CMD_ARGS[*]}"
  else
    echo "P1010_EFFECTIVE_PHPUNIT_ARGS=<none>"
  fi >>"$META"
  echo "P1010_PHP_BIN=$PHP_BIN" >>"$META"
  echo "P1010_EFFECTIVE_PHPUNIT_CMD=$(printf '%q ' timeout --foreground -k 30 "$WALL_CAP_SEC" "$PHP_BIN" -c "$PHP_INI" "$PHPUNIT_BIN" -c "$CFG" --log-junit "$JUNIT")${extra_cmd}" >>"$META"
}

p1010_build_phpunit_argv() {
  P1010_PHPUNIT_ARGV=()
  if [[ "$PHPUNIT_BIN" == *.sh ]] || head -1 "$PHPUNIT_BIN" 2>/dev/null | grep -qE '^#!.*/(ba)?sh'; then
    P1010_PHPUNIT_ARGV=(bash "$PHPUNIT_BIN" -c "$CFG" --log-junit "$JUNIT")
  else
    P1010_PHPUNIT_ARGV=("$PHP_BIN" -c "$PHP_INI" -n -d auto_prepend_file= -d auto_append_file= -d max_execution_time=0 -d output_buffering=0
      "$PHPUNIT_BIN" -c "$CFG" --log-junit "$JUNIT")
  fi
  if [[ ${#PHPUNIT_CMD_ARGS[@]} -gt 0 ]]; then
    P1010_PHPUNIT_ARGV+=("${PHPUNIT_CMD_ARGS[@]}")
  fi
}

run_command() {
  cd "$WT" || return 1
  local -a cmd
  if [[ "$RUN_SUITE" == "1" ]]; then
    if [[ "$RUN_NOTE" == rejected_* ]] || [[ "$RUN_NOTE" == "rejected_conflicting_modes" ]]; then
      echo "P1010_REJECTED_SCOPE note=$RUN_NOTE leaked=${LEAKED_ARGS_DETECTED:-}" >>"$LOG"
      return 2
    fi
    RUN_ROLE="timeout"
    export PHPUNIT_PROGRESS_FILE="$PROGRESS"
    log_phpunit_command
    if command -v stdbuf >/dev/null 2>&1; then
      cmd=(timeout --foreground -k 30 "$WALL_CAP_SEC" stdbuf -oL -eL env PHPUNIT_PROGRESS_FILE="$PROGRESS")
    else
      cmd=(timeout --foreground -k 30 "$WALL_CAP_SEC" env PHPUNIT_PROGRESS_FILE="$PROGRESS")
    fi
    p1010_build_phpunit_argv
    cmd+=("${P1010_PHPUNIT_ARGV[@]}")
  elif [[ "$FOCUS_RUN" == "1" ]]; then
    RUN_ROLE="focus"
    export PHPUNIT_PROGRESS_FILE="$PROGRESS"
    log_phpunit_command
    if command -v stdbuf >/dev/null 2>&1; then
      cmd=(timeout --foreground -k 30 "$WALL_CAP_SEC" stdbuf -oL -eL env PHPUNIT_PROGRESS_FILE="$PROGRESS")
    else
      cmd=(timeout --foreground -k 30 "$WALL_CAP_SEC" env PHPUNIT_PROGRESS_FILE="$PROGRESS")
    fi
    p1010_build_phpunit_argv
    cmd+=("${P1010_PHPUNIT_ARGV[@]}")
  elif [[ -n "${P1010_SIM_CMD:-}" ]]; then
    RUN_ROLE="sim"
    cmd=(timeout --foreground -k 30 "$WALL_CAP_SEC" bash -c "$P1010_SIM_CMD")
  else
    echo "P1010_NO_RUN" >>"$LOG"
    return 2
  fi
  "${cmd[@]}" >>"$LOG" 2>&1 &
  RUN_CHILD_PID=$!
  echo "$RUN_CHILD_PID" >"$RUN_CHILD_FILE"
  : >"$PHPUNIT_PID_FILE"
  wait_for_phpunit_pid "$RUN_CHILD_PID" || true
  record_process_tree "$RUN_CHILD_PID"
  log_wrap "run_child_pid=$RUN_CHILD_PID role=$RUN_ROLE"
  if [[ -z "${HEART_PID:-}" ]]; then
    heartbeat_loop &
    HEART_PID=$!
  fi
  wait "$RUN_CHILD_PID"
  local ec=$?
  local remain
  remain=$(verify_no_descendants "$RUN_CHILD_PID")
  if [[ "$remain" -gt 0 ]]; then
    log_wrap "descendants_remain=$remain sending_kill"
    kill_run_tree "$RUN_CHILD_PID"
  fi
  return "$ec"
}

on_exit() {
  local ec="${1:-$?}"
  if [[ "$FINALIZED" -eq 1 ]]; then
    exit "${WRAPPER_FINAL_EXIT:-$ec}"
  fi
  if [[ -n "${RUN_CHILD_PID:-}" ]] && kill -0 "$RUN_CHILD_PID" 2>/dev/null; then
    kill_run_tree "$RUN_CHILD_PID"
    wait "$RUN_CHILD_PID" 2>/dev/null || true
  fi
  RUN_EXIT=$ec
  finalize_meta "$RUN_EXIT"
  exit "${WRAPPER_FINAL_EXIT:-$ec}"
}

trap 'on_exit $?' EXIT
trap 'on_exit 129' HUP
trap 'on_exit 130' INT
trap 'on_exit 143' TERM

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  RUN_NOTE="lock_busy"
  WRAPPER_FINAL_EXIT=2
  PHPUNIT_CHILD_EXIT=2
  {
    echo "P1010_START=$(date -Iseconds)"
    echo "P1010_END=$(date -Iseconds)"
    echo "P1010_PHPUNIT_CHILD_EXIT=2"
    echo "P1010_EXIT=2"
    echo "P1010_NOTE=lock_busy"
    echo "P1010_FINALIZED=1"
  } >>"$META"
  FINALIZED=1
  exit "$WRAPPER_FINAL_EXIT"
fi

echo "$$" >"$LOCK_DIR/pid"
CFG_SHA=$(shasum -a 256 "$CFG" 2>/dev/null | awk '{print $1}')
{
  echo "P1010_START=$(date -Iseconds)"
  echo "P1010_SHA=$(git -C "$WT" rev-parse HEAD)"
  echo "P1010_PHP_CLI_INI=$PHP_INI"
  echo "P1010_PHPUNIT_XML=$CFG"
  echo "P1010_PHPUNIT_XML_SHA256=$CFG_SHA"
  echo "P1010_RUN_SUITE=$RUN_SUITE"
  echo "P1010_FOCUS_RUN=$FOCUS_RUN"
  echo "P1010_WRAPPER_PID=$$"
  echo "P1010_WALL_CAP_SEC=$WALL_CAP_SEC"
  echo "P1010_PROGRESS=$PROGRESS"
  echo "P1010_HEARTBEAT_SEC=$HEARTBEAT_SEC"
  echo "P1010_BASELINE_FULL_SUITE_TESTS=$P1010_BASELINE_FULL_SUITE_TESTS"
  echo "P1010_FULL_SUITE_MIN_TESTS=$(p1010_full_suite_min_tests)"
} >"$META"

if [[ "$RUN_NOTE" == rejected_* ]] || [[ "$RUN_NOTE" == "rejected_conflicting_modes" ]]; then
  echo "P1010_REJECTED note=$RUN_NOTE" >>"$LOG"
  set +e
  run_command
  RUN_EXIT=$?
  set -e
  exit "$RUN_EXIT"
fi

date -Iseconds >"$RUNNER_STATE"
: >"$LOG"
: >"$JUNIT"
: >"$PROGRESS"

set +e
run_command
RUN_EXIT=$?
set -e
exit "$RUN_EXIT"
