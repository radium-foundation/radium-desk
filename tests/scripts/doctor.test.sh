#!/usr/bin/env bash
#
# Static checks for KVM-aware desk doctor (no production SSH).
#
# Run: bash tests/scripts/doctor.test.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCTOR="$ROOT/tools/commands/doctor.sh"
LIB="$ROOT/tools/lib.sh"
FIXTURES="$ROOT/tests/scripts/fixtures/doctor-mocks"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DOCTOR" ]] || fail "doctor.sh missing"
[[ -f "$LIB" ]] || fail "lib.sh missing"
bash -n "$DOCTOR" || fail "doctor.sh syntax check failed"
bash -n "$LIB" || fail "lib.sh syntax check failed"
pass "doctor.sh and lib.sh syntax valid"

grep -q 'DEPLOY_MODE' "$DOCTOR" || fail "doctor must branch on DEPLOY_MODE"
grep -q 'kvm_doctor_check_laravel_public' "$DOCTOR" || fail "doctor must call kvm_doctor_check_laravel_public"
grep -q 'kvm_doctor_check_remote_manifest' "$DOCTOR" || fail "doctor must call kvm_doctor_check_remote_manifest"
grep -q 'kvm_doctor_check_up_endpoint' "$DOCTOR" || fail "doctor must call kvm_doctor_check_up_endpoint"
grep -q 'kvm_doctor_check_supervisor_program' "$DOCTOR" || fail "doctor must call kvm_doctor_check_supervisor_program"
grep -q 'kvm_doctor_check_redis' "$DOCTOR" || fail "doctor must call kvm_doctor_check_redis"
grep -q 'legacy_doctor_check_vite_manifest_parity' "$DOCTOR" || fail "doctor must call legacy_doctor_check_vite_manifest_parity"
grep -q 'legacy_doctor_check_shared_hosting_index' "$DOCTOR" || fail "doctor must call legacy_doctor_check_shared_hosting_index"

if awk '/if \[\[ "\$DEPLOY_MODE" == "kvm" \]\]; then/,/else/' "$DOCTOR" | grep -q 'LEGACY_REMOTE_PUBLIC'; then
    fail "KVM doctor branch must not reference LEGACY_REMOTE_PUBLIC"
fi

if awk '/else$/,/fi$/' "$DOCTOR" | grep -q 'kvm_doctor_check_'; then
    fail "legacy doctor branch must not call kvm_doctor_check_* helpers"
fi

grep -q 'LEGACY_REMOTE_PUBLIC' "$LIB" || fail "lib must define legacy manifest parity using LEGACY_REMOTE_PUBLIC"
grep -q 'kvm_doctor_check_redis' "$LIB" || fail "lib must define kvm_doctor_check_redis"
grep -q 'Redis::connection()->ping()' "$LIB" || fail "redis check must use Laravel Redis connection ping"
grep -q 'SUPERVISOR_PROGRAM' "$LIB" || fail "lib must use SUPERVISOR_PROGRAM for supervisor check"

pass "doctor KVM/legacy branching guards present"

# --- Redis check: throw-on-failure, not tinker exit() ---

REDIS_FN="$(awk '/^kvm_doctor_check_redis\(\)/,/^}/' "$LIB")"
echo "$REDIS_FN" | grep -q 'Redis::connection()->ping()' || fail "redis check must ping Laravel Redis connection"
echo "$REDIS_FN" | grep -q 'throw new RuntimeException' || fail "redis check must throw on ping failure"
if echo "$REDIS_FN" | grep -q 'exit('; then
    fail "redis check must not use tinker exit() (false negative on production)"
fi
pass "redis check uses throw-on-failure, not tinker exit()"

# --- mocked execution: KVM path skips legacy checks ---

MOCK_BIN="$FIXTURES/bin"
mkdir -p "$MOCK_BIN"

cat > "$MOCK_BIN/ssh" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "echo ok" ]]; then
    exit 0
fi
if [[ "$*" == *"PHP_VERSION"* ]]; then
    echo "8.4.0"
    exit 0
fi
if [[ "$*" == *"composer"* && "$*" == *"--version"* ]]; then
    exit 0
fi
if [[ "$*" == *"test -d"* && "$*" == *"artisan"* ]]; then
    exit 0
fi
if [[ "$*" == *"test -w"* ]]; then
    exit 0
fi
if [[ "$*" == *"APP_ENV="* ]]; then
    echo "production"
    exit 0
fi
if [[ "$*" == *"APP_URL="* ]]; then
    echo "https://desk.example.test"
    exit 0
fi
if [[ "$*" == *"manifest.json"* ]]; then
    exit 0
fi
if [[ "$*" == *"index.php"* ]]; then
    exit 0
fi
if [[ "$*" == *"/up"* ]]; then
    echo "200"
    exit 0
fi
if [[ "$*" == *"supervisorctl status"* ]]; then
    echo "radium-desk-queue-worker    RUNNING   pid 1, uptime 0:01:00"
    exit 0
fi
if [[ "$*" == *"curl"* ]]; then
    echo "200"
    exit 0
fi
exit 0
EOF
chmod +x "$MOCK_BIN/ssh"

cat > "$MOCK_BIN/php" <<'EOF'
#!/usr/bin/env bash
if [[ "$1" == "-r" && "$2" == *"PHP_VERSION"* ]]; then
    echo "8.4.0"
    exit 0
fi
if [[ "$1" == *"artisan"* || "${@: -2:1}" == "artisan" ]]; then
  for arg in "$@"; do
    if [[ "$arg" == "db:show" ]]; then
      exit 0
    fi
    if [[ "$arg" == *"Redis::connection()->ping()"* ]]; then
      exit 0
    fi
  done
fi
if [[ "$*" == *"artisan"* && "$*" == *"tinker"* ]]; then
    exit 0
fi
exit 0
EOF
chmod +x "$MOCK_BIN/php"

export PATH="$MOCK_BIN:$PATH"
export SSH_HOST=mock-host
export SSH_PORT=22
export SSH_USER=mock
export REMOTE_PROJECT=/var/www/radium-desk
export REMOTE_PUBLIC=/var/www/radium-desk/public
export PHP_BIN=php
export COMPOSER_BIN=composer
export SUPERVISOR_PROGRAM=radium-desk-queue-worker
export DEPLOY_MODE=kvm

MOCK_PROJECT="$FIXTURES/project"
mkdir -p "$MOCK_PROJECT/public/build"
echo '{}' > "$MOCK_PROJECT/public/build/manifest.json"

# Run doctor with mocked PATH; override PROJECT_ROOT via symlink layout.
DOCTOR_OUTPUT="$(
    cd "$MOCK_PROJECT" && \
    bash -c '
        DOCTOR_SCRIPT="'"$DOCTOR"'"
        SCRIPT_DIR="$(cd "$(dirname "$DOCTOR_SCRIPT")" && pwd)"
        PROJECT_ROOT="'"$MOCK_PROJECT"'"
        DEPLOY_MODE=kvm
        # shellcheck source=tools/lib.sh
        source "'"$LIB"'"
        failures=0
        check() { "$@" >/dev/null 2>&1 || failures=$((failures + 1)); }
        check ssh_exec "echo ok" >/dev/null
        check kvm_doctor_check_laravel_public
        check kvm_doctor_check_remote_manifest
        check kvm_doctor_check_up_endpoint
        check kvm_doctor_check_supervisor_program
        check kvm_doctor_check_redis
        echo "failures=${failures}"
    '
)"

if [[ "$DOCTOR_OUTPUT" != "failures=0" ]]; then
    fail "mocked KVM doctor helpers failed: ${DOCTOR_OUTPUT}"
fi

pass "mocked KVM doctor helper checks succeed"

# --- Redis check exit codes via mocked remote artisan ---

cat > "$MOCK_BIN/php-redis" <<'EOF'
#!/usr/bin/env bash
if [[ "$*" == *"artisan"* && "$*" == *"tinker"* ]]; then
    if [[ "$*" == *"exit("* && "$*" == *"Redis::connection()->ping()"* ]]; then
        exit 1
    fi
    exit 0
fi
exit 0
EOF
chmod +x "$MOCK_BIN/php-redis"

run_mocked_redis_check() {
    local php_bin="$1"
    local expected_exit="$2"
    local label="$3"
    local actual_exit=0

    (
        export PATH="$MOCK_BIN:$PATH"
        export SSH_HOST=mock-host SSH_PORT=22 SSH_USER=mock

        # shellcheck source=tools/lib.sh
        source "$LIB"

        export REMOTE_PROJECT="$MOCK_PROJECT" PHP_BIN="$php_bin" COMPOSER_BIN=composer

        ssh_exec() {
            if [[ "$*" == *"Redis::connection()->ping()"* ]]; then
                "$MOCK_BIN/$PHP_BIN" artisan tinker --execute="mock-redis-check"
                return $?
            fi
            bash -c "$*"
        }

        if kvm_doctor_check_redis; then
            actual_exit=0
        else
            actual_exit=1
        fi

        exit "$actual_exit"
    ) && actual_exit=0 || actual_exit=1

    if [[ "$actual_exit" -ne "$expected_exit" ]]; then
        fail "${label}: expected exit ${expected_exit}, got ${actual_exit}"
    fi
}

run_mocked_redis_check "php-redis" 0 "successful Redis ping"
pass "successful Redis ping passes kvm_doctor_check_redis"

cat > "$MOCK_BIN/php-redis-fail" <<'EOF'
#!/usr/bin/env bash
if [[ "$*" == *"artisan"* && "$*" == *"tinker"* ]]; then
    exit 1
fi
exit 0
EOF
chmod +x "$MOCK_BIN/php-redis-fail"

run_mocked_redis_check "php-redis-fail" 1 "failed Redis ping"
pass "failed Redis ping fails kvm_doctor_check_redis"

# Legacy parity helper must use LEGACY_REMOTE_PUBLIC, not REMOTE_PUBLIC.
if ! grep -q 'remote_public_manifest="${LEGACY_REMOTE_PUBLIC}/build/manifest.json"' "$LIB"; then
    fail "legacy manifest parity must target LEGACY_REMOTE_PUBLIC"
fi

pass "legacy manifest parity targets LEGACY_REMOTE_PUBLIC"

echo "All doctor static checks passed."
