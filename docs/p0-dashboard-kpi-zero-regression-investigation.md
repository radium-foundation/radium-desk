# P0 Investigation — Dashboard KPI Strip All Zeros After Deploy

**Date:** 2026-08-07  
**Priority:** P0 production regression  
**Status:** Root cause proven in code + local reproduction (no fix applied)  
**Prod symptom:** DB Open ≈ 852 / Total ≈ 29,814; Dashboard Open = Overdue = Customer Waiting = Ready Queue = 0  
**HEAD at investigation:** `e1370d76`  
**Canvas:** [`p0-dashboard-kpi-zero-regression.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-dashboard-kpi-zero-regression.canvas.tsx)

---

## Bottom line

All four operator KPIs share one upstream collection: `DashboardSnapshot` active incidents. Counts are not raw SQL `status=open`. They require a hydrated `Incident→Order` relation because `Incident::isPendingAdmin()` is:

```php
return $this->order !== null && ! $this->order->isTransactionLocked();
```

Commit **`7398a8c`** (2026-08-07 15:12 IST) switched that relation to `order_record_id`:

```php
// before
return $this->belongsTo(Order::class); // FK = order_id

// after
return $this->belongsTo(Order::class, 'order_record_id');
```

If `incidents.order_record_id` is **missing** (migration not applied) or **null**, Eloquent loads incidents successfully but every `order` relation is **null** — **no exception**. Then:

| Gate | Result |
|------|--------|
| `isPendingAdmin()` | false for every case |
| Waiting / Scheduled / Attention / Ready | all false |
| `OperationsQueueClassifier` | dumps everything into `pending_review` |
| Open (= Ready + Scheduled + Attention) | **0** |
| Customer Waiting | **0** |
| Overdue (needs `isPendingAdmin`) | **0** |
| Ready Queue tab | **0** |

**Category:** query / relation condition (broken order FK hydration), **not** visibility filter, manual ownership, platform/automation snapshot, feature flag, or stale KPI HTML alone.

**Data in DB is correct.** UI is faithfully rendering zeros computed from a broken relation. Rebuilding `operator.dashboard.snapshot:v2` does **not** fix it while the FK is broken.

---

## 1. Which service builds dashboard KPI counts

| KPI | Owner | Path |
|-----|--------|------|
| Open | `DashboardSnapshot::openCount()` | `DashboardService::buildFastChangingStats()` → `CaseQueueReadModel::operationalKpiCounts()` |
| Customer Waiting | `DashboardSnapshot::waitingCount()` | same |
| Overdue | `DashboardSnapshot::slaCounts()` | `CaseQueueReadModel::slaCounts()` |

Files:

- `app/Services/DashboardService.php` — `buildFastChangingStats()`, `fastChangingStatsForKpiStrip()`, `renderKpiStrip()`
- `app/ReadModels/Cases/CaseQueueReadModel.php` — thin delegate
- `app/Services/Dashboard/DashboardSnapshot.php` — count owner
- `resources/views/dashboard/partials/kpi-strip.blade.php` — Open / Overdue / Customer Waiting

Open formula (unscoped admin):

`action_required + scheduled + attention` (not raw `status=open`).

---

## 2. Which service builds Ready Queue

| Layer | Method |
|-------|--------|
| Tab / badge count | `DashboardSnapshot::filterCounts()` → `queueCounts()` → `incidentsForQueue('action_required')` |
| Entry | `DashboardService::serviceCaseFilterCounts()` |
| Label | `config/operations.php` → `queues.action_required.label` = "Ready Queue" |
| Admin overlay | `ServiceCaseAssignmentService::isVisibleInAdminReadyQueue()` (Ready only) |

---

## 3. Introducing commit

| Commit | Time (IST) | Relevance |
|--------|------------|-----------|
| **`7398a8c`** `perf(scheduler): consolidate light tasks and optimize scheduler cadence` | 15:12 | **Introduces regression** — `Incident::order()` → `order_record_id` + migration `2026_08_07_150000_add_order_record_id_to_incidents_table` |
| `e1370d7` manual ownership / SC28000 | 16:22 | Ready visibility only — **cannot** zero Open + Waiting + Overdue together |
| `30daa64` live dashboard pipeline | 12:18 | Lean KPI strip / deeper refund eager loads — not the all-zero mechanism |
| `a8faa1e` / `2c55a19` platform/automation snapshots | midday | **Not** on the operator KPI path |

Deploy note (`tools/commands/deploy.sh`): **git pull runs before `migrate --force`**. Code that reads `order_record_id` can be live before the column exists. If migrate fails after pull, the broken state persists (`set -e` aborts later steps but does not roll back code).

---

## 4. Classification of cause

| Hypothesis | Verdict |
|------------|---------|
| Visibility filter | No (alone) — Ready only |
| Manual ownership rule (`e1370d7`) | No for all-four — Ready only; Open still includes Scheduled/Attention |
| Snapshot cache stale empty payload | Unlikely as sole durable cause (15–30s TTL); rebuild does not restore KPIs if FK broken |
| Platform snapshot | No |
| Automation snapshot | No |
| Dashboard snapshot empty collection | Possible secondary if hydrate fails; local proof shows **non-empty** active set with all KPIs still 0 |
| Feature flag | No |
| **Query / relation condition (`order_record_id`)** | **Yes — proven** |

---

## 5. Live SQL vs dashboard (definitions)

```sql
-- Raw open (user number ~852)
SELECT COUNT(*) FROM incidents WHERE status = 'open';

-- Snapshot load scope (should be ≥ open)
SELECT COUNT(*) FROM incidents
WHERE status IN ('open','in_progress','awaiting_product_details');

-- Migration / FK health (must pass on prod)
SHOW COLUMNS FROM incidents LIKE 'order_record_id';
SELECT COUNT(*) AS null_order_record_id FROM incidents WHERE order_record_id IS NULL;
SELECT COUNT(*) AS mismatch FROM incidents
WHERE order_record_id IS NULL OR order_record_id <> order_id;
```

Dashboard Open ≠ SQL open. Dashboard Open is queue-derived after order hydration.

---

## 6. Exact point the count becomes zero

```text
DB incidents (operationally active)          ← non-empty (e.g. 852+)
        │
        ▼
DashboardSnapshotStore::loadFresh()
  Incident::with(['order…'])->whereIn(status, operationallyActive)
        │
        ▼
Incident::order() FK = order_record_id
  missing/null column → order = NULL on every row   ← BREAK
        │
        ▼
Incident::isPendingAdmin() → false
OperationsQueueClassifier → pending_review
        │
        ▼
openCount = Ready+Scheduled+Attention = 0
waitingCount = 0
slaCounts overdue (needs isPendingAdmin) = 0
filterCounts action_required = 0
        │
        ▼
kpi-strip.blade.php / Ready tab badges show 0
```

Local reproduction (column absent):

| Metric | Value |
|--------|-------|
| `activeIncidents()` | 1 |
| `order` null | 1 |
| classify | `pending_review` |
| open / waiting / overdue / ready | **0 / 0 / 0 / 0** |

After running the migration locally, `order` hydrates again and `isPendingAdmin()` returns true.

---

## 7. Does rebuilding the snapshot fix it?

**No** — while `order_record_id` is missing/null.

`OperatorDashboardCache::forgetSnapshot()` / `DashboardSnapshotStore::forget()` only clears `operator.dashboard.snapshot:v2`. The next hydrate still joins orders via `order_record_id`, still gets null orders, still computes zeros.

Snapshot rebuild **would** fix a pure stale-empty-cache case; that is **not** the proven durable mechanism here.

---

## 8. Ruled out (with reason)

| Item | Why |
|------|-----|
| SC28000 / `isVisibleInAdminReadyQueue` | Affects unscoped Ready membership only |
| Platform / automation / executive snapshots | Different cache keys and consumers |
| `resolveKpiScopeUser()` | Always `null` (global admin KPIs) |
| Hybrid Reverb divergence | Transient; poll reconciles; does not hold all four at 0 with healthy hydrate |
| Feature flags | No flag gates these four counts to zero |

---

## Production proof checklist (read-only)

Run on production (admin / SSH tinker):

```php
Schema::hasColumn('incidents', 'order_record_id'); // expect true after healthy deploy

DB::table('incidents')->whereNull('order_record_id')->count(); // expect 0

$n = App\Models\Incident::query()
    ->with('order')
    ->whereIn('status', App\Enums\IncidentStatus::operationallyActive())
    ->get()
    ->filter(fn ($i) => $i->order === null)
    ->count();
// If $n ≈ active count → root cause confirmed on prod

app(App\Services\Dashboard\DashboardSnapshotStore::class)->forget();
$s = app(App\Services\Dashboard\DashboardSnapshotStore::class)->get();
[$s->activeIncidents()->count(), $s->operationalKpiCounts(), $s->slaCounts()['overdue_cases'], $s->filterCounts()['action_required']];
```

Also confirm `migrations` table contains `2026_08_07_150000_add_order_record_id_to_incidents_table`.

---

## Minimal safe fix (do not apply until approved)

**Preferred (data-correct):** Ensure migration applied and backfilled on production, then `php artisan optimize:clear` (or `DashboardSnapshotStore::forget()`). No business-rule change.

**Emergency rollback (code):** Revert `Incident::order()` / `Order::incidents()` foreign keys to legacy `order_id` until migration is verified — keeps dual-write optional. Does not change Ready/ownership rules.

**Do not:** “Fix” KPIs by loosening classifier, ownership, or Ready visibility. Do not treat this as a cache TTL tweak.

---

## Answers to required questions

1. **KPI service:** `DashboardService` → `CaseQueueReadModel` → `DashboardSnapshot` (`openCount` / `waitingCount` / `slaCounts`).
2. **Ready Queue service:** `DashboardSnapshot::filterCounts` / `incidentsForQueue('action_required')` (+ `isVisibleInAdminReadyQueue` overlay).
3. **Introducing commit:** `7398a8c`.
4. **Type:** query / relation condition (`order_record_id` hydration), not ownership/platform/automation/feature-flag.
5. **SQL vs dashboard:** DB open counts remain; dashboard queue KPIs collapse to 0 after null `order`.
6. **Zero point:** `Incident::order()` null → `isPendingAdmin()` false → classifier `pending_review` → open/waiting/overdue/ready all 0.
7. **Snapshot rebuild:** does **not** fix while FK broken.
8. **Business rules:** unchanged in this investigation.
9. **Code changes:** none applied.
