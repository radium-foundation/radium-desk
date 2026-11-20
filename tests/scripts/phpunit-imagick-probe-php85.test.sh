#!/usr/bin/env bash
# P-20-24a — PHP 8.5+ CLI rejects ternary inside exit() for -r; use if/exit probe.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${DESK_PHP_BIN:-/opt/homebrew/bin/php}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

if [[ ! -x "$PHP_BIN" ]]; then
  echo "SKIP: phpunit-imagick-probe-php85 (no executable DESK_PHP_BIN: ${PHP_BIN})"
  exit 0
fi

PROBE_SAFE='if (!extension_loaded("imagick")) { exit(1); }'
PROBE_LEGACY='exit(extension_loaded("imagick") ? 0 : 1)'

set +e
legacy_out="$("$PHP_BIN" -n -r "$PROBE_LEGACY" 2>&1)"
legacy_ec=$?
set -e

if [[ "$legacy_ec" -eq 0 ]] && ! grep -qi 'parse error' <<<"$legacy_out"; then
  echo "NOTE: legacy ternary probe succeeded on this PHP; P-20-24a guard still applies."
else
  grep -qi 'parse error' <<<"$legacy_out" || fail "expected legacy probe parse error on PHP 8.5-style CLI, got ec=${legacy_ec}: ${legacy_out}"
fi

set +e
safe_out="$("$PHP_BIN" -n -r "$PROBE_SAFE" 2>&1)"
safe_ec=$?
set -e
grep -qi 'parse error' <<<"$safe_out" && fail "safe probe must not parse-error without extension: ${safe_out}"
[[ "$safe_ec" -eq 1 ]] || fail "safe probe without imagick must exit 1 (got ${safe_ec})"

ext_dir="$("$PHP_BIN" -r 'echo PHP_EXTENSION_DIR;' 2>/dev/null || true)"
api="$(basename "${ext_dir:-none}")"
IMAGICK_SO=""
for prefix in "${ext_dir}/imagick.so" "/opt/homebrew/lib/php/pecl/${api}/imagick.so" "/usr/local/lib/php/pecl/${api}/imagick.so"; do
  if [[ -f "$prefix" ]]; then
    IMAGICK_SO="$prefix"
    break
  fi
done

if [[ -z "$IMAGICK_SO" ]]; then
  echo "SKIP: imagick.so not found for ${PHP_BIN} (api=${api})"
  exit 0
fi

set +e
loaded_out="$("$PHP_BIN" -n -d "extension=${IMAGICK_SO}" -r "$PROBE_SAFE" 2>&1)"
loaded_ec=$?
set -e
grep -qi 'parse error' <<<"$loaded_out" && fail "safe probe with imagick.so must not parse-error: ${loaded_out}"
[[ "$loaded_ec" -eq 0 ]] || fail "safe probe with ${IMAGICK_SO} must exit 0 (got ${loaded_ec})"

set +e
preflight_out="$(DESK_PREFLIGHT_COMPOSER_VALIDATE=0 "$ROOT/bin/phpunit-preflight" --php-bin "$PHP_BIN" --root "$ROOT" --require-imagick tests/Unit/Support/Testing/PhpunitGuardTest.php 2>&1)"
preflight_ec=$?
set -e
[[ "$preflight_ec" -eq 0 ]] || fail "phpunit-preflight --require-imagick failed: ${preflight_out}"
grep -q 'PREFLIGHT_OK' <<<"$preflight_out" || fail "missing PREFLIGHT_OK: ${preflight_out}"
grep -q 'imagick=imagick' <<<"$preflight_out" || fail "preflight did not report imagick loaded: ${preflight_out}"

echo "IMAGICK_PROBE_PHP85_OK imagick_so=${IMAGICK_SO}"