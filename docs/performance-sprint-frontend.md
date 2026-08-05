# Performance Sprint — Frontend

**Date:** 2026-08-05  
**Source audit:** [radium-desk-performance-audit.md](./radium-desk-performance-audit.md)  
**Scope:** Frontend-only. No business-logic / query / cache changes. No CSS/JS bundle splits. No redesign.

---

## Goals (from audit)

| Goal | Status |
|------|--------|
| Reduce unnecessary HTML re-render on live refresh | Done |
| Avoid replacing entire KPI strip when values unchanged | Done |
| Reduce dashboard payload (client + dead assets) | Done (client-side; wire omit deferred) |
| Review CSS/JS loading | Done (findings + icon `font-display`) |
| Remove obvious dead dashboard assets | Done |
| Do not split bundles | Honored |
| Do not redesign | Honored |
| No business logic | Honored |

---

## Changes shipped

### 1. KPI strip — skip / patch instead of blind replace

**Files:** `resources/js/dashboard-kpi-dom.js` (new), `resources/js/live-dashboard.js`

Live polls and Reverb KPI events previously always set `#dashboard-kpi-strip` via `innerHTML`, which:

- Destroyed Bootstrap tooltips and Email Intake hover state
- Forced style/layout work even when Open / Overdue / Intake counts were identical

**New behavior:**

1. **Skip** when structure + metric signature match (tooltip runtime attrs ignored).
2. **Patch** operator KPI values / Email Intake hover counts in place when structure is unchanged but numbers differ.
3. **Replace** only when structure changes (e.g. agent appointment banner appears/disappears).
4. Re-run `initTooltips` only after patch/replace, not after skip.
5. Admin Total Users / Online Users slots use the same skip/patch path.

### 2. Case rows — avoid reorder + tooltip churn

**File:** `resources/js/live-dashboard-merge.js`

Row merge already skipped identical `outerHTML`. It still:

- Re-`appendChild`’d every row on every poll (order “sync”)
- Called `initTooltips(tbody)` even when nothing changed

**New behavior:** reorder only when incident order changes; init tooltips only when DOM content/order actually changed.

### 3. Team Activity — stable HTML compare

**File:** `resources/js/dashboard-team-activity.js`

Compare used raw `outerHTML`, which almost never matched after tooltip init, so the panel was replaced every 30s when expanded.

**New behavior:** strip tooltip runtime attrs, normalize whitespace, then compare before `replaceWith`.

### 4. Filter count labels — write only when changed

**File:** `resources/js/live-dashboard.js`

`applyFilterCounts` updates `textContent` only when the label string differs.

### 5. Dead workspace KPI fallback removed

**File:** `resources/js/workspace/response-handler.js`

Removed legacy `action_stats_html` / `sla_cards_html` DOM writes targeting `#dashboard-action-stats` / `#dashboard-sla-cards` (elements not present on the current dashboard). Workspace refresh already sends `kpi_strip_html` only.

### 6. Orphan Blade partials removed

Audit Finding 20 — unwired maintenance noise:

| Removed | Notes |
|---------|--------|
| `resources/views/dashboard/partials/admin-metrics-strip.blade.php` | Not `@include`d anywhere |
| `resources/views/dashboard/partials/automation-health-card.blade.php` | Not `@include`d anywhere |
| `resources/views/dashboard/partials/sla-alert-cards.blade.php` | Stub comment only |
| `resources/views/dashboard/partials/action-stats.blade.php` | Thin wrapper around `kpi-strip`; unwired |

### 7. CSS/JS loading review

| Check | Result | Action |
|-------|--------|--------|
| Dashboard JS entry | Page-scoped `@vite('resources/js/pages/dashboard.js')` on dashboard only | Keep |
| Global `app.js` | Does not import dashboard / C360 bundles | Keep |
| Monolithic `app.css` (~634 KB built) | Shared by all authenticated pages | **No split** this sprint (per scope) |
| Bootstrap Icons `font-display: block` | Blocks paint until font loads | Redeclare face with `font-display: swap` in `app.css` |
| Dashboard JS ~187 KB | Large but already a dedicated entry | Bundle split deferred |

---

## Payload notes

### What improved now

- Less main-thread work and DOM mutation on typical 30s polls when KPIs/rows are unchanged.
- Smaller maintenance surface (orphan partials + dead client fallbacks).
- Slightly better first-paint icon font behavior (`swap`).

### What did **not** change (intentionally)

Network JSON from `GET /dashboard/live` still includes full `kpi_strip_html` + row HTML every poll. Omitting unchanged KPI HTML requires a small API fingerprint (client sends hash → server omits field). That touches the live controller transport layer and was left out of this frontend-only sprint.

**Recommended follow-up (not done here):**

1. Client stores last applied KPI metric signature on `#dashboard-kpi-strip`.
2. Live query sends `kpi_fp=<signature>`.
3. `DashboardLiveController@refresh` still builds metrics, but omits `kpi_strip_html` when fingerprint matches (returns `kpi_unchanged: true`).
4. Later: true scalar JSON KPIs (audit M5) — larger change, still no redesign of the strip UI.

---

## Tests

| Suite | Coverage |
|-------|----------|
| `tests/js/dashboard-kpi-dom.test.js` | Skip / patch / tooltip-stable equivalence |
| `tests/js/live-dashboard-merge.test.js` | Unchanged rows skip tooltip init |
| Existing live-dashboard / reverb / agent / ops workspace suites | Still pass |

---

## Explicitly out of scope

- Bundle / CSS code-split by surface (audit M7)
- Cross-request KPI cache, Email Intake cache, Team Activity SSR defer (backend)
- Customer360 drawer payload / intelligence cache
- Email Intake hover markup correctness fix (audit Q1 / UX §14) — correctness, not this sprint’s DOM-churn focus
- Redesign of KPI strip or case table

---

## Verification checklist

- [ ] Dashboard open → KPI strip renders as before
- [ ] Wait for a live poll with unchanged KPIs → strip nodes are not replaced (hover/tooltips stay alive)
- [ ] Change a case so Open/Overdue changes → values update without full strip flash when structure same
- [ ] Expand Team Activity → poll does not rebuild panel when content unchanged
- [ ] Workspace assign/remark still refreshes KPI strip via `kpi_strip_html`
- [ ] Icons still render after hard refresh (font-display swap)

---

*End of frontend sprint notes.*
