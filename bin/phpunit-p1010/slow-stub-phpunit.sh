#!/usr/bin/env bash
# P-10-27 — Long-running PHPUnit test double (never invokes vendor/bin/phpunit).
set -euo pipefail

JUNIT=""
DURATION="${P1010_STUB_DURATION_SEC:-45}"
TICK="${P1010_STUB_TICK_SEC:-2}"
RESULT="${P1010_STUB_RESULT:-passed}"
EXIT_CODE="${P1010_STUB_EXIT:-0}"
PROGRESS="${PHPUNIT_PROGRESS_FILE:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --log-junit) JUNIT="$2"; shift 2 ;;
    -c|--configuration|--colors|--no-output|--testdox|--fail-on-warning|--fail-on-risky) shift ;;
    *) shift ;;
  esac
done

[[ -n "$JUNIT" ]] || { echo "slow-stub-phpunit: missing --log-junit" >&2; exit 2; }

emit_progress() {
  local event="$1" name="$2"
  [[ -n "$PROGRESS" ]] || return 0
  printf '%s\n' "{\"ts\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"event\":\"${event}\",\"test\":\"${name}\"}" >>"$PROGRESS"
}

ts() { date -Iseconds; }

echo "$(ts) slow-stub-phpunit start duration=${DURATION}s tick=${TICK}s"

elapsed=0
n=0
while [[ "$elapsed" -lt "$DURATION" ]]; do
  n=$((n + 1))
  name="Tests\\\\Unit\\\\Stub\\\\SlowLifecycleStubTest::test_tick_${n}"
  emit_progress prepared "$name"
  sleep "$TICK"
  emit_progress finished "$name"
  elapsed=$((elapsed + TICK))
done

SRC="${P1010_STUB_JUNIT_SRC:-}"
if [[ -n "$SRC" && -f "$SRC" ]]; then
  cp "$SRC" "$JUNIT"
else
  printf '%s\n' \
    '<?xml version="1.0"?>' \
    "<testsuites tests=\"${n}\" failures=\"0\" errors=\"0\" skipped=\"0\">" \
  "</testsuites>" >"$JUNIT"
fi

COUNT="${P1010_STUB_TEST_COUNT:-$n}"
echo "{\"tool\":\"phpunit\",\"result\":\"${RESULT}\",\"tests\":${COUNT},\"passed\":${COUNT},\"failures\":0,\"errors\":0,\"skipped\":0,\"assertions\":${COUNT}}"
echo "$(ts) slow-stub-phpunit end result=${RESULT} exit=${EXIT_CODE}"
exit "$EXIT_CODE"
