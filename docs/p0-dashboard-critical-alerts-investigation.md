# P0 Investigation — Dashboard Critical Alerts

**Date:** 2026-08-04  
**Priority:** P0 production (read-only)  
**Status:** Root causes proven · no production changes made  
**Captured:** 2026-08-04 18:55 IST  
**Prod HEAD:** `1c0bbd64`  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-dashboard-critical-alerts-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-dashboard-critical-alerts-investigation.canvas.tsx)

---

## Root Cause Summary

Two **independent** issues. Neither was introduced by today's dashboard / workspace / profile deploys.

| Alert | Count | Root cause |
|-------|-------|------------|
| Automation | 8 | Missing-serial automation restarts waiting on **already closed** service cases; later `auto_close` fails with `Service case is already closed.` |
| Queue (dedicated_cron) | 1 | `RadiumBoxOrderEnrichmentJob` for order **RD3473215** failed: RadiumBox returned a **108-char padded serial** exceeding `orders.serial_number` varchar(100) |

**Shared root cause?** No.  
**Today's deploy caused it?** No.

---

## Issue 1 — Automation (8 failures)

### Identity

| Field | Value |
|-------|-------|
| Automation / policy | `customer_waiting_default` |
| Trigger | Scheduler / customer-waiting auto-close cutoff (~18:00 IST) |
| Action | `auto_close` / `customer_not_responding` |
| Channel | none |
| Exception | `Service case is already closed.` (business `ActionHandlerResult`, not a PHP throw) |
| Retry count | 0 (ledger records Failed once per execution) |
| Same root cause for all 8? | **Yes** |

### Failure inventory

| Exec | Waiting | Case | Incident | Order DB | Created (IST) | Failed (IST) |
|------|---------|------|----------|----------|---------------|--------------|
| 2241 | 1842 | SC23144 | 23225 | 22791 | 2026-08-04 00:00:49 | 2026-08-04 18:00:54 |
| 2259 | 1845 | SC23190 | 23271 | 22837 | 2026-08-04 10:00:53 | 2026-08-04 18:00:54 |
| 2260 | 1847 | SC23205 | 23286 | 22852 | 2026-08-04 10:00:53 | 2026-08-04 18:01:09 |
| 2270 | 1865 | SC23319 | 23400 | 22966 | 2026-08-04 12:01:00 | 2026-08-04 18:02:50 |
| 2271 | 1878 | SC23380 | 23461 | 23027 | 2026-08-04 13:00:50 | 2026-08-04 18:03:24 |
| 2272 | 1880 | SC23400 | 23481 | 23047 | 2026-08-04 13:00:50 | 2026-08-04 18:03:35 |
| 2274 | 1885 | SC23429 | 23510 | 23076 | 2026-08-04 14:00:49 | 2026-08-04 18:03:46 |
| 2283 | 1900 | SC23516 | 23597 | 23163 | 2026-08-04 16:00:52 | 2026-08-04 18:05:03 |

### Proven chain (SC23144 / waiting 1842)

| Step | When (DB local) | What |
|------|-----------------|------|
| 1 | 2026-08-01 23:30:27 | Waiting #1792 started (`serial_number`) |
| 2 | 2026-08-02 15:04:39 | Agent closed case → waiting #1792 **cleared** (correct) |
| 3 | 2026-08-02 23:30:38 | WhatsApp + `customer_waiting_started` → **new** waiting #1842 on **closed** case |
| 4 | 2026-08-04 18:00:54 IST | `auto_close` fails: already closed. Waiting #1842 still uncleared |

### Why waiting restarts on closed cases

1. `MissingSerialAutomationService::resolveIncident` uses `activeIncident() ?? latestIncident()`.
2. After close, no active incident → falls back to the **closed** latest case.
3. Candidate query filters `orders.status = active` + missing serial, but does **not** require an open service case.
4. On successful contact it calls `ensureSerialWaitingState`.
5. `IncidentWaitingStateService::start` has **no** Closed-case guard.
6. `CustomerWaitingLifecycleService::autoCloseForNoResponse` returns failure for already-closed and **does not clear** the orphan waiting state.

**Current orphan count:** 34 active waiting rows on closed incidents.

### Classification

| Question | Answer |
|----------|--------|
| Same root cause for all 8? | Yes |
| Code issue? | Yes — missing closed-case guards + fail-not-clear |
| Data issue? | Yes — orphan waiting rows; cases already closed |
| External API? | No |
| Retryable as-is? | No — would fail again until waiting cleared |

### Historical “already closed” (by created day IST)

| Day | Count |
|-----|-------|
| 2026-07-10 | 26 |
| 2026-07-11 | 2 |
| 2026-07-19 | 1 |
| 2026-07-20 | 1 |
| 2026-07-23 | 3 |
| 2026-07-24 | 1 |
| 2026-07-26 | 2 |
| 2026-07-29 | 1 |
| 2026-07-30 | 1 |
| 2026-07-31 | 4 |
| 2026-08-02 | 4 |
| 2026-08-03 | 2 |
| **2026-08-04** | **8** |

First seen: automation execution #564 (2026-07-10). Recurring — not a new regression from today's deploy.

### Severity / user impact

- **Severity:** Medium (Critical Alerts noise + customer re-contact on closed cases).
- **Not** a queue/worker outage.
- Today automation ledger: **64 success · 8 failed**.

---

## Issue 2 — Queue dead-letter (1 job)

### Identity

| Field | Value |
|-------|-------|
| failed_jobs.id | 5 |
| uuid | `231c15b7-1d7c-480d-adb1-d6ee0315479a` |
| Job class | `App\Jobs\RadiumBoxOrderEnrichmentJob` |
| Payload | `orderId: 25224` (RD3473215) |
| Queue | `critical` |
| Connection | `database` |
| Enqueued | 2026-08-04 15:03:06 IST |
| Failed at | 2026-08-04 15:41:02 IST |
| maxTries / backoff | 4 · `[60, 300, 1800]` seconds |
| Timeout | null (default) |
| Worker | `dedicated_cron` (Hostinger Cron #2) |
| Horizon / Supervisor | Not primary on this Hostinger path |
| job_batches | unused / empty for this path |

### Exception

```
PDOException / QueryException
SQLSTATE[22001]: String data, right truncated: 1406
Data too long for column 'serial_number' at row 1
```

Poison value written in SQL: `9920320` + internal spaces + `99` → **108 characters** (trim-resistant). Column: `orders.serial_number varchar(100)`.

Fail site: `RadiumBoxService::persistEnrichment` → `Order::update`.

### Stack (top app frames)

| Frame | Location |
|-------|----------|
| Model | `update` / `save` |
| App | `RadiumBoxService.php:123` `persistEnrichment` |
| App | `RadiumBoxService.php:90` `enrichOrderFromBackgroundSync` |
| App | `RadiumBoxOrderEnrichmentService.php:367` `runSyncAttempt` |
| App | `RadiumBoxOrderEnrichmentService.php:258` `process` |
| Job | `RadiumBoxOrderEnrichmentJob.php:34` `handle` |
| Worker | `queue:work` (dedicated_cron) |

### Order outcome after failure

| Field | Current value |
|-------|---------------|
| serial_number | `9920320` (len 7) — agent entry |
| serial_entered_at | 2026-08-04 15:47:27 · user_id 9 |
| device_model | MFS 110 |
| radiumbox_sync_status | SYNCED · last sync 16:46:08 |
| missing_serial_automation_status | completed |

Operational impact for RD3473215 is **recovered**. Dead-letter row is historical evidence — do not retry/delete per investigation rules.

### Classification

| Question | Answer |
|----------|--------|
| Retryable? | Unsafe until sanitizer ships — API may still return padded serial |
| Poison message? | Yes — deterministic overflow until input sanitized or serial locked |
| Code bug? | Yes — `normalizeSerialNumber` only `trim()`s ends; no max-length / internal-whitespace collapse |
| Missing dependency? | No |
| Database issue? | Schema limit correct; value invalid |
| External API issue? | Yes — RadiumBox returned padded/concatenated `serial_no` |
| Related to Issue 1? | **No** |

### Severity / user impact

- **Severity:** Low for this order (recovered).
- Code hardening still required to stop recurrence.
- Worker otherwise healthy (other enrichment jobs succeeding).

---

## Correlation

| Signal | Issue 1 Automation | Issue 2 Queue |
|--------|--------------------|---------------|
| Time | 18:00–18:05 IST | 15:03 enqueue · 15:41 fail IST |
| Entity | 8 closed service cases | Order RD3473215 |
| Surface | `automation_executions` | `failed_jobs` |
| Exception | already closed | serial_number too long |
| Deploy link | Pre-existing since Jul 10 | Predates evening UI deploys |

**Not the same exception.** Different classes, queues, timestamps, and entities.

### Deploy correlation

Production HEAD `1c0bbd64` (workspace/profile) committed **18:35 IST** — after both failure windows. Workforce commits earlier today do not touch auto-close or serial persistence.

**Conclusion:** today's deployment did **not** introduce these alerts.

---

## Timeline (2026-08-04 IST)

| Time | Event |
|------|-------|
| 00:00+ | Automation exec rows created for waiting auto-close steps |
| 12:17–16:37 | Workforce / ready-queue commits (unrelated) |
| 15:03:06 | `RadiumBoxOrderEnrichmentJob` enqueued for order 25224 |
| 15:41:02 | Job lands in `failed_jobs` after 4 attempts (serial overflow) |
| 15:47:27 | Agent enters serial `9920320` on RD3473215 |
| 16:46:08 | Order SYNCED with device model MFS 110 |
| 17:27–18:35 | Dashboard workspace / profile deploys (unrelated) |
| 18:00–18:05 | 8 `auto_close` executions fail: already closed |
| Ongoing | 34 orphan active waiting states on closed incidents |
| 18:55 | Read-only investigation captured |

### Infrastructure snapshot

| Check | Result |
|-------|--------|
| APP_ENV | production |
| QUEUE_CONNECTION | database |
| QUEUE_WORKER_MODE | dedicated_cron |
| failed driver | database-uuids |
| failed_jobs total | 1 (today's only) |
| Automation today | 64 success · 8 failed |
| Horizon | Not in use on Hostinger path |

---

## Temporary workaround

- Do **not** retry or delete the failed job.
- Do **not** restart queue workers.
- Optionally treat the automation Critical Alert as known noise until the closed-case guard ships.
- Monitor orphan waiting count (34); do not mass-clear until code guard is ready.

---

## Recommended fixes (ordered by priority)

| Priority | Fix | Files likely involved | Why |
|----------|-----|----------------------|-----|
| **P0** | Guard missing-serial contact + waiting start on closed service cases | `MissingSerialAutomationService::resolveIncident` / `sendContact`; `IncidentWaitingStateService::start` | Stops WhatsApp/email + orphan waiting on closed cases |
| **P0** | Treat auto-close “already closed” as idempotent success and clear waiting | `CustomerWaitingLifecycleService::autoCloseForNoResponse` | Stops false Failed executions; clears orphans |
| **P1** | Sanitize RadiumBox serial: collapse internal whitespace + enforce varchar(100) | `RadiumBoxOrderSearchResponseMapper::normalizeSerialNumber`; `RadiumBoxService::buildUpdates` | Prevents poison serial from reaching UPDATE |
| **P2** | Watchdog: exclude benign “already closed” from critical automation threshold (or Information severity) | `ProductionWatchdogService::automationAlerts` | Reduces Critical Alerts noise |
| **P2** | One-time data repair: clear 34 orphan active waiting rows on closed incidents | Ops script / `CustomerWaitingLifecycleRepairService` | Stops backlog auto-close failures — **after** code guard ships |

### Immediate hotfix required?

- **Yes** for Issue 1 closed-case guard (customer contact impact).
- **No** emergency deploy for Issue 2 order (already recovered).
- **No** rollback of today's UI deploy.

---

## Rollback impact

Rolling back today's dashboard/workspace/profile commits will **not** clear these alerts. Issue 1 needs a targeted lifecycle guard; Issue 2 needs serial sanitization (or leave the dead-letter as-is since the order recovered).

---

## Success criteria

1. Missing-serial automation never contacts or starts waiting on Closed incidents.
2. `auto_close` on an already-closed incident clears waiting and records success/skip — not Failed.
3. Orphan active waiting on closed incidents returns to 0 after repair.
4. RadiumBox padded serials never write to `orders.serial_number`; job succeeds or soft-fails without dead-letter.
5. Critical Alerts no longer fire solely on benign already-closed auto-closes (threshold / classification).
6. `failed_jobs` count for this class stays 0 for 7 days after sanitizer ships.

---

## Files likely involved

- `app/Services/MissingSerial/MissingSerialAutomationService.php`
- `app/Services/IncidentWaitingStateService.php`
- `app/Services/Automation/CustomerWaitingLifecycleService.php`
- `app/Services/RadiumBox/RadiumBoxOrderSearchResponseMapper.php`
- `app/Services/RadiumBox/RadiumBoxService.php`
- `app/Jobs/RadiumBoxOrderEnrichmentJob.php`
- `app/Services/Operations/ProductionWatchdogService.php`

---

## Investigation constraints honored

- Read-only only
- No failed-job retries
- No failed-job deletes
- No worker restarts
- No code changes
- No deployment

---

## Sources

Production DB: `automation_executions`, `failed_jobs`, `incident_waiting_states`, `audit_logs`, `orders`, `incidents` · App TZ `Asia/Kolkata` · SSH read-only via `tools/desk` host.
