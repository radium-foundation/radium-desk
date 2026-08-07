#!/usr/bin/env bash
# Read-only production CPU sampler (local → SSH). Duration default 35 minutes.
set -u
OUT="$(cd "$(dirname "$0")" && pwd)"
source /Users/ravi/radium-service-desk/tools/config.sh
DURATION_SEC="${1:-2100}"
INTERVAL_SEC="${2:-15}"

echo "start_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$OUT/sampler.meta"
echo "duration_sec=$DURATION_SEC" >> "$OUT/sampler.meta"
: > "$OUT/samples.tsv"

END=$((SECONDS + DURATION_SEC))
n=0
while [ "$SECONDS" -lt "$END" ]; do
  ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  snap=$(ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=12 -o StrictHostKeyChecking=accept-new \
    "$SSH_USER@$SSH_HOST" 'bash -s' <<'REMOTE'
set +e
load=$(cat /proc/loadavg)
lve_fail=$(head -c 800 /proc/lve/fail 2>/dev/null | tr "\n" ";")
art=$(ps -eo pcpu,cmd 2>/dev/null | awk '/artisan|lsphp:ins/ && !/awk/ {s+=$1} END {printf "%.1f", s+0}')
warm=$(ps -eo pcpu,etime,cmd 2>/dev/null | awk '/platform:snapshots:warm($| )/ && !/awk|sh -c/ {print $1","$2; exit}')
auto=$(ps -eo pcpu,etime,cmd 2>/dev/null | awk '/automation:snapshot/ && !/awk|sh -c/ {print $1","$2; exit}')
qwork=$(ps -eo pcpu,etime,cmd 2>/dev/null | awk '/queue:work/ && !/awk/ {c++; cpu+=$1; et=$2} END {if(c) print cpu","et","c}')
wd=$(ps -eo pcpu,etime,cmd 2>/dev/null | awk '/watchdog:send-critical/ && !/awk|sh -c/ {print $1","$2; exit}')
sched=$(ps -eo pcpu,etime,cmd 2>/dev/null | awk '/php artisan schedule:run/ && !/awk/ {print $1","$2; exit}')
lsphp_n=$(ps -eo cmd 2>/dev/null | grep -c 'lsphp:ins/desk.radiumbox.com' || true)
echo "load=$load	acct_cpu=$art	lsphp_n=$lsphp_n	warm=$warm	auto=$auto	qwork=$qwork	wd=$wd	sched=$sched	lve_fail=$lve_fail"
REMOTE
  ) || snap="ssh_error"
  echo "$ts	$snap" >> "$OUT/samples.tsv"
  n=$((n + 1))
  # keep a heartbeat file for local monitoring
  echo "n=$n last=$ts" > "$OUT/sampler.heartbeat"
  sleep "$INTERVAL_SEC"
done

echo "end_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$OUT/sampler.meta"
echo "samples=$n" >> "$OUT/sampler.meta"
