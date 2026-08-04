# P0 Investigation — Stale Automation Alerts After Fix

**Date:** 2026-08-04  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no production changes  
**Captured:** 2026-08-04 20:56 IST  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-stale-automation-alerts-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-stale-automation-alerts-investigation.canvas.tsx)

---

## Root Cause Summary

The production issue is resolved operationally, but alerts count **immutable historical Failed ledger rows** for the calendar day — not open/unresolved incidents.

The 8 `already closed` failures (exec IDs **2241, 2259, 2260, 2270, 2271, 2272, 2274, 2283**) remain `status=failed`. Hotfix does not rewrite them. Orphan repair does not rewrite them. Failed job forget does not touch them.

Watchdog and Automation Health therefore still report **8**. Telegram re-sends the same Critical Alert every **~60 minutes** while that count stays ≥ threshold **3**.

| Metric | Value |
|--------|-------|
| Failed rows still in ledger today (created_at) | 8 |
| Alert semantics | Historical calendar-day count |
| Telegram re-notify cooldown | 60 minutes |
| Queue · Scheduler · Presence · DB | Healthy |
| Orphan waiting on closed | 0 |
| failed_jobs | 0 |

---

## What was fixed vs what still fires

### Fixed (operational)

- Closed-case waiting guard deployed **19:17 IST** (`a96690bc`)
- Orphan waiting cleared (0 orphans)
- `failed_jobs = 0`
- Queue / Scheduler / Presence / DB probes Healthy

### Still firing alerts

- Watchdog SQL: `Failed ∧ created_at ≥ today` → **8 ≥ threshold 3**
- Message: `8 automation execution failure(s) recorded today.`
- Telegram last sent **20:20 IST** (also 19:20, 18:40, 18:10, 17:50)
- Expand: `failures_today=8` + ancient pending → Critical / “stalled”

**Natural clear without code change:** At midnight IST the `created_at ≥ startOfDay` window rolls; the 8 rows leave “today” and the Automation watchdog alert stops — unless new Failed rows appear. That is calendar expiry, not resolution tracking.

---

## Why surfaces disagree

| Surface | Reads | Sees |
|---------|-------|------|
| Queue / Scheduler / Presence / DB | Live operational probes | Healthy — correct |
| Telegram Critical Alert | Watchdog historical Failed count today | Automation: 8 failures — stale semantics |
| Automation Health Expand | Same ledger + pending orphans (Jul 25+) | Critical / stalled — false runtime outage |
| Ops Critical Alerts strip | Integrations / overdue / IRA — **not** watchdog | May be clear while Telegram fires |
| Executive Snapshot / Platform Critical zone | Cached zone health — not watchdog text | May show unrelated or derived status |

---

## Architecture · alert flow

```
Automation execution (auto_close)
        ↓
automation_executions row status=failed  (immutable ledger)
        ↓
No "resolved" flag · hotfix/repair do not rewrite
        ↓
Cron watchdog:send-critical-alerts (every 5 min)
        ↓
ProductionWatchdogService::automationAlerts()
  COUNT Failed WHERE created_at >= startOfDay()
  IF count >= 3 → alert key automation:failures
        ↓
IraCommunicationService::sendCriticalAlerts()
  dedupe_key = watchdog:automation:failures
  cooldown cache 60 minutes
        ↓
Telegram Critical Alert (re-sent hourly while still true)
        ↓
Parallel: AutomationHealthService aggregation (60s)
  → Platform Automation overview / Expand (300s)
```

### Proven production timeline

| Time IST | Event |
|----------|-------|
| ~18:00–18:05 | 8 auto_close Failures written (already closed) |
| 19:00:51 | 20 older pending auto_closes complete as Failed (**pre-hotfix**) |
| 19:17:51 | Hotfix deploy `a96690bc` — guard live |
| After deploy | 0 success rows with `external_id=customer-waiting-already-closed` |
| Orphan repair | Orphans → 0 |
| Failed job | forgotten · `failed_jobs=0` |
| 17:50→20:20 | Telegram Automation Alert every ~60m |
| 20:56 | Watchdog still returns `automation:failures` count=8 |

---

## Every consumer

| Component | Data source | Cache | Refresh | Historical vs active | Cleared? |
|-----------|-------------|-------|---------|----------------------|----------|
| ProductionWatchdogService | `automation_executions` Failed · `created_at ≥ startOfDay` | None (live SQL) | Every Telegram poll (5m) | Calendar day historical | Never cleared |
| Telegram (IraCommunicationService) | Watchdog `collectCriticalAlerts()` | Cooldown `ira:cooldown:*` 60m | Cron `*/5` | Same as watchdog | Re-sends hourly while true |
| Automation Health / Expand | Same ledger `failures_today` + pending orphans | agg cache 60s · zone 300s | Warm / on demand | Calendar day + all-time pending | Never cleared |
| Platform Health · Automation probe | Failed exists in last 24h | Via overall health warm 120s | Zone warm | Rolling 24h historical | Never cleared |
| Scheduler / Queue / Presence / DB | Live heartbeats / failed_jobs / probes | Varies | Live | Active operational | Clears when healthy |
| Ops Critical Alerts strip | Integrations + overdue + IRA risks | Integration cache | Dashboard refresh | Active operational | Does **not** read watchdog |

### “Today” semantics

| Consumer | Window |
|----------|--------|
| Watchdog `automationAlerts` | Calendar day (app TZ Asia/Kolkata) via `created_at ≥ startOfDay` — **not** last 24h, **not** unresolved-only |
| AutomationHealthService `failures_today` | Same: `created_at ≥ today()` |
| Platform `AutomationHealthProvider` | Rolling **24h** `Failed.exists()` — different window |
| Telegram | Whatever watchdog returns each run |

### Executive Snapshot

Executive Snapshot zone does **not** query `automation_executions` for the “8 failures” string. That exact message is **watchdog → Telegram** (and any UI that surfaces watchdog). Expand “stale automation count” comes from `PlatformAutomationOverviewService` / `AutomationHealthService` (`failures_today=8` + false stalled from pending since **2026-07-25**). Snapshot caches TTL 120s (P1) / automation overview 300s — not the reason the 8 persists; **the ledger is**.

### Why Expand says “Scheduler stalled” while Scheduler is Healthy

`AutomationHealthStatusCalculator` treats **any pending execution older than 1 hour** as stalled. Production has 3 pending `customer_not_responding` rows with `started_at` as old as **2026-07-25**. That forces Automation Health to **Failed / stalled**, while the separate Scheduler & Workers card uses heartbeat + queue probes and correctly shows Healthy.

---

## Telegram

Telegram does not know about hotfix, orphan repair, or “resolved.” It only asks: does watchdog still emit `automation:failures`? Yes while 8 Failed rows exist today.

| Question | Answer |
|----------|--------|
| Current vs historical? | Historical calendar-day Failed count |
| Ignores resolved failures? | No — no resolved concept on ledger |
| Remembers last notification? | Yes — cooldown cache key `ira:cooldown:{user}:critical_system_alert:watchdog:automation:failures` · 60 min |
| Repeatedly notifies same incident? | Yes — every ~60m while condition true (proven 17:50, 18:10, 18:40, 19:20, 20:20) |
| Dedupe includes count/fingerprint? | No — static key `watchdog:automation:failures` |
| Schedule | `watchdog:send-critical-alerts` every 5 minutes |

---

## Recommended unified health model

| Class | Definition | UI / Telegram |
|-------|------------|---------------|
| Historical failures | Ledger Failed rows (any time) — audit trail | Activity table only · never Critical alone |
| Resolved failures | Fixed root cause / classified benign / repaired | Information or hidden from Critical |
| Open failures | Failed + still actionable (open case, retryable job, open waiting) | Warning on Expand |
| Critical failures | Open + production impact (dead letter, sync down, scheduler truly stalled) | Critical Alerts + Telegram (once per incident fingerprint) |

Suppress duplicate Telegram: first fire per dedupe fingerprint per day (or until condition clears), not every cooldown while still true — unless severity increases.

Only **active unresolved** production issues should be Critical. Historical “already closed” ledger rows must not keep Critical Alerts or Telegram alive after the operational fix.

---

## Priority fixes (do not implement in this investigation)

| Priority | Fix | Why |
|----------|-----|-----|
| **P0** | Watchdog: only alert on unresolved / post-cutoff active failures — exclude known-benign already-closed (or require open waiting) | Stops Telegram + Critical noise for historical ledger rows |
| **P0** | Telegram dedupe: include resolution fingerprint; suppress while same key still true after first send (or day-scoped cooldown) | Stops hourly repeat of identical Automation Alert |
| **P1** | Split health model: Historical vs Open vs Critical; Expand shows counts but Critical only for open | Aligns Expand / Platform / Telegram |
| **P1** | Automation Health: pending older than N days ≠ scheduler stalled; ignore terminal/orphan pending | Fixes false Critical while Scheduler card is Healthy |
| **P2** | Optional: classify pre-hotfix already-closed Failed as Skipped/Success via one-time ledger repair (audit only) | Clears today's count without waiting for midnight |

---

## Rollback impact

Investigation only — no production changes. Future alert-semantics changes are additive; reverting them restores today’s historical-count behavior. Ledger rows need not be deleted; classification/filter is enough. One-time status rewrite (P2) is optional and should be audited.

---

## Success criteria

1. After operational fix, Telegram stops for already-closed historical Failures the same day.
2. Critical Alerts / Expand distinguish historical count vs open critical.
3. Queue/Scheduler Healthy is not contradicted by automation ledger noise.
4. Identical Telegram Critical is not re-sent every cooldown while unchanged.

---

## Investigation constraints honored

- Read-only only
- No fixes
- No deletes
- No retries
- No SQL updates

---

## Sources

Production: `automation_executions`, `ira_notifications`, `failed_jobs`, orphan waiting count · Code: `ProductionWatchdogService`, `IraCommunicationService`, `AutomationHealthService`, `AutomationHealthStatusCalculator`, `PlatformAutomationOverviewService`, `bootstrap/app.php` watchdog schedule · App TZ `Asia/Kolkata`.
