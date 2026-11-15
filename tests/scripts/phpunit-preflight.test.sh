#!/usr/bin/env bash
# P-15-19 — preflight fail-closed cases. Does not run PHPUnit or connect to a database.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PREFLIGHT="$ROOT/bin/phpunit-preflight"
FAKE="$ROOT/tests/fixtures/preflight/fake-php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

unset APP_ENV INVENTORY_POS_MYSQL_HOST INVENTORY_POS_MYSQL_DATABASE
unset HARDWARE_FULFILMENT_MYSQL_HOST HARDWARE_FULFILMENT_MYSQL_DATABASE
unset CENTRAL_WALLET_MYSQL_HOST CENTRAL_WALLET_MYSQL_DATABASE
export DESK_PREFLIGHT_COMPOSER_VALIDATE=0

mkdir -p "$TMP/vendor/bin" "$TMP/public/build"
printf '%s\n' '{' '}' >"$TMP/composer.json"
printf '%s\n' '{' '}' >"$TMP/composer.lock"
printf '%s\n' '{' '}' >"$TMP/package-lock.json"
printf '%s\n' '{' '}' >"$TMP/public/build/manifest.json"
cat >"$TMP/phpunit.xml" <<'XML'
<?xml version="1.0"?>
<phpunit>
  <php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
  </php>
</phpunit>
XML
printf '%s\n' '#!/bin/sh' 'exit 0' >"$TMP/vendor/bin/phpunit"
chmod +x "$TMP/vendor/bin/phpunit" "$FAKE"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

expect_blocked() {
  local label="$1"
  shift
  local out ec
  set +e
  out="$(FAKE_PHP_MODE="$label" "$PREFLIGHT" --php-bin "$FAKE" --root "$TMP" "$@" 2>&1)"
  ec=$?
  set -e
  if [[ "$ec" -eq 0 ]]; then
    fail "$label exited 0: $out"
  fi
  if ! printf '%s\n' "$out" | grep -q 'BLOCKED:'; then
    fail "$label did not print BLOCKED: $out"
  fi
  echo "PASS blocked $label"
}

expect_blocked herd-prepend
expect_blocked herd-extension
expect_blocked missing-sqlite

rm -rf "$TMP/vendor"
expect_blocked clean
mkdir -p "$TMP/vendor/bin"
printf '%s\n' '#!/bin/sh' 'exit 0' >"$TMP/vendor/bin/phpunit"
chmod +x "$TMP/vendor/bin/phpunit"

rm -f "$TMP/public/build/manifest.json"
expect_blocked clean tests/Feature/ExampleTest.php
printf '%s\n' '{' '}' >"$TMP/public/build/manifest.json"

export INVENTORY_POS_MYSQL_HOST=187.127.129.16
expect_blocked clean tests/Unit/ExampleTest.php
unset INVENTORY_POS_MYSQL_HOST

export CENTRAL_WALLET_MYSQL_DATABASE=radium_desk
expect_blocked clean tests/Unit/ExampleTest.php
unset CENTRAL_WALLET_MYSQL_DATABASE

out="$(FAKE_PHP_MODE=clean "$PREFLIGHT" --php-bin "$FAKE" --root "$TMP" tests/Feature/ExampleTest.php 2>&1)"
printf '%s\n' "$out" | grep -q 'PREFLIGHT_OK' || fail "safe sqlite did not pass: $out"
printf '%s\n' "$out" | grep -q 'effective_db_connection=sqlite' || fail "safe sqlite did not report sqlite: $out"
echo "PASS safe sqlite"

mkdir -p "$TMP/bin"
cat >"$TMP/bin/composer" <<'EOF'
#!/bin/sh
exit 1
EOF
chmod +x "$TMP/bin/composer"
export DESK_PREFLIGHT_COMPOSER_VALIDATE=1
set +e
out="$(PATH="$TMP/bin:$PATH" FAKE_PHP_MODE=clean "$PREFLIGHT" --php-bin "$FAKE" --root "$TMP" tests/Unit/ExampleTest.php 2>&1)"
ec=$?
set -e
[[ "$ec" -ne 0 ]] || fail "inconsistent composer exited 0: $out"
printf '%s\n' "$out" | grep -q 'BLOCKED:' || fail "inconsistent composer missing BLOCKED: $out"
echo "PASS blocked composer lock"
echo "PREFLIGHT_TESTS_OK"
