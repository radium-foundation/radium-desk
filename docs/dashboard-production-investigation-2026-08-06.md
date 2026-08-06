# Dashboard Production Investigation — 2026-08-06

**Date:** 2026-08-06  
**Scope:** Root-cause investigation only — **no code changes**  
**Symptoms:**
1. Email Intake dashboard **Needs Attention** stuck at **3** since last night  
2. Dashboard **Team Activity** will not expand when clicked  

**Canvas:** [`dashboard-production-investigation-2026-08-06.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/dashboard-production-investigation-2026-08-06.canvas.tsx)

**Related prior notes:**
- [`docs/email-intake-dashboard-counter-investigation.md`](./email-intake-dashboard-counter-investigation.md) (same-day counter deep dive)
- [`docs/performance-sprint-email-intake.md`](./performance-sprint-email-intake.md) (widget cache, shipped in `5547a2d` / v4.0.4)
- [`docs/performance-sprint-team-activity.md`](./performance-sprint-team-activity.md) (lazy shell, shipped in `5547a2d` / v4.0.4)
- [`docs/hostinger-scheduler-cron-wrapper.md`](./hostinger-scheduler-cron-wrapper.md) (Gmail sync / scheduler stall risk)

---

## Executive verdict

| Issue | Root cause (code-proven) | Production confirmation still needed? |
|-------|--------------------------|----------------------------------------|
| **1 – Needs Attention = 3** | **Not** operator snapshot cache; **not** an overnight widget-cache freeze (TTL ≤ 60s). After hard reload, the tile equals live `needs_review`+`failed` (bucketed). Stuck overnight ⇒ either backlog really is 3, or ingest/scheduler/outbox stalled. | **Yes** — DB / Learning Center / sync logs on production |
| **2 – Team Activity won’t expand** | **Yes — Aug 5 lazy-shell regression** (`5547a2d`). Expand is now fetch-dependent; failures are swallowed; empty API response removes the panel. | Confirm Network tab: click → `GET /dashboard/team-activity` status/body |

This workspace is **local** (`app.env=local`, inbound email disabled, `incoming_email_messages=0`). It cannot reproduce the live “3” tile. Findings below are from code + local config evidence plus production verification steps.

---

## Issue 1 — Email Intake “Needs Attention” stuck at 3

### What the tile counts

| Item | Value |
|------|-------|
| **Authoritative service** | `App\Services\IncomingEmail\IncomingEmailIntakeCounterService` |
| **Entry** | `dashboardWidget()` → `buildDashboardWidget()` |
| **Formula** | `Sales + Orders + Priority(Escalations)` from `IncomingEmailAttentionCategoryService::aggregateCounts()` |
| **Row set** | Every `IncomingEmailMessage` with `status IN (needs_review, failed)` — each row gets exactly one attention bucket (default Sales) |
| **Identity** | `needs_attention` **==** Learning Center **Needs Human** `COUNT(*)` (same rows; different presentation) |

Ignored mail (Promotions / Spam / Auto Processed) is **not** in the big number — only hover rows from today’s `incoming_email_ignore_stats`.

### Answers to the investigation checklist

#### 1. Are new emails still being ingested?

**Local:** No.

| Signal (local, 2026-08-06 ~09:40 IST) | Value |
|---------------------------------------|-------|
| `inbound_email.enabled` | `false` |
| `inbound_email.gmail.enabled` | `false` |
| `incoming_email_messages` total | `0` |
| `storage/logs/inbound-email-gmail-sync.log` | absent |

**Production path:**

```text
schedule:run
  → inbound-email:sync-gmail   (only if BOTH enabled flags true)
  → ingest + outbox email.inbound.process
  → outbox:process (every minute)
  → IncomingEmailProcessorService::process()
```

Scheduler gate (`bootstrap/app.php`):

```php
->when(fn (): bool => (bool) config('inbound_email.enabled')
    && (bool) config('inbound_email.gmail.enabled'))
```

Known Hostinger risk: hung `inbound-email:sync-gmail` + host `flock` can skip **all** `schedule:run` work unless Cron #1 uses `bin/schedule-run.sh`.

#### 2. Is the intake processor running?

Processor: `IncomingEmailProcessorService::process()`.  
Its `finally` block calls `forgetDashboardWidgetCache()`.

If sync never enqueues, or outbox is stuck, the Needs Human backlog (and therefore the tile) will not move even if Gmail has new mail.

#### 3. Are new IncomingEmail records being created?

**Local:** No (0 rows).  
**Production verify:**

```sql
SELECT COUNT(*) FROM incoming_email_messages
  WHERE created_at >= NOW() - INTERVAL 12 HOUR;
SELECT MAX(received_at), MAX(created_at), MAX(processed_at)
  FROM incoming_email_messages;
SELECT status, COUNT(*) FROM incoming_email_messages GROUP BY status;
```

#### 4. Are emails moving into expected queues?

| Surface | Source | In Needs Attention? |
|---------|--------|---------------------|
| Needs Human | `needs_review` / `failed` | **Yes** (entire total) |
| Promotions / Spam / Auto Processed | ignore stats / ignored rows | Hover only |
| Smart-routed / linked / created case | leaves Needs Human | No |

New ignored mail increments ignore stats, **not** Needs Attention. Routing/linking also removes rows from Needs Human — so a healthy pipeline can keep the tile at 3 while Gmail traffic continues.

#### 5. Compare Dashboard / Learning Center / DB

| Source | Mechanism | Cache? |
|--------|-----------|--------|
| Dashboard tile | `dashboardWidget()` → attention sum | Yes — `incoming_email:dashboard_widget:{Y-m-d}`, TTL **45s** (clamped 30–60) |
| Learning Center Needs Human badge | `needsHumanCount()` / `counts()` | **No** |
| DB | `COUNT(*)` where `status IN (needs_review, failed)` | Source of truth |

After hard reload on a healthy admin session:

```text
tile.needs_attention === LC Needs Human === DB COUNT(needs_review|failed)
```

| Observation | Conclusion |
|-------------|------------|
| All three = 3 | Tile is **correct**; triage or pipeline explains “stuck” |
| Tile = 3, LC/DB ≫ 3 | Unexpected (widget cache/UI); hard reload should clear cache path |
| Tile = 3, no new rows overnight | Ingest/scheduler stalled |
| Tile = 3, new rows but all ignored/routed | Pipeline healthy; Needs Human backlog stable |

#### 6. Is polling still running?

| Piece | Detail |
|-------|--------|
| Endpoint | `GET /dashboard/live` → `DashboardLiveController::refresh()` → `kpi_strip_html` |
| Client | `resources/js/live-dashboard.js` + `live-dashboard-polling.js` |
| DOM patch | `resources/js/dashboard-kpi-dom.js` patches `.dashboard-email-intake-kpi__value` |
| Intervals | Active ~20s, idle ~60s |
| Hidden tab | `document.hidden` suppresses refresh |

An overnight **background tab** can paint “3” until focus + poll (or hard reload). That cannot survive a hard reload if the DB moved.

#### 7. Is snapshot cache serving stale data?

**No for Email Intake.**

| Cache | Key | Holds Email Intake? |
|-------|-----|---------------------|
| Operator snapshot | `operator.dashboard.snapshot:v2` | **No** |
| Slow scalars | `operator.dashboard.slow_scalars:v1` | **No** |
| Email widget | `incoming_email:dashboard_widget:{date}` | **Yes** (≤ 60s) |

#### 8. Is cache invalidation occurring?

| Trigger | Invalidates widget cache? |
|---------|---------------------------|
| `IncomingEmailProcessorService::process()` `finally` | **Yes** |
| `IncomingEmailLearningActionService::applyToMessages()` | **Yes** |
| Own-outbound ingest short-circuit (ignore stat only) | **No** (TTL covers) |
| Operator dashboard snapshot forget | **N/A** (different keys) |

TTL fallback alone refreshes every ≤ 60s. **Cannot explain multi-hour freeze after full page load.**

#### 9. Logs reviewed (this workspace)

| Log | Finding |
|-----|---------|
| `storage/logs/inbound-email-gmail-sync.log` | Missing (gmail sync never ran locally) |
| `storage/logs/outbox-processor.log` | Not present as a dedicated conclusive local intake signal |
| `storage/logs/laravel.log` | Dominated by PHPUnit `testing.*` noise; not live production intake |

**Production must inspect:** `inbound-email-gmail-sync.log`, `outbox-processor.log`, scheduler heartbeat, Gmail `history_id` lag.

#### 10. Exact service / class responsible

| Responsibility | File | Method |
|----------------|------|--------|
| **Tile number** | `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | `dashboardWidget()` / `buildDashboardWidget()` |
| Categorization | `app/Services/IncomingEmail/IncomingEmailAttentionCategoryService.php` | `aggregateCounts()` / `categorize()` |
| Cache forget on process | `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | `process()` finally |
| Cache forget on teach | `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | `applyToMessages()` |
| SSR / live stats | `app/Services/DashboardService.php` | `fastChangingStatsFor()` / `liveMetricsFor()` |
| Card markup | `resources/views/dashboard/partials/email-intake-kpi-card.blade.php` | — |
| Live DOM patch | `resources/js/dashboard-kpi-dom.js` | KPI strip surgical apply |
| Sync schedule | `bootstrap/app.php` | `inbound-email:sync-gmail` |

### Ranked root-cause hypotheses (Issue 1)

| Rank | Hypothesis | When it fits | Overnight freeze after hard reload? |
|------|------------|--------------|-------------------------------------|
| **A** | Backlog really is still 3 | LC Needs Human = 3; DB = 3; new mail ignored/routed | Yes — tile is correct |
| **B** | Ingest / scheduler / outbox stalled | Gmail has mail; Desk rows not advancing; sync/outbox quiet | Yes — pipeline, not counter |
| **C** | UI-only freeze (hidden tab) | Hard reload or LC differs from open tab | No — fails hard-reload test |
| **D** | Stale widget / snapshot cache | — | **Ruled out** for multi-hour |
| **E** | Surgical live patch bug | — | **Low** — would not survive hard reload |

### Recommended fix (do not implement yet)

1. **Confirm A vs B vs C on production** with the script below + Learning Center `?queue=needs_human`.
2. **If A:** No counter bug. Triage the 3 Needs Human items; clarify ops that ignored/routed mail does not raise Needs Attention.
3. **If B:** Ops recovery — confirm `INBOUND_EMAIL_ENABLED` + `INBOUND_EMAIL_GMAIL_ENABLED`, Cron #1 → `bin/schedule-run.sh`, clear hung sync/flock, drain outbox, check sync log.
4. **If C:** Force KPI refresh on `visibilitychange`; ensure focused-tab poll path.
5. **Do not** couple Email Intake into `OperatorDashboardCache` — wrong layer.

#### Production confirmation script (read-only)

```bash
php artisan tinker --execute="
\$c = app(App\Services\IncomingEmail\IncomingEmailIntakeCounterService::class);
\$admin = App\Models\User::role(['superadmin','admin'])->where('is_active',true)->first();
echo json_encode([
  'enabled' => config('inbound_email.enabled'),
  'gmail' => config('inbound_email.gmail.enabled'),
  'db_needs_human' => \$c->needsHumanCount(),
  'counts' => \$c->counts(),
  'widget' => \$c->dashboardWidget(\$admin),
  'latest' => App\Models\IncomingEmailMessage::orderByDesc('id')->limit(5)
      ->get(['id','status','classification','received_at','created_at']),
], JSON_PRETTY_PRINT);
"
tail -n 80 storage/logs/inbound-email-gmail-sync.log
tail -n 80 storage/logs/outbox-processor.log
```

### Risk assessment (Issue 1)

| Path | Risk | Notes |
|------|------|-------|
| Ops recovery (scheduler / outbox) | Low–medium | May restart hung sync; watch flock / overlapping sync |
| Counter code change without prod confirm | **High** | Likely fixing the wrong layer |
| Extending widget TTL / snapshot coupling | Medium | Adds real staleness risk; wrong diagnosis |

---

## Issue 2 — Team Activity will not expand

### How expand works

| Layer | Mechanism |
|-------|-----------|
| Framework | **Vanilla JS** — not Alpine, Livewire, or Bootstrap Collapse |
| Markup | `resources/views/dashboard/partials/team-activity-panel.blade.php` |
| JS | `resources/js/dashboard-team-activity.js` |
| Init | `resources/js/pages/dashboard.js` → `initDashboardTeamActivity(pageRoot)` on `DOMContentLoaded` |
| API | `GET /dashboard/team-activity` → `DashboardTeamActivityController::refresh()` |
| CSS | `.team-activity-panel.is-collapsed [data-team-activity-panel-body] { display: none; }` |

**Panel expand flow:**

1. Click `[data-team-activity-panel-toggle]`
2. Remove `is-collapsed` / set `data-team-activity-collapsed="0"`
3. `fetch(GET /dashboard/team-activity)`
4. `panel.replaceWith(nextPanel)` + re-bind click handlers
5. Start 30s poll while expanded

**Row expand:** only the chevron `[data-team-activity-row-toggle]` — row body clicks intentionally do nothing.

### Answers to the investigation checklist

#### 1. Is the click event firing?

Delegated on the panel in `bindPanelInteractions()`. Tests prove title + chevron clicks expand (`tests/js/dashboard-team-activity.test.js`).  
If init never ran, clicks do nothing.

#### 2. Is JavaScript loading successfully?

Vite entry: `@vite('resources/js/pages/dashboard.js')` on `dashboard/index.blade.php`.  
Team Activity is bundled into that chunk. A failed Vite load / early throw in `bootDashboard()` before line ~407 prevents init.

#### 3. Any console errors?

Team Activity module **swallows** fetch errors (`catch { /* ignore */ }`). No `console.error` on failure — expand can look broken with an empty console.

#### 4. Is Alpine/Livewire/Bootstrap initializing correctly?

**N/A** — none of those drive this widget.

#### 5. Has another element begun overlaying or blocking clicks?

Team Activity sits **below** `.dashboard-primary-panel` as a sibling. Workspace-switching `pointer-events: none` overlays apply only inside the primary panel hosts — they do **not** cover Team Activity header. No Team Activity-specific `pointer-events: none` / elevated z-index blocker found.

#### 6. Did a recent dashboard optimization break expansion?

**Yes — primary regression vector.**

Commit `5547a2d` (2026-08-05) / release **v4.0.4** (`3437acb`):

| Before | After |
|--------|-------|
| SSR called `TeamActivityPanelService::build()` even when collapsed | SSR renders **empty lazy shell** only |
| Expand mostly revealed already-rendered roster | Expand **must** hydrate via `GET /dashboard/team-activity` |
| Fetch failure still left prior roster visible | Fetch failure → **empty body** (or panel removed if `empty: true`) |

Files changed for this:

- `app/Http/Controllers/DashboardController.php` — drop SSR `build()`, pass `teamActivityCanView`
- `resources/views/dashboard/partials/team-activity-panel.blade.php` — `$panel = null` shell
- `resources/js/dashboard-team-activity.js` — always hydrate when session restores expanded; `stablePanelHtml()` poll compare
- `resources/views/dashboard/index.blade.php` — include shell when can view

#### 7. Is polling replacing the DOM and removing listeners?

| Poller | Touches Team Activity? |
|--------|------------------------|
| `/dashboard/live` (20s/60s) | **No** |
| Team Activity own poll (30s while expanded) | **Yes** — `replaceWith` + `bindPanelInteractions` re-bind |

Live KPI polling is **not** stripping Team Activity listeners.  
Own poll re-binds after replace. Secondary concern: server HTML always ships `is-collapsed` in the Blade shell attrs, so `stablePanelHtml()` may still differ from an expanded DOM node and force replace more often than necessary — flicker risk, not primary “won’t expand”.

#### 8. Does the API return expanded data correctly?

Contract (`DashboardTeamActivityController`):

```json
{ "html": "<div data-team-activity-panel>...</div>", "empty": false, "agent_count": N, "generated_at": "..." }
```

| Response | Client behavior |
|----------|-----------------|
| `empty: true` / `html: null` | **Removes panel from DOM** |
| Non-OK HTTP / network error | Silent return — expanded empty shell remains |
| OK + html | Replace panel, restore expand state |

Expanded agent history is loaded when query has `expanded[]` IDs.

#### 9. Exact file responsible

| Role | File |
|------|------|
| **Primary client** | `resources/js/dashboard-team-activity.js` |
| Lazy shell markup | `resources/views/dashboard/partials/team-activity-panel.blade.php` |
| SSR gate | `app/Http/Controllers/DashboardController.php` |
| API | `app/Http/Controllers/DashboardTeamActivityController.php` |
| Roster builder | `app/Services/Dashboard/TeamActivityPanelService.php` |
| Boot | `resources/js/pages/dashboard.js` |

### Ranked failure modes (Issue 2)

| Likelihood | Mode | Evidence |
|------------|------|----------|
| **High** | Lazy shell + silent hydrate failure (network/5xx/timeout) | Empty catch; body empty after chevron flips |
| **High** | API returns `empty: true` → panel removed | `dashboard-team-activity.js` removes panel |
| **Medium** | `initDashboardTeamActivity` never ran (JS error earlier / missing panel attrs) | Init returns null without `data-team-activity-refresh-url` |
| **Medium** | User expects row-body click to expand history | By design — chevron only |
| **Low** | Overlay blocking clicks | No covering overlay found |
| **Low** | Live dashboard poll stripping listeners | Live poll does not touch this panel |

### Recommended fix (do not implement yet)

1. **Confirm in browser:** click header → Network `GET /dashboard/team-activity` (status, JSON `empty`/`html`, timing).
2. **If fetch fails / 5xx:** fix server/build timeout; surface error toast instead of silent catch.
3. **If `empty: true` incorrectly:** fix roster/permissions in `TeamActivityPanelService` — do not remove panel on transient empty during hydrate.
4. **UX hardening (preferred code fix):**
   - Keep shell visible on hydrate failure; show “Unable to load team activity” state
   - Do not `currentPanel.remove()` on first hydrate failure
   - Log `console.warn` / toast on non-OK responses
   - Optionally keep a minimal SSR skeleton message so expand never looks dead
5. **Do not** revert entire lazy-load without measuring — SSR `build()` was expensive; fix the failure/empty handling instead.

### Risk assessment (Issue 2)

| Fix | Risk | Notes |
|-----|------|-------|
| Error UI + stop silent catch / stop remove-on-empty for hydrate | Low | Improves observability; preserves lazy-load win |
| Full revert of lazy shell | Medium | Dashboard TTFB regression (why lazy-load shipped) |
| Touching live-dashboard poll path | Medium | Unrelated; easy to break KPI strip |

---

## Cross-issue timeline

| When | Event |
|------|-------|
| 2026-08-05 ~20:36 IST | `5547a2d` perf sprint — email widget cache + Team Activity lazy shell |
| 2026-08-05 ~23:50 IST | `3437acb` release **v4.0.4** (includes above) |
| Overnight → 2026-08-06 morning | Needs Attention reported stuck at 3; Team Activity expand broken |

Both production symptoms line up with the **v4.0.4 performance sprint**, but for different reasons:

- Issue 1: cache change is a red herring for overnight stickiness; look at backlog vs pipeline.
- Issue 2: lazy shell is a direct behavioral change that makes expand fail closed.

---

## Local environment snapshot (investigation host)

```json
{
  "app_env": "local",
  "inbound_email.enabled": false,
  "inbound_email.gmail.enabled": false,
  "widget_ttl_seconds": 45,
  "cache_store": "database",
  "team_activity_enabled": true,
  "messages_total": 0,
  "needs_human": 0,
  "messages_24h": 0,
  "widget_cache_present": false
}
```

---

## Immediate production checks (ordered)

1. Hard-reload dashboard → note Needs Attention number.  
2. Open Learning Center Needs Human → compare badge to tile.  
3. Run the Issue 1 tinker script + sync/outbox log tails.  
4. On dashboard, click Team Activity → DevTools Network for `/dashboard/team-activity`.  
5. Note: chevron rotate? empty body? panel vanishes? HTTP status?

Do **not** change code until those five checks classify each issue.
