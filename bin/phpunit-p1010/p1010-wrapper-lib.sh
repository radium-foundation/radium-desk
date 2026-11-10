# P-10-04 — shared PHPUnit wrapper helpers (source only).
# shellcheck shell=bash

# Evidence baseline: P-09-09 authoritative full run (8075 tests executed).
P1010_BASELINE_FULL_SUITE_TESTS="${P1010_BASELINE_FULL_SUITE_TESTS:-8075}"
# Ratio expressed in percent to avoid floating-point bash arithmetic (default 75% of baseline).
P1010_FULL_SUITE_MIN_PERCENT="${P1010_FULL_SUITE_MIN_PERCENT:-75}"

p1010_full_suite_min_tests() {
  local min
  min=$(( P1010_BASELINE_FULL_SUITE_TESTS * P1010_FULL_SUITE_MIN_PERCENT / 100 ))
  if [[ "$min" -lt 100 ]]; then
    min=100
  fi
  echo "$min"
}

p1010_junit_test_count() {
  local f="$1"
  [[ -f "$f" && -s "$f" ]] || { echo 0; return 0; }
  local root n sum
  root=$(grep -oE '<testsuites[^>]*\btests="[0-9]+"' "$f" 2>/dev/null | head -1 | grep -oE 'tests="[0-9]+"' | cut -d'"' -f2 || true)
  if [[ -n "$root" && "$root" -gt 0 ]]; then
    echo "$root"
    return 0
  fi
  n=$(grep -oE '<testsuite[^>]*\btests="[0-9]+"' "$f" 2>/dev/null | grep -oE 'tests="[0-9]+"' | cut -d'"' -f2 | awk '{s+=$1} END {print s+0}')
  echo "${n:-0}"
}

p1010_junit_failure_error_count() {
  local f="$1"
  [[ -f "$f" && -s "$f" ]] || { echo "0 0"; return 0; }
  local failures errors
  failures=$(grep -oE '<testsuites[^>]*\bfailures="[0-9]+"' "$f" 2>/dev/null | head -1 | grep -oE 'failures="[0-9]+"' | cut -d'"' -f2 || true)
  errors=$(grep -oE '<testsuites[^>]*\berrors="[0-9]+"' "$f" 2>/dev/null | head -1 | grep -oE 'errors="[0-9]+"' | cut -d'"' -f2 || true)
  if [[ -z "$failures" ]]; then
    failures=$(grep -oE '<testsuite[^>]*\bfailures="[0-9]+"' "$f" 2>/dev/null | grep -oE 'failures="[0-9]+"' | cut -d'"' -f2 | awk '{s+=$1} END {print s+0}')
  fi
  if [[ -z "$errors" ]]; then
    errors=$(grep -oE '<testsuite[^>]*\berrors="[0-9]+"' "$f" 2>/dev/null | grep -oE 'errors="[0-9]+"' | cut -d'"' -f2 | awk '{s+=$1} END {print s+0}')
  fi
  echo "${failures:-0} ${errors:-0}"
}

p1010_log_reported_test_count() {
  local log="$1"
  [[ -f "$log" ]] || { echo ""; return 0; }
  local json n
  json=$(grep -E '"tool"[[:space:]]*:[[:space:]]*"phpunit"' "$log" 2>/dev/null | tail -1 | grep -oE '"tests"[[:space:]]*:[[:space:]]*[0-9]+' | grep -oE '[0-9]+' || true)
  if [[ -n "$json" ]]; then
    echo "$json"
    return 0
  fi
  n=$(grep -oE 'Tests:[[:space:]]*[0-9]+' "$log" 2>/dev/null | tail -1 | grep -oE '[0-9]+' || true)
  echo "${n:-}"
}

p1010_log_has_phpunit_summary() {
  local log="$1"
  grep -qE '^(OK \(|Tests:)' "$log" 2>/dev/null && return 0
  grep -qE '"tool"[[:space:]]*:[[:space:]]*"phpunit"' "$log" 2>/dev/null
}

p1010_args_look_like_test_paths() {
  local arg
  for arg in "$@"; do
    [[ "$arg" == *tests/* ]] && return 0
    [[ "$arg" == *Test.php ]] && return 0
    [[ "$arg" == --filter* ]] && return 0
  done
  return 1
}

# Sets P1010_SCOPE_NOTE on failure paths when RUN_MODE=full|focus|sim.
p1010_classify_full_suite_success() {
  local ec="$1" log="$2" junit="$3" run_suite="$4"
  local min count fe failures errors log_count

  if [[ "$ec" -ne 0 ]]; then
    return 1
  fi
  if [[ "$run_suite" != "1" ]]; then
    return 0
  fi

  min=$(p1010_full_suite_min_tests)
  count=$(p1010_junit_test_count "$junit")
  read -r failures errors <<<"$(p1010_junit_failure_error_count "$junit")"
  log_count=$(p1010_log_reported_test_count "$log")

  if [[ "$count" -lt "$min" ]]; then
    P1010_SCOPE_NOTE="blocked_tiny_subset"
    return 1
  fi
  if [[ -n "$log_count" && "$log_count" -lt "$min" ]]; then
    P1010_SCOPE_NOTE="blocked_tiny_subset"
    return 1
  fi
  if ! p1010_log_has_phpunit_summary "$log"; then
    P1010_SCOPE_NOTE="incomplete_no_summary"
    return 1
  fi
  if [[ "$failures" -gt 0 || "$errors" -gt 0 ]]; then
    P1010_SCOPE_NOTE="complete_with_failures"
    return 0
  fi
  P1010_SCOPE_NOTE="complete"
  return 0
}

# Map classified P1010_NOTE (+ child exit) to the wrapper's final exit code.
p1010_resolve_wrapper_exit() {
  local child_ec="$1"
  local note="$2"
  case "$note" in
    complete)
      echo 0
      ;;
    complete_with_failures)
      if [[ "$child_ec" -ne 0 ]]; then
        echo "$child_ec"
      else
        echo "${P1010_EXIT_FAILED:-1}"
      fi
      ;;
    blocked_*|incomplete_*)
      echo "${P1010_EXIT_BLOCKED:-3}"
      ;;
    junit_*)
      echo "${P1010_EXIT_JUNIT:-4}"
      ;;
    rejected_*|lock_busy|rejected_conflicting_modes)
      echo 2
      ;;
    wall_clock_cap)
      echo 124
      ;;
    oom|max_execution_time)
      if [[ "$child_ec" -ne 0 ]]; then
        echo "$child_ec"
      else
        echo 1
      fi
      ;;
    signalled_*)
      echo "$child_ec"
      ;;
    exit_*)
      local code="${note#exit_}"
      if [[ "$code" =~ ^[0-9]+$ ]]; then
        echo "$code"
      else
        echo "$child_ec"
      fi
      ;;
    *)
      if [[ "$child_ec" -ne 0 ]]; then
        echo "$child_ec"
      else
        echo 1
      fi
      ;;
  esac
}
