# Performance Sprint — Customer360 Quick Wins

**Date:** 2026-08-05  
**Source:** [radium-desk-performance-audit.md](./radium-desk-performance-audit.md) (Q6, Q10, M2)  
**Scope:** Customer360 only. No Timeline rewrite. No feature / UI / API changes.

---

## Goal

Cut drawer-open and IRA/AI AJAX cost without changing operator-visible behavior:

1. Batch communication action lifecycle audit lookups.
2. Compute Action Visibility once per drawer build.
3. Compute Serial Request states once per drawer build.
4. Share Case Intelligence across Overview (IRA) and AI tab via `incident_id + updated_at` cache.

---

## Changes

### 1. Batch communication action status queries

**Before:** `Customer360CommunicationActionStatusPresenter::forIncident` ran ~3 audit lookups per action (`resolveStatus` → latest, last-sent, latest again) ≈ 3 × N queries on every drawer open.

**After:** `CommunicationActionLifecycleService::eventIndexForIncident()` loads all lifecycle audits for the incident in one ordered query and builds:

- `latestByAction`
- `lastSentByAction`

The presenter resolves status from the preloaded index. Public `resolveStatus()` still works for single-action paths.

**Files:**

- `app/Services/CommunicationActions/CommunicationActionLifecycleService.php`
- `app/Support/Customer360/Customer360CommunicationActionStatusPresenter.php`
- `tests/Unit/Customer360CommunicationActionStatusPresenterTest.php`

---

### 2. Dedupe Action Visibility

**Before:** `Customer360Service::buildDrawerData` called `Customer360ActionVisibilityService::forIncident` twice on the happy path, and `Customer360OverflowMenuPresenter::build` called it again.

**After:** Visibility is computed once in `buildDrawerData` and passed into `overflowMenuPayload` → overflow presenter (optional `$visibility` argument; existing call sites unchanged).

**Files:**

- `app/Services/Customer360Service.php`
- `app/Support/Customer360/Customer360OverflowMenuPresenter.php`

---

### 3. Dedupe Serial State

**Before:** `serialRequestState($order)` and `correctSerialRequestState($order)` each ran twice (payload keys + overflow menu args).

**After:** Each runs once; the same arrays are used for drawer payload and overflow menu.

**Files:**

- `app/Services/Customer360Service.php`

---

### 4. Cache Case Intelligence (`incident_id + updated_at`)

**Before:** Request-scoped `$snapshotCache` only. Overview `executive-summary` and AI `ai-workbench` are separate AJAX requests, so each rebuilt the full engine snapshot.

**After:** `CaseIntelligenceEngine::build()` still memoizes in-request, and also stores the snapshot in Laravel Cache:

| Item | Value |
|------|--------|
| Key | `customer360:case-intelligence:{incidentId}:{updatedAtUnix}` |
| TTL | 300 seconds (safety net; version key invalidates on incident update) |
| Shared by | Overview IRA (`executiveSummaryPayload`), AI tab (`aiTabPayload`) |
| Force refresh | `forget($incident)` + `build(..., force: true)` clears and rebuilds |

Null collector results are cached with a sentinel so repeated empty builds stay cheap.

**Files:**

- `app/Services/Customer360/Intelligence/CaseIntelligenceEngine.php`
- `tests/Unit/Customer360/Intelligence/CaseIntelligenceReuseTest.php`

---

## Explicitly out of scope

- Timeline source rewrite / SQL limits / poll ETag
- Bonvoice deferral
- Email thread list column / pagination changes
- Dashboard / Team Activity / Email Intake work
- UI, routes, response shapes, feature flags beyond existing IRA engine flag

---

## Compatibility

| Surface | Behavior |
|---------|----------|
| Drawer HTML / JSON keys | Unchanged |
| Communication action status labels / variants | Unchanged (same presenter rules, batched data) |
| Overflow menu | Same groups/items; visibility may be injected |
| IRA Overview / AI workbench | Same payloads; may hit shared cache |
| `resolveStatus()` single-action API | Preserved |
| Overflow `build()` without `$visibility` | Still computes visibility internally |

---

## Verification

```bash
php artisan test --filter=Customer360CommunicationActionStatusPresenterTest
php artisan test --filter=CaseIntelligenceReuseTest
php artisan test --filter=Customer360ServiceTest
php artisan test --filter=Customer360OverflowMenuPresenterTest
php artisan test --filter=Customer360DrawerTest
```

Manual smoke:

1. Open Customer360 drawer — communication actions and overflow menu match prior behavior.
2. Load Overview IRA, then open IRA AI — second load should reuse cached intelligence when the incident was not updated.
3. Refresh AI workbench — forces rebuild (cache bypassed).

---

## Expected impact

| Path | Effect |
|------|--------|
| Drawer open | Fewer audit_logs queries for action statuses; less duplicate eligibility / serial-history work |
| Overview → AI | Second intelligence build avoided while `incidents.updated_at` unchanged |
| Timeline | Untouched |

---

*End of sprint notes.*
