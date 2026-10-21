#!/usr/bin/env bash
#
# RIP capture wrapper: stdin failure, path-based staging, mocked SSH/SCP.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRAPPER="${ROOT}/tools/commands/capture-release-recovery-checkpoint.sh"
REMOTE="${ROOT}/tools/commands/capture-release-recovery-checkpoint-remote.sh"
ARCHIVE_LIB="${ROOT}/tools/lib/rip-capture-archive.sh"
CAPTURE_TEST="${ROOT}/tests/scripts/rip-capture-remote.test.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

command -v zstd >/dev/null 2>&1 || fail "zstd required"

bash -n "${WRAPPER}" || fail "wrapper syntax"
bash -n "${ROOT}/tools/lib/rip-capture-operator-remote.sh" || fail "operator-remote lib syntax"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

MOCK_BIN="${TMP}/bin"
mkdir -p "${MOCK_BIN}"
SSH_LOG="${TMP}/ssh.log"
SCP_LOG="${TMP}/scp.log"
: >"${SSH_LOG}"
: >"${SCP_LOG}"

FAKE_STAGE_ROOT="${TMP}/remote-stages"
mkdir -p "${FAKE_STAGE_ROOT}"

export FAKE_STAGE_ROOT SSH_LOG SCP_LOG ROOT REMOTE ARCHIVE_LIB

cat >"${MOCK_BIN}/ssh" <<'MOCKSSH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"${SSH_LOG}"

args=("$@")
idx=0
while [[ $idx -lt ${#args[@]} ]]; do
  if [[ "${args[$idx]}" == "-o" ]]; then
    idx=$((idx + 2))
    continue
  fi
  break
done
host="${args[$idx]:-}"
idx=$((idx + 1))
if [[ "${args[$idx]:-}" == "--" ]]; then
  idx=$((idx + 1))
fi
cmd=("${args[@]:$idx}")

case "${cmd[0]:-}" in
  mktemp)
    stage="${FAKE_STAGE_ROOT}/stage-$(date +%s%N)"
    mkdir -p "${stage}/tools/commands" "${stage}/tools/lib"
    echo "${stage}"
    exit 0
    ;;
  mkdir|chmod|rm|test)
    filtered=()
    for a in "${cmd[@]:1}"; do
      [[ "$a" == "--" ]] && continue
      filtered+=("$a")
    done
    exec "/bin/${cmd[0]}" "${filtered[@]}"
    ;;
  bash)
    if [[ "${cmd[1]:-}" == "-s" ]]; then
      script="$(cat)"
      if [[ "${cmd[2]:-}" == "--" ]]; then
        exec /bin/bash -s -- "${cmd[@]:3}" <<<"${script}"
      fi
      exec /bin/bash -s <<<"${script}"
    fi
    if [[ "${cmd[1]:-}" == "--" && "${cmd[2]:-}" == *capture-release-recovery-checkpoint-remote.sh ]]; then
      script="${cmd[2]}"
      exec /bin/bash -- "${script}" "${cmd[@]:3}"
    fi
    if [[ "${cmd[1]:-}" == "-c" ]]; then
      exec /bin/bash "${cmd[@]:1}"
    fi
    exec /bin/bash "${cmd[@]:1}"
    ;;
  env)
    exec /usr/bin/env "${cmd[@]:1}"
    ;;
  *)
    echo "mock-ssh: unsupported command: ${cmd[*]}" >&2
    exit 127
    ;;
esac
MOCKSSH

cat >"${MOCK_BIN}/scp" <<'MOCKSCP'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"${SCP_LOG}"

if [[ "$1" == "-o" ]]; then
  shift 2
fi
src="$1"
dest="$2"
remote_path="${dest#*:}"
/bin/mkdir -p "$(dirname "${remote_path}")"
/bin/cp -f "${src}" "${remote_path}"
MOCKSCP

chmod +x "${MOCK_BIN}/ssh" "${MOCK_BIN}/scp"

# 1) stdin + set -u reproduces missing helper (BASH_SOURCE under bash -s)
REPRO="${TMP}/stdin-repro.sh"
head -8 "${REMOTE}" >"${REPRO}"
echo 'exit 0' >>"${REPRO}"
set +e
(
  set -euo pipefail
  cd /tmp
  /bin/bash -s <"${REPRO}" 2>"${TMP}/stdin.err"
) 
set -e
if ! grep -qE 'rip-capture-archive|BASH_SOURCE|unbound|No such file' "${TMP}/stdin.err" 2>/dev/null; then
  fail "expected stdin/BASH_SOURCE failure; got: $(cat "${TMP}/stdin.err" 2>/dev/null || true)"
fi
pass "stdin delivery fails to source archive helper (reproduced)"

# 2) path-based remote invocation with staged helper
FAKE_APP="${TMP}/laravel"
mkdir -p "${FAKE_APP}/storage/app/private/release-checkpoints" "${FAKE_APP}/app/Http" "${FAKE_APP}/config" "${FAKE_APP}/database/migrations" "${FAKE_APP}/public/build"
echo '{}' >"${FAKE_APP}/config/app.php"
echo '-- m' >"${FAKE_APP}/database/migrations/0001.php"
echo '{}' >"${FAKE_APP}/public/build/manifest.json"
echo '{"version":"1.0.0","build":"7441de0"}' >"${FAKE_APP}/storage/app/private/release.json"
echo '#!/usr/bin/env php' >"${FAKE_APP}/artisan"
echo '<?php exit(0);' >>"${FAKE_APP}/artisan"
chmod +x "${FAKE_APP}/artisan"

STAGE="${FAKE_STAGE_ROOT}/manual-stage"
mkdir -p "${STAGE}/tools/commands" "${STAGE}/tools/lib"
cp "${REMOTE}" "${STAGE}/tools/commands/capture-release-recovery-checkpoint-remote.sh"
cp "${ARCHIVE_LIB}" "${STAGE}/tools/lib/rip-capture-archive.sh"
PHP_BIN="/opt/homebrew/bin/php"
command -v "${PHP_BIN}" >/dev/null 2>&1 || PHP_BIN="$(command -v php)"
ID="RIP-211026-1200-radium-desk-wrapper-path-7441de0"
/bin/bash -- "${STAGE}/tools/commands/capture-release-recovery-checkpoint-remote.sh" \
  "${ID}" wrapper-path 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed "${FAKE_APP}" "${PHP_BIN}" \
  || fail "path-based remote capture failed"
[[ -d "${FAKE_APP}/storage/app/private/release-checkpoints/${ID}" ]] || fail "checkpoint published via path invoke"
pass "path-based remote invocation publishes checkpoint"

# 3) wrapper end-to-end with mocked ssh/scp (no real network)
export PATH="${MOCK_BIN}:${PATH}"
export RIP_CAPTURE_SSH_BIN="${MOCK_BIN}/ssh"
export RIP_CAPTURE_SCP_BIN="${MOCK_BIN}/scp"
export DESK_DEPLOY_HOST="mock@localhost"
export DESK_LARAVEL_ROOT="${FAKE_APP}"
export DESK_PHP_BIN="${PHP_BIN}"

VERIFY_ARTISAN="${TMP}/verify-artisan.sh"
cat >"${VERIFY_ARTISAN}" <<'ART'
#!/usr/bin/env bash
if [[ "${1:-}" == "artisan" && "${2:-}" == rip:verify-checkpoint ]]; then
  exit 0
fi
exit 0
ART
chmod +x "${VERIFY_ARTISAN}"
# Replace php with script that accepts artisan rip:verify-checkpoint
MOCK_PHP="${TMP}/php-mock"
cat >"${MOCK_PHP}" <<MOCKPHP
#!/usr/bin/env bash
if [[ "\$1" == *artisan* ]]; then
  exit 0
fi
exec "${PHP_BIN}" "\$@"
MOCKPHP
chmod +x "${MOCK_PHP}"
export DESK_PHP_BIN="${MOCK_PHP}"

: >"${SSH_LOG}"
: >"${SCP_LOG}"

OUT="$(
  TZ=Asia/Kolkata RIP_STAMP_FIXED=211026-1200 \
  bash "${WRAPPER}" \
    --change wrapper-mock \
    --git-sha 7441de0 \
    --status deployed 2>&1
)" || fail "wrapper mock run failed: ${OUT}"

grep -q 'capture-release-recovery-checkpoint-remote.sh' "${SCP_LOG}" || fail "remote script not transferred"
grep -q 'rip-capture-archive.sh' "${SCP_LOG}" || fail "archive helper not transferred"
grep -q 'bash --' "${SSH_LOG}" || fail "remote capture not invoked via bash path"
grep -q 'rm -rf' "${SSH_LOG}" || fail "remote staging not cleaned up"
CKPT_ID="$(echo "${OUT}" | sed -n 's/^checkpoint_id=//p')"
[[ -n "${CKPT_ID}" ]] || fail "checkpoint_id missing from wrapper output"
grep -qF "${CKPT_ID}" "${SSH_LOG}" || fail "post-capture verify must pass CHECKPOINT_ID via bash -s argv"
grep -qF "${MOCK_PHP}" "${SSH_LOG}" || fail "post-capture verify must pass PHP_BIN via bash -s argv"
bash_verify_count="$(grep -c 'bash -s' "${SSH_LOG}" || true)"
[[ "${bash_verify_count}" -ge 2 ]] || fail "expected preflight and post-capture bash -s SSH invocations"
pass "wrapper stages tooling and invokes remote script by path"

# 4) scp failure cleans staging
export RIP_CAPTURE_SCP_BIN="${TMP}/scp-fail"
cat >"${RIP_CAPTURE_SCP_BIN}" <<'FAILSCP'
#!/usr/bin/env bash
exit 1
FAILSCP
chmod +x "${RIP_CAPTURE_SCP_BIN}"
: >"${SSH_LOG}"
set +e
bash "${WRAPPER}" --change scp-fail --git-sha 7441de0 >/dev/null 2>&1
rc=$?
set -e
[[ "$rc" -ne 0 ]] || fail "wrapper must fail when scp fails"
grep -q 'rm -rf' "${SSH_LOG}" || fail "staging cleanup after scp failure"
pass "scp failure aborts and cleans remote staging"

# 5) remote capture failure — no checkpoint dir
export RIP_CAPTURE_SCP_BIN="${MOCK_BIN}/scp"
ID_FAIL="RIP-211026-1201-radium-desk-wrapper-fail-7441de0"
set +e
/bin/bash -- "${STAGE}/tools/commands/capture-release-recovery-checkpoint-remote.sh" \
  "${ID_FAIL}" fail-case 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed /no/such/app "${PHP_BIN}" >/dev/null 2>&1
rc=$?
set -e
[[ "$rc" -ne 0 ]] || fail "remote must fail for missing app root"
[[ ! -e "${FAKE_APP}/storage/app/private/release-checkpoints/${ID_FAIL}" ]] || fail "failed capture must not publish checkpoint"
pass "remote failure does not publish checkpoint"

# 6) refuse overwrite existing checkpoint
DUP_ID="$(bash "${WRAPPER}" --dry-run --change wrapper-dup --git-sha 7441de0 | sed -n 's/^checkpoint_id=//p')"
[[ -n "${DUP_ID}" ]] || fail "dry-run checkpoint_id missing"
mkdir -p "${FAKE_APP}/storage/app/private/release-checkpoints/${DUP_ID}"
set +e
bash "${WRAPPER}" --change wrapper-dup --git-sha 7441de0 >/dev/null 2>&1
rc=$?
set -e
[[ "$rc" -ne 0 ]] || fail "must refuse existing checkpoint"
pass "wrapper refuses existing checkpoint ID"

# 7) metacharacter-safe argv: change slug allows hyphens; LARAVEL_ROOT with spaces via mock
FAKE_APP_SPACE="${TMP}/laravel space"
mkdir -p "${FAKE_APP_SPACE}/storage/app/private/release-checkpoints" "${FAKE_APP_SPACE}/config" "${FAKE_APP_SPACE}/database/migrations" "${FAKE_APP_SPACE}/public/build" "${FAKE_APP_SPACE}/app/Http"
echo '{}' >"${FAKE_APP_SPACE}/config/app.php"
echo '-- m' >"${FAKE_APP_SPACE}/database/migrations/0001.php"
echo '{}' >"${FAKE_APP_SPACE}/public/build/manifest.json"
echo '{"build":"7441de0"}' >"${FAKE_APP_SPACE}/storage/app/private/release.json"
echo '#!/usr/bin/env php' >"${FAKE_APP_SPACE}/artisan"
echo '<?php exit(0);' >>"${FAKE_APP_SPACE}/artisan"
chmod +x "${FAKE_APP_SPACE}/artisan"
export DESK_LARAVEL_ROOT="${FAKE_APP_SPACE}"
: >"${SSH_LOG}"
OUT_SPACE="$(bash "${WRAPPER}" --change 'a-b' --git-sha 7441de0 2>&1)" || fail "wrapper with spaced root failed: ${OUT_SPACE}"
grep -qF "laravel space" "${SSH_LOG}" || fail "spaced LARAVEL_ROOT must appear in ssh argv log"
pass "arguments passed safely (spaced application root)"

# 8) reject whitespace-only PHP_BIN before SSH
set +e
OUT_BAD_PHP="$(DESK_PHP_BIN='   ' bash "${WRAPPER}" --change bad-php --git-sha 7441de0 2>&1)"
rc_bad_php=$?
set -e
[[ "$rc_bad_php" -ne 0 ]] || fail "wrapper must reject whitespace-only PHP_BIN"
echo "${OUT_BAD_PHP}" | grep -q 'PHP_BIN is empty' || fail "expected PHP_BIN validation message"
pass "wrapper rejects empty PHP_BIN configuration"

bash "${CAPTURE_TEST}" || fail "rip-capture-remote.test.sh regression"

pass "rip-capture-wrapper.test.sh complete"
