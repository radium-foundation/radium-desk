#!/usr/bin/env bash
#
# Validate isolated Desk UAT reservation flows without printing secrets.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/lib.sh
source "$SCRIPT_DIR/../lib.sh"

UAT_REMOTE_PROJECT="/var/www/radium-desk-uat"
UAT_HOSTNAME="desk-uat.radiumbox.com"
PROD_REMOTE_PROJECT="/var/www/radium-desk"

ssh_uat() {
    ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=30 "${SSH_USER}@${SSH_HOST}" "$@"
}

print_warning "Validating Desk UAT lane (secrets not printed)..."

ssh_uat bash -s <<'EOVAL'
set -euo pipefail
UAT_ROOT="/var/www/radium-desk-uat"
PROD_ROOT="/var/www/radium-desk"
HOST="desk-uat.radiumbox.com"
PHP="/usr/local/lsws/lsphp84/bin/php"
TOKEN=$(cat "${UAT_ROOT}/.uat-integration-token")
AUTH=( -H "Authorization: Bearer ${TOKEN}" -H "X-Site-Code: radiumbox.com" -H "Content-Type: application/json" )
BASE="http://127.0.0.1/api/central-wallet/v1"

fail() { echo "VALIDATION_FAIL: $1"; exit 1; }

RUN_ID=$(date +%s)

echo "== UAT config =="
grep -E '^(APP_ENV|CENTRAL_WALLET_RESERVATIONS_ENABLED|DB_DATABASE)=' "${UAT_ROOT}/.env" | sed 's/=.*$/=***redacted***/'

echo "== Production reservation flag =="
if grep -q '^CENTRAL_WALLET_RESERVATIONS_ENABLED=true' "${PROD_ROOT}/.env" 2>/dev/null; then
  fail "production reservations flag is true"
fi
echo "production_reservations_flag=off_or_unset"

echo "== Production counts (unchanged baseline) =="
$PHP "${PROD_ROOT}/artisan" tinker --execute='echo "prod_res=".DB::table("central_wallet_reservations")->count()." prod_ledger=".DB::table("central_wallet_ledger_entries")->count();'

echo "== UAT health =="
code=$(curl -sS -o /tmp/desk-uat-health.json -w '%{http_code}' -H "Host: ${HOST}" "${AUTH[@]}" "${BASE}/health")
[[ "$code" == "200" ]] || fail "health http ${code}"
grep -q '"status"' /tmp/desk-uat-health.json || fail "health body"

echo "== UAT login page =="
login_code=$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${HOST}" "http://127.0.0.1/login")
[[ "$login_code" == "200" || "$login_code" == "302" ]] || fail "login http ${login_code}"

echo "== Create synthetic wallet + credit =="
wallet_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-seed-wallet-${RUN_ID}\"}" "${BASE}/wallets")
cwid=$(printf '%s' "$wallet_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["central_wallet_id"] ?? "";')
[[ -n "$cwid" ]] || fail "wallet create"
echo "uat_cwid_prefix=${cwid:0:8}..."

curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-seed-credit-${RUN_ID}\",\"entry_type\":\"credit\",\"amount\":\"500.00\",\"source_system\":\"radiumbox.com\"}" \
  "${BASE}/wallets/${cwid}/ledger-entries" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); exit(isset($j["ledger_entry_id"])||isset($j["entry_type"])?0:1);' \
  || fail "credit"

UAT_LOCAL_USER="uat-${RUN_ID}"
echo "== Account link (synthetic user ${UAT_LOCAL_USER}) =="
link_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-seed-link-${RUN_ID}\",\"central_wallet_id\":\"${cwid}\",\"site_code\":\"radiumbox.com\",\"local_user_id\":\"${UAT_LOCAL_USER}\",\"verification_method\":\"uat_synthetic\",\"created_by\":\"desk-uat-provisioner\"}" "${BASE}/account-links")
link_id=$(printf '%s' "$link_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["link_id"] ?? "";')
[[ -n "$link_id" ]] || fail "account link create"
curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-seed-link-confirm-${RUN_ID}\",\"verification_method\":\"uat_synthetic\",\"actor_id\":\"desk-uat-provisioner\"}" \
  "${BASE}/account-links/${link_id}/confirm" >/dev/null || fail "account link confirm"

bal_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" "${BASE}/wallets/${cwid}/balance")
avail=$(printf '%s' "$bal_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["available_balance"] ?? "";')
[[ "$avail" == "500.00" ]] || fail "balance after credit (${avail})"

echo "== Reservation create (hold) =="
res_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-reserve-1-${RUN_ID}\",\"central_wallet_id\":\"${cwid}\",\"amount\":\"75.00\",\"business_reference\":\"uat-order:1001\"}" "${BASE}/wallet-reservations")
res_id=$(printf '%s' "$res_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["reservation_id"] ?? "";')
[[ -n "$res_id" ]] || fail "reservation create"
bal_hold=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" "${BASE}/wallets/${cwid}/balance")
$PHP -r '$j=json_decode(stream_get_contents(STDIN), true); exit(($j["available_balance"]??"")==="425.00" && ($j["reserved_balance"]??"")==="75.00"?0:1);' <<<"$bal_hold" || fail "hold balance"

echo "== Reservation release (no debit) =="
curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-release-1-${RUN_ID}\"}" "${BASE}/wallet-reservations/${res_id}/release" >/dev/null || fail "release"
bal_release=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" "${BASE}/wallets/${cwid}/balance")
$PHP -r '$j=json_decode(stream_get_contents(STDIN), true); exit(($j["available_balance"]??"")==="500.00" && ($j["reserved_balance"]??"")==="0.00" && ($j["ledger_balance"]??"")==="500.00"?0:1);' <<<"$bal_release" || fail "debit after release"

echo "== Reservation commit on separate hold =="
res2_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-reserve-2-${RUN_ID}\",\"central_wallet_id\":\"${cwid}\",\"amount\":\"25.00\",\"business_reference\":\"uat-order:1002\"}" "${BASE}/wallet-reservations")
res2_id=$(printf '%s' "$res2_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["reservation_id"] ?? "";')
curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-commit-1-${RUN_ID}\"}" "${BASE}/wallet-reservations/${res2_id}/commit" >/dev/null || fail "commit"
bal_commit=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" "${BASE}/wallets/${cwid}/balance")
$PHP -r '$j=json_decode(stream_get_contents(STDIN), true); exit(($j["ledger_balance"]??"")==="475.00"?0:1);' <<<"$bal_commit" || fail "balance after commit"

echo "== Expiry behavior =="
res3_json=$(curl -sS "${AUTH[@]}" -H "Host: ${HOST}" -d "{\"idempotency_key\":\"uat-reserve-3-${RUN_ID}\",\"central_wallet_id\":\"${cwid}\",\"amount\":\"10.00\",\"business_reference\":\"uat-order:1003\"}" "${BASE}/wallet-reservations")
res3_id=$(printf '%s' "$res3_json" | $PHP -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["reservation_id"] ?? "";')
$PHP "${UAT_ROOT}/artisan" tinker --execute="DB::table('central_wallet_reservations')->where('id', '${res3_id}')->update(['expires_at' => now()->subMinute()]);"
$PHP "${UAT_ROOT}/artisan" tinker --execute="(new App\\CentralWallet\\Infrastructure\\Jobs\\ExpireActiveReservationsJob)->handle(app(App\\CentralWallet\\Application\\ReservationService::class));"
exp_state=$($PHP "${UAT_ROOT}/artisan" tinker --execute="echo DB::table('central_wallet_reservations')->where('id', '${res3_id}')->value('state');")
[[ "$exp_state" == "expired" ]] || fail "expiry state (${exp_state})"

echo "== UAT DB counts =="
$PHP "${UAT_ROOT}/artisan" tinker --execute='echo "uat_res=".DB::table("central_wallet_reservations")->count()." uat_ledger=".DB::table("central_wallet_ledger_entries")->count()." uat_wallets=".DB::table("central_wallets")->count();'

echo "VALIDATION_OK"
EOVAL

print_success "Desk UAT validation completed"
