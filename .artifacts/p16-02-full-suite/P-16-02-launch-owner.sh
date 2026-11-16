#!/usr/bin/env bash
# P-16-02 — ONE authorized full PHPUnit @ f38395519+ (PR #277) via P1010 + P-12-40 grant/guard (Owner-only). Fail-closed.
# Materialized only. Cursor must NOT invoke. Owner grant required before execution.
set -Eeuo pipefail

readonly TARGET_SHA='206570962a87af52f327ccdefd3d75801906a214'
readonly WT='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p16-01-p1544-resolve'
readonly ENV_SH='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p15-30-full-suite-08363558d/.artifacts/p15-30-full-suite/p15-30-isolated-php-env.sh'
# shellcheck source=/dev/null
source "$ENV_SH"

export PATH="/opt/homebrew/bin:/usr/local/bin:${HOME}/Library/Application Support/Herd/bin:/usr/bin:/bin"
readonly PROMPT_ID='RadiumDesk-P-16-02'
readonly TTL_MINUTES=180
readonly TEMPLATE_INI='/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p12-03-full-suite-prep/.artifacts/p12-03-full-suite/attempt-1-20261112-0822/php-cli-for-phpunit.ini'
readonly PHP_INI='/tmp/p1010-php-cli-for-phpunit-p1602.ini'
readonly WRAPPER="$WT/bin/phpunit-p1010/p1010-run-full-phpunit.sh"

die() { echo "REFUSE: $*" >&2; exit "${2:-3}"; }

ATTEMPT=''
RUN_EXIT=127

preserve_evidence() {
  local dest preserve_stamp f
  [[ -n "${ATTEMPT:-}" && -d "$ATTEMPT" ]] || return 0
  preserve_stamp="$(date +%Y%m%dT%H%M%S%z)"
  dest="$ATTEMPT/preserved-run-${preserve_stamp}"
  mkdir -p "$dest"
  for f in \
    p1010-phpunit.log \
    p1010-phpunit-meta.txt \
    p1010-phpunit-progress.jsonl \
    p1010-junit.xml \
    p1010-heartbeat.log \
    p1010-wrapper.log \
    p1010-runner.state \
    p1602-launch.txt
  do
    [[ -f "$ATTEMPT/$f" ]] && cp -a "$ATTEMPT/$f" "$dest/"
  done
  [[ -f "$WT/storage/app/tmp/phpunit-full-suite-auth.json" ]] && \
    cp -a "$WT/storage/app/tmp/phpunit-full-suite-auth.json" "$dest/"
  [[ -f "$PHP_INI" ]] && cp -a "$PHP_INI" "$dest/p1010-php-cli-for-phpunit.ini"
  echo "P1602_PRESERVED=$dest" >>"$ATTEMPT/p1602-launch.txt" 2>/dev/null || true
}

trap preserve_evidence EXIT

export PHPUNIT_GUARD_PHP_BIN="$PHP_BIN"
export P1010_PHP_BIN="$PHP_BIN"
unset P1010_PHPUNIT_ARGS P1010_FULL_SUITE_PHPUNIT_ARGS P1010_SIM_CMD P1010_FOCUS_RUN || true

[[ -f "$ENV_SH" ]] || die "missing isolated PHP env helper: $ENV_SH"
[[ -x "$PHP_BIN" ]] || die "Homebrew PHP missing: $PHP_BIN"
[[ -d "$WT" ]] || die "worktree missing: $WT"
[[ -x "$WRAPPER" ]] || die "P1010 wrapper missing: $WRAPPER"
[[ -f "$TEMPLATE_INI" ]] || die "verified ini template missing: $TEMPLATE_INI"
[[ -f "$WT/bin/phpunit-safe" ]] || die "missing bin/phpunit-safe"
[[ -f "$WT/bin/phpunit-p1010/p1010-wrapper-lib.sh" ]] || die "missing p1010-wrapper-lib.sh"
[[ -f "$WT/scripts/grant-phpunit-full-suite-auth.php" ]] || die "missing grant-phpunit-full-suite-auth.php"

cd "$WT" || die "cannot cd $WT"

head_sha="$(git rev-parse HEAD)"
[[ "$head_sha" == "$TARGET_SHA" ]] || die "HEAD=$head_sha want $TARGET_SHA"

[[ -f "$WT/public/build/manifest.json" ]] || die "missing public/build/manifest.json (run: npm ci && npm run build)"
/usr/bin/xmllint --noout phpunit.xml || die "phpunit.xml invalid XML"
export INBOUND_EMAIL_GMAIL_SYNC_TIMEOUT_SECONDS=0

p1530_apply_phpunit_ini "$TEMPLATE_INI" "$PHP_INI" || die "ini setup failed"
grep -q '^max_execution_time = 0$' "$PHP_INI" || die "ini missing max_execution_time = 0"
grep -q '^memory_limit = 1024M$' "$PHP_INI" || die "ini missing memory_limit = 1024M"

php_probe="$(
  env PHP_INI_SCAN_DIR= "$PHP_BIN" -c "$PHP_INI" -n \
    -d auto_prepend_file= -d auto_append_file= -d max_execution_time=0 -d output_buffering=0 \
    -r 'echo ini_get("max_execution_time"),"|",ini_get("memory_limit"),"|",extension_loaded("imagick")?"1":"0";'
)"
[[ "$php_probe" == "0|1024M|1" ]] || die "PHP profile probe failed (got: $php_probe)"

[[ -x "$WT/vendor/bin/phpunit" ]] || die "missing vendor/bin/phpunit (composer install?)"

if pgrep -fl 'p1010-run-full-phpunit\.sh|vendor/bin/phpunit.*p16-01-p1544-resolve' >/dev/null 2>&1; then
  pgrep -fl 'p1010-run-full-phpunit\.sh|vendor/bin/phpunit.*p16-01-p1544-resolve' >&2 || true
  die "concurrent PHPUnit/P1010 for this worktree"
fi

if [[ -d /tmp/p1010-full-phpunit.lockdir ]] && [[ -f /tmp/p1010-full-phpunit.lockdir/pid ]]; then
  opid="$(tr -d '[:space:]' </tmp/p1010-full-phpunit.lockdir/pid || true)"
  if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
    die "foreign live lock /tmp/p1010-full-phpunit.lockdir pid=$opid"
  fi
fi

auth_path="$WT/storage/app/tmp/phpunit-full-suite-auth.json"
env PHP_INI_SCAN_DIR= "$PHP_BIN" -n "$WT/scripts/grant-phpunit-full-suite-auth.php" \
  --by="Owner" \
  --prompt-id="$PROMPT_ID" \
  --ttl-minutes="$TTL_MINUTES" \
  --sha="$TARGET_SHA" || die "grant script failed"
[[ -f "$auth_path" ]] || die "grant file not written: $auth_path"

env PHP_INI_SCAN_DIR= DESK_PHP_BIN="$PHP_BIN" PATH="$PATH" "$WT/bin/phpunit-safe" --guard-check-only || die "phpunit-safe guard preflight failed"

ATTEMPT="$WT/.artifacts/p16-02-full-suite/$(date +%Y%m%dT%H%M%S%z)"
mkdir -p "$ATTEMPT"

export P1010_WORKTREE="$WT"
export P1010_PHPUNIT_XML="$WT/phpunit.xml"
export P1010_PHP_CLI_INI="$PHP_INI"
export P1010_PHPUNIT_BIN="$WT/vendor/bin/phpunit"
export P1010_LIB="$WT/bin/phpunit-p1010/p1010-wrapper-lib.sh"
export P1010_RUN_SUITE=1
export P1010_FOCUS_RUN=0
export P1010_WALL_CAP_SEC=10800
export P1010_LOCK_DIR="$ATTEMPT/lockdir"
export P1010_RUNNER_STATE="$ATTEMPT/p1010-runner.state"
export P1010_LOG="$ATTEMPT/p1010-phpunit.log"
export P1010_JUNIT="$ATTEMPT/p1010-junit.xml"
export P1010_META="$ATTEMPT/p1010-phpunit-meta.txt"
export P1010_HEARTBEAT="$ATTEMPT/p1010-heartbeat.log"
export P1010_WRAPPER_LOG="$ATTEMPT/p1010-wrapper.log"
export P1010_PROGRESS="$ATTEMPT/p1010-phpunit-progress.jsonl"

{
  echo "P1602_START=$(date -Iseconds)"
  echo "P1602_SHA=$TARGET_SHA"
  echo "P1602_PROMPT_ID=$PROMPT_ID"
  echo "P1602_ATTEMPT=$ATTEMPT"
  echo "P1602_PHP_INI=$PHP_INI"
  echo "P1602_PHP_BIN=$PHP_BIN"
  echo "P1602_GRANT=$auth_path"
  echo "P1602_LAUNCHER=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
} | tee "$ATTEMPT/p1602-launch.txt"

cd "$WT"
set +e
env PHP_INI_SCAN_DIR= "$WRAPPER"
RUN_EXIT=$?
set -e

{
  echo "P1602_END=$(date -Iseconds)"
  echo "P1602_RUN_EXIT=$RUN_EXIT"
} >>"$ATTEMPT/p1602-launch.txt"

[[ -f "$ATTEMPT/p1010-phpunit-meta.txt" ]] && \
  grep -E '^P1010_(START|END|EXIT|NOTE|FINALIZED|SHA|PHPUNIT_CHILD_EXIT|RUN_SCOPE|JUNIT_VALID|RESULT|LOG|JUNIT|PROGRESS)' \
    "$ATTEMPT/p1010-phpunit-meta.txt" >>"$ATTEMPT/p1602-launch.txt" || true

exit "$RUN_EXIT"