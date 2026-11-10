#!/usr/bin/env bash
# Test double for wrapper simulations — never runs the real suite.
set -euo pipefail
JUNIT=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --log-junit)
      JUNIT="$2"
      shift 2
      ;;
    -c|--configuration|--colors|--no-output|--testdox|--fail-on-warning|--fail-on-risky)
      shift
      ;;
    *)
      shift
      ;;
  esac
done
[[ -n "$JUNIT" ]] || { echo "stub-phpunit: missing --log-junit" >&2; exit 2; }
SRC="${P1010_STUB_JUNIT_SRC:-}"
[[ -n "$SRC" && -f "$SRC" ]] || { echo "stub-phpunit: P1010_STUB_JUNIT_SRC missing" >&2; exit 2; }
cp "$SRC" "$JUNIT"
COUNT="${P1010_STUB_TEST_COUNT:-1}"
RESULT="${P1010_STUB_RESULT:-passed}"
echo "{\"tool\":\"phpunit\",\"result\":\"${RESULT}\",\"tests\":${COUNT},\"passed\":${COUNT},\"failures\":0,\"errors\":0,\"skipped\":0,\"assertions\":${COUNT}}"
exit "${P1010_STUB_EXIT:-0}"
