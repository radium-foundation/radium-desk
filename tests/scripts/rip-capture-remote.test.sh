#!/usr/bin/env bash
#
# Local RIP capture archive + atomic publish tests (no SSH / production).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REMOTE="${ROOT}/tools/commands/capture-release-recovery-checkpoint-remote.sh"
ARCHIVE_LIB="${ROOT}/tools/lib/rip-capture-archive.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

command -v zstd >/dev/null 2>&1 || fail "zstd required for rip-capture-remote tests"

bash -n "${REMOTE}" || fail "remote capture script syntax"
bash -n "${ARCHIVE_LIB}" || fail "rip-capture-archive.sh syntax"
# shellcheck source=tools/lib/rip-capture-archive.sh
source "${ARCHIVE_LIB}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

make_fake_app() {
    local app="$1"
    mkdir -p "${app}/app/Http" "${app}/public/build" "${app}/config" "${app}/database/migrations" "${app}/storage/app/private/release-checkpoints"
    echo '{}' >"${app}/config/app.php"
    echo '-- migration' >"${app}/database/migrations/0001_test.php"
    mkdir -p "${app}/storage/app/public" "${app}/vendor" "${app}/node_modules"
    echo 'class App {}' >"${app}/app/Http/Kernel.php"
    echo '{}' >"${app}/public/build/manifest.json"
    echo 'SECRET=1' >"${app}/.env"
    echo '{"version":"1.0.0","build":"abc1234"}' >"${app}/storage/app/private/release.json"
    echo 'overlay-secret' >"${app}/storage/app/private/overlay-runtime.json"
    echo 'upload' >"${app}/storage/app/public/customer.jpg"
    mkdir -p "${app}/tests/Unit" "${app}/.cursor"
    echo 'test' >"${app}/tests/Unit/ExampleTest.php"
    echo 'rules' >"${app}/.cursor/rules.md"
    echo 'leak' >"${app}/OPERATOR-NOTES.local.md"
    echo '#!/usr/bin/env php' >"${app}/artisan"
    echo '<?php exit(0);' >>"${app}/artisan"
    chmod +x "${app}/artisan"
}

FAKE="${TMP}/laravel"
make_fake_app "${FAKE}"

ARCHIVE="${TMP}/test.tar.zst"
rip_capture_build_application_archive "${FAKE}" "${ARCHIVE}" || fail "build archive"
rip_capture_assert_archive_policy "${ARCHIVE}" || fail "archive policy"

LISTING="$(rip_capture_list_archive_paths "${ARCHIVE}")"
echo "${LISTING}" | grep -q 'app/Http/Kernel.php' || fail "archive must include application code"
echo "${LISTING}" | grep -qE '(^|/)storage/' && fail "archive must not include storage/"
echo "${LISTING}" | grep -qE '(^|/)\.env' && fail "archive must not include .env"
echo "${LISTING}" | grep -q 'vendor/' && fail "archive must not include vendor/"
echo "${LISTING}" | grep -qE '(^|/)tests/' && fail "archive must not include tests/"
echo "${LISTING}" | grep -qE '(^|/)\.cursor/' && fail "archive must not include .cursor/"
echo "${LISTING}" | grep -q 'OPERATOR-NOTES' && fail "archive must not include repo-root operator files outside allowlist"
pass "archive allowlist policy"

RIP_CAPTURE_MIN_FREE_MB=1 BASE="${FAKE}/storage/app/private/release-checkpoints"
export RIP_CAPTURE_MIN_FREE_MB
ID="RIP-211026-1200-radium-desk-test-capture-7441de0"
PHP_BIN="$(command -v php)"

bash "${REMOTE}" "${ID}" test-capture 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed "${FAKE}" "${PHP_BIN}" \
    || fail "full capture publish"

DEST="${BASE}/${ID}"
[[ -d "${DEST}" ]] || fail "checkpoint directory published"
[[ -f "${DEST}/manifest.json" ]] || fail "manifest present"
[[ -f "${DEST}/release.json.snapshot" ]] || fail "release.json.snapshot allowlisted copy present"
rip_capture_assert_archive_policy "${DEST}/desk-application.tar.zst" || fail "published archive policy"

if bash "${REMOTE}" "${ID}" test-capture 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed "${FAKE}" "${PHP_BIN}" 2>/dev/null; then
    fail "must not overwrite existing checkpoint"
fi
pass "refuse overwrite existing checkpoint"

ID2="RIP-211026-1201-radium-desk-test-fail-7441de0"
FAIL_PHP="${TMP}/fail-php.sh"
echo '#!/usr/bin/env bash' >"${FAIL_PHP}"
echo 'exit 1' >>"${FAIL_PHP}"
chmod +x "${FAIL_PHP}"

if bash "${REMOTE}" "${ID2}" test-fail 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed "${FAKE}" "${FAIL_PHP}" 2>/dev/null; then
    fail "manifest failure must abort capture"
fi
[[ ! -e "${BASE}/${ID2}" ]] || fail "failed capture must not publish checkpoint directory"
STAGING_LEFT="$(find "${BASE}" -maxdepth 1 -type d -name ".rip-staging.${ID2}.*" 2>/dev/null | wc -l | tr -d ' ')"
[[ "${STAGING_LEFT}" == "0" ]] || fail "failed capture must clean staging directory"
pass "failed capture does not publish partial checkpoint"

RIP_CAPTURE_MIN_FREE_MB=999999999
export RIP_CAPTURE_MIN_FREE_MB
ID3="RIP-211026-1202-radium-desk-test-disk-7441de0"
if bash "${REMOTE}" "${ID3}" test-disk 7441de0 7441de0d65c110de9f8c6dd11fdb419c67931a9c deployed "${FAKE}" "${PHP_BIN}" 2>/dev/null; then
    fail "disk preflight must fail when free space below threshold"
fi
pass "disk space preflight"

pass "rip-capture-remote.test.sh complete"
