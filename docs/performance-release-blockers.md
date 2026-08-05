# Performance Release — Remaining Production-Risk Blockers

> **Update (2026-08-05):** Cashfree Phase A hardening resolved blocker #3 (`CashfreePaymentIntegrityTest` passes). See [cashfree-phase-a-hardening.md](./cashfree-phase-a-hardening.md). Items #1, #2, and #4 remain deferrable per sprint analysis.

**Date:** 2026-08-05  
**Scope:** Four named regressions only (read-only investigation)  
**Related:** [performance-sprint-regression-analysis.md](./performance-sprint-regression-analysis.md), [dashboard-snapshot-cache-hardening.md](./dashboard-snapshot-cache-hardening.md)  
**Constraints:** No code or test changes in this pass. No Canvas.

---

## Summary

| Item | Verdict | Release | Risk |
|------|---------|---------|------|
| 1. Team Activity query bound (120 → ~192–201) | Stale bound + workforce/architecture cost — **not** snapshot-cache breakage | **Safe to defer** | **Medium** (perf on expand) |
| 2. `WorkspaceDashboardAssignTest` missing `replace_row` | Intentional `remove_row` when case is not Admin Ready; frontend OK | **Safe to defer** | **Low** |
| 3. `CashfreePaymentIntegrityTest` | Broken payment → order path; **unrelated** to dashboard perf | **Must fix before deploy** | **High** |
| 4. `Customer360RadiumBoxSyncTest` | Sync **functional**; asserts are presentation/copy | **Safe to defer** | **Low** |

---

## 1. `DashboardTeamActivitySortingAndIraTest` — query bound

### Observation

`test_panel_build_query_count_stays_bounded` expects `<= 120`. Current run: **194** (PHPUnit) / probe replica **201**.

The test calls `TeamActivityPanelService::build()` directly. Team Activity **lazy SSR** (`docs/performance-sprint-team-activity.md`) does not change `build()` cost — it only avoids calling `build()` on first dashboard paint.

Perf commit `5547a2d` does **not** modify `TeamActivityPanelService.php`.

### Exact query mix (probe, same fixture shape)

Grouped by table (total ≈ 201):

| Count | Table / area | Role in `build()` |
|------:|--------------|-------------------|
| 34 | `work_sessions` | Presence / working-hours / open-session resolution |
| 31 | `leave_requests` | Roster / availability / leave eligibility |
| 26 | `workforce_attendance_days` | Working Hours Today / attendance register |
| 24 | `users` | Roster + role/profile resolution (repeated) |
| 24 | `company_holidays` | Calendar / holiday checks (repeated exists) |
| 12 | `workforce_payroll_month_locks` | Attendance / payroll lock checks |
| 12 | `audit_logs` | KPI / latest activity aggregations |
| 10 | `roles` | Spatie role pivots for members |
| 8 | `orders` | Snapshot eager load + activity presentation |
| 7 | `team_member_work_schedules` | Roster schedules |
| ~5–10 | `incidents` + waiting / holds / appointments / refunds | **DashboardSnapshot** hydrate used by pending workload (`CaseQueueReadModel`) |
| 1 | `bonvoice_call_events` | Team IVR total |

Dominant cost is **workforce / attendance / leave / holiday** fan-out (~150+ queries), not the snapshot cache encoder.

### Classification

| Question | Answer |
|----------|--------|
| Real correctness regression? | **No** — panel still builds; bound is a budget guard |
| Expected architectural change? | **Yes** — bound set **2026-07-26/27** (`ea10951` / `88ed1fc`); later pending metrics → `CaseQueueReadModel`/`DashboardSnapshot`, PI badges, deeper attendance stack grew cost |
| Perf-sprint snapshot cache? | **No** — cache stores arrays; first `build()` still hydrates once. Lazy SSR is orthogonal |
| Test instrumentation issue? | **Partially** — fixed `120` ceiling never rebaselined; still a useful signal that expand path is heavy |

### Production note

Expanding Team Activity remains relatively expensive for supervisors. That is **performance debt**, not a deploy-stopping functional defect for the snapshot-cache release.

---

## 2. `WorkspaceDashboardAssignTest` — missing `replace_row`

### Observation

`test_dashboard_assign_action_returns_row_and_kpi_refresh_payload` fails: JSON `refresh` lacks key `replace_row`.

### Product behaviour (current)

`WorkspaceAssignActionService::buildSuccessResponse`:

1. Starts from dashboard effects with `replaceRow: true` + KPIs.
2. If `ServiceCaseAssignmentService::shouldRemoveFromAdminReadyQueue($incident)` → rebuilds effects as **`removeRow: true`** (and drops `replaceRow`).

`WorkspaceRefreshRenderer` only emits `replace_row` when `replaceRow` is true; otherwise may emit `remove_row`.

### Fixture vs Ready Queue

| Test | Fixture | Result |
|------|---------|--------|
| `WorkspaceDashboardAssignTest` | Open case, serial `SN-DASH-WS-*`, **not** validated Ready membership | Leaves / never in Admin Ready → **`remove_row`** |
| `QueueIntegrityLiveRefreshTest::test_dashboard_assign_to_admin_still_returns_replace_row` | Validated FM220 serial + RadiumBox synced + already admin-owned | Stays Ready → **`replace_row`** (**passes**) |

So `replace_row` was **not** removed globally. The failing test’s fixture triggers the Ready-queue **remove** path.

### Was the payload intentionally changed?

Yes — Ready-queue integrity work (`shouldRemoveFromAdminReadyQueue`) intentionally prefers **remove** over **replace** when the row should disappear from Admin Ready. That predates / is independent of the dashboard snapshot cache. Perf commit only trimmed obsolete KPI DOM patches in `response-handler.js` (`action_stats` / `sla_cards`); it still applies `replace_row` / `remove_row` / `replace_rows` / `remove_rows`.

### Frontend compatibility

`resources/js/workspace/response-handler.js` handles:

- `refresh.remove_row` / `remove_rows`
- `refresh.replace_row` / `replace_rows`
- `refresh.kpis_html.kpi_strip_html` (+ filter counts)

Assign from dashboard remains compatible.

### Classification

| Question | Answer |
|----------|--------|
| Regression of assign success? | **No** — assign + KPI refresh still succeed |
| Contract regression vs this test? | **Yes** — test expects outdated `replace_row` for a non-Ready fixture |
| Production risk | **Low** — correct Ready remove vs replace already covered by queue integrity tests |

---

## 3. `CashfreePaymentIntegrityTest`

### Observation (current suite)

Multiple failures of the form **expected 1 paid order, got 0** (webhook does not create the Desk order), e.g.:

- `test_dashboard_broadcast_exception_does_not_roll_back_paid_order`
- `test_radiumbox_exception_does_not_roll_back_paid_order`
- `test_notification_failure_does_not_roll_back_paid_order`
- `test_outbox_processor_exception_after_commit_does_not_mark_webhook_failed`
- `test_duplicate_webhook_does_not_create_duplicate_order`
- `test_reconcile_detects_missing_paid_orders`

Plus risky tests from mocked exception handlers.

### Could dashboard performance work cause this?

**No.**

Evidence:

- Perf commit `5547a2d` touches **no** Cashfree / outbox / webhook processor files.
- Snapshot cache / Team Activity lazy load / KPI DOM split do not sit on the webhook → order → outbox commit path.
- `DashboardBroadcastService` is only involved **after** a successful create (and tests already prove broadcast exceptions must not roll back — currently the order never appears at all).

This matches **RC-E** in the regression analysis: payment → order → outbox spine drift.

### Classification

| Question | Answer |
|----------|--------|
| Related to dashboard perf sprint? | **Unrelated** |
| Production risk if shipped | **High** — paid Cashfree webhooks must create Desk orders / mark processed / write outbox |
| Release | **Must fix before deploy** (any release that includes this broken spine on `main`) |

---

## 4. `Customer360RadiumBoxSyncTest`

### Failures (3 of 12)

| Test | Failing assert | What HTML actually has |
|------|----------------|------------------------|
| `test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer` | contains `bi-check-circle` | Serial `9389755`, chip **Synced**, sync history present — **enrichment succeeded** |
| `test_customer_360_shows_last_synced_freshness_when_serial_exists` | contains `bi-check-circle` | Serial + **Synced** chip; Bootstrap icon class removed/changed |
| `test_customer_360_shows_synchronization_history` | contains `Synchronization History` | Title is **`Synchronization history`** (sentence case) |

### Functional vs presentation

| Concern | Status |
|---------|--------|
| Open drawer triggers enrichment job / pending status | Covered by passing tests in the same class |
| Manual sync writes serial + `Synced` status + JSON success | **Functional path works** (asserts before the icon check pass) |
| Device partial / polling attributes | Passing tests remain |
| Icon class / heading capitalization | **Presentation / copy contract only** |

Perf commit’s Customer360 changes are mostly serial-request state reuse + communication-action presentation — not RadiumBox sync store logic.

### Classification

| Question | Answer |
|----------|--------|
| Functional RadiumBox sync broken? | **No** (for these failures) |
| Presentation drift? | **Yes** |
| Production risk | **Low** |
| Release | **Safe to defer** (update asserts or restore copy/icon if product wants the old chrome) |

---

## Release blockers

### Must fix before deploy

1. **Cashfree payment integrity / webhook success path** — orders not created (`CashfreePaymentIntegrityTest` and the broader RC-E cluster). **Risk: High.** Unrelated to dashboard performance work, but blocks a safe production release.

### Safe to defer

1. **Team Activity `build()` query budget** — raise/rebase bound and/or batch workforce holiday/leave lookups later. **Risk: Medium** (expand latency), not correctness.
2. **`WorkspaceDashboardAssignTest` `replace_row` expectation** — align fixture with Ready membership or assert `remove_row` for non-Ready assigns. **Risk: Low.**
3. **Customer360 RadiumBox presentation asserts** (`bi-check-circle`, title case). **Risk: Low.**

### Already addressed elsewhere (not blockers here)

- Dashboard snapshot Eloquent-in-cache issue → see [dashboard-snapshot-cache-hardening.md](./dashboard-snapshot-cache-hardening.md).

---

## Risk legend

| Level | Meaning |
|-------|---------|
| **High** | Incorrect money / case creation / durability under production traffic |
| **Medium** | Correct behaviour but material latency or operator friction |
| **Low** | Test/contract or cosmetic drift; operators not blocked |

---

*End of investigation. No code was modified.*
