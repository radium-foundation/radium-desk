# RadiumBox API Success Rate Degradation — P0 Investigation

**Alert:** `RadiumBox API success rate degraded to 71.4% (24h)`  
**Alert key:** `radiumbox:degraded`  
**Investigated:** 2026-08-05 ~18:58–19:05 IST (Asia/Kolkata)  
**Environment:** Production (`desk.radiumbox.com` / `radium-desk`)  
**Constraint:** Read-only investigation. No code changes. No Canvas.

---

## Verdict

**Exact root cause of the 71.4% figure:**

1. Health “success rate (24h)” is **not** a durable rolling 24h API metric. It is a **calendar-day counter in the database cache** (`infrastructure:integration:radiumbox:stats:YYYY-MM-DD`).
2. Those counters were **reset around 18:49–18:50 IST** (evidence: `optimize:clear` / bootstrap cache rebuild at 13:19 UTC; first post-reset enrichment sync cache keys created ~18:50 IST).
3. Shortly after the reset, **two real RadiumBox API timeouts** occurred at **18:53 IST** (`cURL error 28` against `GET /api/search/order` for `RD3475408` and `RD3475412`).
4. Each timeout was recorded as `retry_scheduled`. A **probe bug double-increments `attempts` for `retry_scheduled`**.
5. Live cache at alert time: **`successes=10`, `attempts=14` → `10/14 = 71.4%`**, below threshold **80%**.

**Math identity (proven on production):**

| Quantity | Value | Meaning |
|---|---|---|
| Successes | 10 | `synced` / `synced_with_updates` after reset |
| True retries | 2 | `retry_count` in aggregate; 2 timeout jobs |
| Displayed attempts | 14 | `10 + (2 × 2)` because retries are counted twice |
| Displayed rate | **71.4%** | `round(10/14*100, 1)` |
| Corrected rate (single-count retries) | **83.3%** | `10/(10+2)` — **above** threshold |

**False positive?**

- **Partially yes for severity / labeling.** The alert is mechanically correct against the buggy thin post-reset counter, but it is **not** evidence of a day-long 71.4% API outage.
- **Partially no for underlying signal.** There were **2 genuine third-party timeouts** at 18:53 IST. They recovered on retry (~2 minutes later). Both orders are now `SYNCED`.

**Current state (19:03 IST):** rate recovered to **88.2%** on the same counter (`30/34`); **no RadiumBox critical alert active**.

---

## 1. Health pipeline (end-to-end)

```
RadiumBoxOrderEnrichmentJob (queue: critical)
  → RadiumBoxOrderEnrichmentService::process / markFailed
    → RadiumBoxClient::GET {base_url}/api/search/order?orderid=…
    → RadiumBoxIntegrationHealthProbe::recordAttempt(...)   // cache counters
  → OperationsRadiumBoxHealthService::widget()              // success_rate_24h
    → ProductionWatchdogService::radiumBoxAlerts()          // threshold check
      → IraCommunicationService::sendCriticalAlerts()
        → watchdog:send-critical-alerts (every 5 min)
          → Telegram (fingerprint-gated)
    → Platform / Operations dashboards (same widget + critical alerts)
```

| Stage | Component | Same source? |
|---|---|---|
| Scheduler recovery | `radiumbox:recover-sync` every 15 min | Dispatches jobs; does not compute rate |
| Jobs / queue | `RadiumBoxOrderEnrichmentJob` (`tries=4`, backoff `[60,300,1800]`) | Writes probe + audit |
| Health snapshot | `RadiumBoxIntegrationHealthProbe` cache keys | **Source of rate** |
| Widget | `OperationsRadiumBoxHealthService` (30s cache) | Reads probe daily stats |
| Critical alert | `ProductionWatchdogService::radiumBoxAlerts()` | Reads same widget |
| Telegram | `watchdog:send-critical-alerts` + `WatchdogCriticalAlertGate` | Same alert objects |
| Platform Integration Health card | `PlatformIntegrationHealthOverviewService::radiumBoxItem()` | Same widget (`useCache: false` on build) |

**All surfaces that show this 71.4% alert use the same counter:**  
`Cache::get('infrastructure:integration:radiumbox:stats:'.now()->format('Y-m-d'))`.

---

## 2. How success rate is calculated

**Formula** (`OperationsRadiumBoxHealthService::build`):

```
success_rate_24h = attempts > 0
  ? round((successes / attempts) * 100, 1)
  : 100.0
```

**What increments counters** (`RadiumBoxIntegrationHealthProbe::recordAttempt`):

| Result | `successes` | `attempts` | `failures` | Notes |
|---|---|---|---|---|
| `synced` / `synced_with_updates` | +1 | +1 | — | Includes **order-not-found** (marked SYNCED) |
| `retry_scheduled` | — | **+2 (bug)** | — | +1 in retry branch, +1 again in shared attempts branch |
| `failed` | — | **not incremented** | +1 | Exhausted retries only; excluded from rate denominator |
| `skipped` | — | — | — | No counter update |

**Threshold:** `config('ira.watchdog.radiumbox_min_success_rate')` = **80** (`IRA_WATCHDOG_RADIUMBOX_MIN_SUCCESS_RATE`).

**Alert conditions** (`radiumbox:degraded`):

1. `failed_syncs == 0` (else `radiumbox:sync_failures` wins instead)
2. Integration enabled
3. `sync_attempts_24h > 0`
4. `success_rate_24h < 80`

At alert time: `failed_syncs=0`, `pending_syncs=0`, `success_rate_24h=71.4` → `radiumbox:degraded`.

---

## 3. Time window / rolling vs calendar / cached vs live

| Claim in UI | Actual behavior |
|---|---|
| “(24h)” | **False label.** Key is **calendar day** `Y-m-d` in `Asia/Kolkata`. TTL = `now()->endOfDay()`. |
| Rolling 24h | **No.** Not `now()->subHours(24)`. |
| Live | **No durable live query.** Counters are **cache increments**. |
| Widget cache | Secondary **30s** cache: `operations:radiumbox-health`. |
| Volatility | Any `cache:clear` / `optimize:clear` / DB cache wipe **zeros the day’s rate history**. |

Production confirmation:

- Key: `radium-desk-cache-infrastructure:integration:radiumbox:stats:2026-08-05`
- Expiration: `2026-08-06T00:00:00+05:30`
- At first read: `{"successes":10,"attempts":14}` exactly matching 71.4%
- Yesterday’s key: `null` (expected; prior day expired / wiped)

---

## 4. Data — API activity

### 4.1 Metric the alert used (post-reset thin sample)

Captured ~18:58 IST:

| Metric | Count |
|---|---|
| Probe successes | 10 |
| Probe attempts (displayed) | 14 |
| Probe failures key | absent / 0 |
| Aggregate `retry_count` | 2 |
| Response-time samples | 12 (= 10 success + 2 retry recordings) |
| Avg latency (samples) | ~2022 ms |
| Manual retries | 0 |
| Pending syncs (orders) | 0 |
| Failed syncs (orders) | 0 |
| Displayed success rate | **71.4%** |

### 4.2 Durable audit — calendar day 2026-08-05 IST

| Event | Count |
|---|---|
| `radiumbox.enrichment_started` | 1652+ (grew during investigation) |
| `radiumbox.enrichment_completed` | 1432 → 1442 |
| `radiumbox.enrichment_failed` | 218 → 227 |
| `radiumbox.sync.scheduler_recovery` | 587+ |

Rolling 24h audit (approx): started 1940 / completed 1659 / failed 279 / recoveries 766.

**Important semantics:** audit `enrichment_failed` for “Order not found” is **not** an API outage. Code marks those orders **SYNCED** and the probe records them as **`synced` (success)**.

### 4.3 Requested status / failure classes (calendar day, durable sources)

| Class | Count | Source / notes |
|---|---|---|
| Total enrichment starts (proxy for API calls) | ~1652–1940 | Audit `enrichment_started` |
| Successful enrichment completed | ~1442 | Audit completed |
| Audit “failed” | 227 | **100% order-not-found** |
| Timed out (API) | **2** | laravel.log `cURL error 28` at 18:53; probe `retry_scheduled` |
| Skipped | 0 observed in alert window | Would log `result=skipped` if order row missing |
| Cancelled | 0 | Not a RadiumBox outcome |
| Rejected | 0 | Not observed |
| 429 / rate limit | **0 today** | No “Too Many” / 429 in today’s failure set |
| 401 | 0 | Not observed |
| 403 | 0 | Not observed |
| 404 (business not found) | **227** | Message `RD Order not found` / `Order not found`; treated as SYNCED success in probe |
| 500 | 0 | Not observed |
| 503 | 0 | Not observed |
| Connection failures | 0 beyond timeouts | — |
| DNS | 0 | Not observed |
| SSL | 0 | Not observed |
| Timeout | **2** | `cURL error 28` after **5002 ms** (`RADIUMBOX_TIMEOUT_SECONDS=5`) |
| Rate limit | 0 today | — |
| Terminal `failed` sync status now | **0** | Orders: `SYNCED=20783`, `PENDING=1`, `NOT_SYNCED=5721`, `FAILED=0` |

HTTP categories 401/403/500/503/429: **none** in today’s enrichment_failed audit breakdown.

---

## 5. Failure breakdown (ranked)

### 5.1 What drove the 71.4% alert (probe outcomes after reset)

| Rank | Reason | Count | % of non-success probe outcomes | Effect on rate |
|---|---|---|---|---|
| 1 | API timeout → `retry_scheduled` (double-counted) | 2 | 100% of real non-successes | Adds **+4** to `attempts` |

There were **no** probe `failures` in the stats payload at alert time.

### 5.2 Durable audit failures (full calendar day) — not what the alert used

| Rank | Reason | Count | % of audit failures |
|---|---|---|---|
| 1 | `RD Order not found` | 213 | 93.8% |
| 2 | `Order not found` | 14 | 6.2% |
| — | Timeout / HTTP / 429 / auth | 0 | 0.0% |

All audit failures were **attempt = 1**. These are **business-data misses** (order ID absent in RadiumBox), not infrastructure degradation. They do **not** reduce `success_rate_24h` because the probe counts them as successes after `markSynced`.

---

## 6. Endpoint analysis

| Item | Value |
|---|---|
| Endpoint | `GET https://admin.radiumbox.com/api/search/order?orderid={orderId}` |
| Only RadiumBox lookup used by enrichment | Yes (single endpoint) |
| Connect timeout | 3s |
| Request timeout | 5s |
| Top failing endpoint | Same endpoint — timeouts only (2) |
| Success rate (probe, alert time) | 71.4% (buggy denominator) |
| Success rate (probe, corrected) | 83.3% |
| Success rate (probe, ~19:03) | 88.2% displayed / 93.8% corrected |
| Avg latency (sample window) | ~1.5–2.0s typical; timeouts ~5.0–5.04s; one later sample 8.2s |
| Retry policy | Job `tries=4`, backoff 60s / 300s / 1800s |
| Observed retries for timeout orders | attempt 1 fail → attempt 2 success ~2 min later |

**Timeout orders:**

| Order | Attempt 1 | Attempt 2 | Final status | Serial |
|---|---|---|---|---|
| `RD3475408` | 18:53:02 start → timeout | 18:55:03 completed | `SYNCED` | `7794854` |
| `RD3475412` | 18:53:07 start → timeout | 18:55:04 completed | `SYNCED` | null |

---

## 7. Timeline (last 24h / calendar day)

### 7.1 Hour-by-hour audit completion rate (IST)

Completion rate = `completed / (completed + failed)` where failed ≈ order-not-found.

| Hour | Started | Completed | Failed | Completion % | State |
|---|---:|---:|---:|---:|---|
| 00 | 19 | 10 | 9 | 52.6 | Low volume / recovery noise |
| 01 | 21 | 18 | 3 | 85.7 | Healthy |
| 02 | 22 | 9 | 13 | 40.9 | Low volume; not-found heavy |
| 03 | 27 | 14 | 13 | 51.9 | Low volume |
| 04 | 15 | 9 | 6 | 60.0 | Low volume |
| 05 | 22 | 15 | 7 | 68.2 | Low volume |
| 06 | 20 | 13 | 7 | 65.0 | Low volume |
| 07 | 38 | 32 | 6 | 84.2 | Healthy |
| 08 | 87 | 78 | 9 | 89.7 | Healthy |
| 09 | 138 | 125 | 13 | 90.6 | Healthy |
| 10 | 159 | 142 | 17 | 89.3 | Healthy |
| 11 | 202 | 190 | 12 | 94.1 | Healthy |
| 12 | 164 | 146 | 18 | 89.0 | Healthy |
| 13 | 153 | 141 | 12 | 92.2 | Healthy |
| 14 | 116 | 104 | 12 | 89.7 | Healthy |
| 15 | 114 | 92 | 22 | 80.7 | Healthy (borderline on audit semantics) |
| 16 | 109 | 99 | 10 | 90.8 | Healthy |
| 17 | 116 | 101 | 15 | 87.1 | Healthy |
| 18 | 109 | 93 | 14 | 86.9 | Healthy overall; **timeout spike 18:53** |
| 19 | partial | — | — | recovering | Probe alert window |

### 7.2 Alert-specific sequence

```
~18:49 IST  Cache / optimize clear rebuilds bootstrap cache; health counters reset
~18:50 IST  Fresh enrichment sync cache keys begin
18:53:02    RD3475408 lookup starts
18:53:07    Timeout (cURL 28) → retry_scheduled (+2 attempts bug)
18:53:07    RD3475412 lookup starts
18:53:12    Timeout (cURL 28) → retry_scheduled (+2 attempts bug)
18:55:03/04 Both orders succeed on retry → SYNCED
~18:58 IST  Cache shows successes=10 attempts=14 → 71.4%
            Watchdog emits radiumbox:degraded
            Telegram fingerprint set (watchdog:critical-fingerprint:radiumbox:degraded)
~19:00+     More successes accumulate; displayed rate rises to 88.2%
~19:03 IST  collectCriticalAlerts() → NO RadiumBox alert
            Watchdog log shows recent "1 critical alert(s) delivered" then recovery path
```

**Pattern:** Healthy day (business not-found only) → **Drop** (cache reset + 2 timeouts + double-count) → **Recovery** (retries succeed; rate climbs above 80%).

---

## 8. Cache determination for “71.4%”

| Question | Answer |
|---|---|
| Live DB aggregate over 24h? | **No** |
| Cached? | **Yes** — database cache store |
| Stale widget? | Widget TTL 30s; value matched live rebuild |
| Snapshot? | Calendar-day running counter, not a frozen snapshot file |
| Rolling? | **No** — calendar day |
| Why so few attempts vs thousands of audits? | Counters were **wiped**; only post-wipe `recordAttempt` calls remain |
| Reproducible identity | `10/14 = 71.428… → 71.4` |

---

## 9. Root cause classification

| Category | Applies? | Detail |
|---|---|---|
| **Application (metric bug)** | **Yes — primary for 71.4%** | `retry_scheduled` double-counts `attempts`; “24h” mislabeled; failures excluded from denominator; counters non-durable |
| **Configuration / ops** | **Yes — trigger** | Cache clear (`optimize:clear` evidence ~18:49 IST) zeroed the day counter |
| **Third-party / network** | **Yes — secondary real signal** | 2× RadiumBox API timeouts (`admin.radiumbox.com`, cURL 28 @ 5s) |
| Infrastructure (our hosts) | No evidence of local outage | Queue processed jobs; recovery continued |
| Business data | Parallel noise | 227 order-not-found; **not** what produced 71.4% |
| Regression | Metric bug is longstanding code behavior | Exposed by thin post-clear sample |

**Exact causal chain:**

`cache wipe` → thin sample → `2 API timeouts` → `retry_scheduled` → **double-counted attempts** → `10/14=71.4%` → `<80` → Critical Alert.

---

## 10. Customer / operations impact

| Area | Impact |
|---|---|
| Orders `RD3475408`, `RD3475412` | Enrichment delayed ~2 minutes; both recovered `SYNCED` |
| Serial lookup / warranty fields | `RD3475408` got serial `7794854` on retry; `RD3475412` synced with null serial (RadiumBox had no serial) |
| Customer360 / automation | No stuck `FAILED` syncs; `failed_syncs=0` |
| Email / refunds | No linkage found to this alert |
| Broad outage | **No.** Day remained operational; alert reflected thin buggy counter + 2 timeouts |

Current order sync inventory: **0 failed**, **1 pending**, vast majority synced/not-synced as steady state.

---

## 11. False positive determination

| Question | Answer |
|---|---|
| Did Platform Health correctly evaluate its configured formula? | **Yes** — `10/14 < 80` |
| Is “API success rate degraded to 71.4% (24h)” an accurate description of production API health? | **No** |
| Was there any real API problem? | **Yes** — 2 timeouts at 18:53 IST |
| Would a correct single-count / durable metric have alerted? | **No** at that moment (`10/12=83.3% ≥ 80`); full-day API timeouts are 2 events, not a 28.6-point degradation |
| Classification | **Metric false positive amplified by a real brief timeout blip** |

---

## 12. Minimal safe fix (recommendation only — do not implement here)

1. **Stop double-counting `retry_scheduled`** in `RadiumBoxIntegrationHealthProbe::recordAttempt` (remove the extra `attempts` increment in the retry branch **or** from the shared list — keep exactly one).
2. **Derive the dashboard/watchdog rate from durable audit (or job logs) over a true rolling 24h**, not a wipeable calendar-day cache counter — **or** persist counters outside cache.
3. **Relabel UI/alert text** from `(24h)` to the real window (`today` / `since midnight IST`) until rolling 24h exists.
4. **Optional alert hygiene:** require a minimum attempts floor (e.g. ≥ 50) before `radiumbox:degraded`, so post-clear thin samples cannot page.
5. **Do not** treat order-not-found as API degradation (already excluded from probe failures; keep it that way, but avoid calling the metric “API success rate” if it includes business not-found as success).

No production code was changed in this investigation.

---

## 13. Evidence index

| Evidence | Location / value |
|---|---|
| Alert message builder | `app/Services/Operations/ProductionWatchdogService.php` (`radiumbox:degraded`) |
| Rate formula | `app/Services/Operations/OperationsRadiumBoxHealthService.php` |
| Counter bug | `app/Infrastructure/IntegrationHealth/Probes/RadiumBoxIntegrationHealthProbe.php` `recordAttempt()` |
| Threshold | `config/ira.php` → `watchdog.radiumbox_min_success_rate` = 80 |
| Endpoint / timeouts | `config/radiumbox.php`; `RadiumBoxClient` |
| Prod stats at alert | `successes=10 attempts=14` |
| Prod timeouts | `storage/logs/laravel.log` 18:53:07 / 18:53:12 IST |
| Queue FAIL/DONE | `storage/logs/queue-worker.log` |
| Fingerprint | cache key `watchdog:critical-fingerprint:radiumbox:degraded` |
| Cache wipe timing | `bootstrap/cache/*` mtime 13:19 UTC; sync keys from ~18:50 IST |

---

## 14. Bottom line

The Critical Alert was raised because a **wiped calendar-day cache counter**, after **two RadiumBox timeouts**, was distorted by a **double-count bug** to **71.4%**. That is **not** a day-long RadiumBox outage. Real impact was a **~2 minute enrichment delay** for two orders, both recovered. Fix the probe math and stop using wipeable cache as a 24h SLO before trusting this alert again.
