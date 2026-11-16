#!/usr/bin/env bash
# P-16-04 — Owner launcher non-mutating preflight (stops before grant / P1010). Not for full suite execution.
set -Eeuo pipefail

LAUNCHER="$(cd "$(dirname "$0")/../p16-02-full-suite" && pwd)/P-16-02-launch-owner.sh"
[[ -f "$LAUNCHER" ]] || { echo "REFUSE: missing $LAUNCHER" >&2; exit 3; }

WT='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p16-01-p1544-resolve'
ENV_SH='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p15-30-full-suite-08363558d/.artifacts/p15-30-full-suite/p15-30-isolated-php-env.sh'
# shellcheck source=/dev/null
source "$ENV_SH"

export PATH="/opt/homebrew/bin:/usr/local/bin:${HOME}/Library/Application Support/Herd/bin:/usr/bin:/bin"
TEMPLATE_INI='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p12-03-full-suite-prep/.artifacts/p12-03-full-suite/attempt-1-20261112-0822/php-cli-for-phpunit.ini'
PHP_INI='/tmp/p1010-php-cli-for-phpunit-p1604-preflight.ini'
WRAPPER="$WT/bin/phpunit-p1010/p1010-run-full-phpunit.sh"

die() { echo "REFUSE: $*" >&2; exit "${2:-3}"; }

[[ -f "$ENV_SH" ]] || die "missing isolated PHP env helper"
[[ -x "$PHP_BIN" ]] || die "Homebrew PHP missing: $PHP_BIN"
[[ -d "$WT" ]] || die "worktree missing: $WT"
[[ -x "$WRAPPER" ]] || die "P1010 wrapper missing: $WRAPPER"
[[ -f "$TEMPLATE_INI" ]] || die "verified ini template missing"
[[ -f "$WT/bin/phpunit-safe" ]] || die "missing bin/phpunit-safe"
[[ -f "$WT/bin/phpunit-p1010/p1010-wrapper-lib.sh" ]] || die "missing p1010-wrapper-lib.sh"
[[ -f "$WT/scripts/grant-phpunit-full-suite-auth.php" ]] || die "missing grant-phpunit-full-suite-auth.php"

TARGET_SHA="$(grep -m1 "^readonly TARGET_SHA=" "$LAUNCHER" | sed "s/.*'\([^']*\)'.*/\1/")"
[[ "$TARGET_SHA" =~ ^[0-9a-f]{40}$ ]] || die "invalid TARGET_SHA in launcher"

cd "$WT" || die "cannot cd $WT"
head_sha="$(git rev-parse HEAD)"
[[ "$head_sha" == "$TARGET_SHA" ]] || die "HEAD=$head_sha want $TARGET_SHA"

[[ -f "$WT/public/build/manifest.json" ]] || die "missing public/build/manifest.json"
/usr/bin/xmllint --noout phpunit.xml || die "phpunit.xml invalid XML"

p1530_apply_phpunit_ini "$TEMPLATE_INI" "$PHP_INI" || die "ini setup failed"
grep -q '^max_execution_time = 0$' "$PHP_INI" || die "ini missing max_execution_time = 0"
grep -q '^memory_limit = 1024M$' "$PHP_INI" || die "ini missing memory_limit = 1024M"

php_probe="$(
  env PHP_INI_SCAN_DIR= "$PHP_BIN" -c "$PHP_INI" -n \
    -d auto_prepend_file= -d auto_append_file= -d max_execution_time=0 -d output_buffering=0 \
    -r 'echo ini_get("max_execution_time"),"|",ini_get("memory_limit"),"|",extension_loaded("imagick")?"1":"0";'
)"
[[ "$php_probe" == "0|1024M|1" ]] || die "PHP profile probe failed (got: $php_probe)"

[[ -x "$WT/vendor/bin/phpunit" ]] || die "missing vendor/bin/phpunit"

if pgrep -fl 'p1010-run-full-phpunit\.sh|vendor/bin/phpunit.*p16-01-p1544-resolve' >/dev/null 2>&1; then
  die "concurrent PHPUnit/P1010 for this worktree"
fi

# Guard must deny full suite without Owner grant (fail-closed).
set +e
GUARD_OUT="$(env PHP_INI_SCAN_DIR= DESK_PHP_BIN="$PHP_BIN" PATH="$PATH" "$WT/bin/phpunit-safe" --guard-check-only 2>&1)"
GUARD_EC=$?
set -e
if [[ $GUARD_EC -ne 0 ]] && echo "$GUARD_OUT" | grep -q 'guard blocked'; then
  echo "PREFLIGHT_OK guard=blocked_without_grant TARGET_SHA=$TARGET_SHA HEAD=$head_sha PROMPT_ID=RadiumDesk-P-16-02 TTL_MINUTES=180"
  exit 0
fi

die "expected full-suite guard to block without grant (ec=$GUARD_EC)"