# P0 — Phase 2 Operations Workspace regression

**Type:** Investigation + wiring-only fix  
**Date:** 2026-08-04  
**Status:** Root cause identified; wiring hardened  
**Canvas:** [`operations-workspace-phase2-regression.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-workspace-phase2-regression.canvas.tsx)  
**Prior:** [Phase 2](./operations-workspace-phase2.md) · [Phase 1](./operations-workspace-phase1.md)

---

## 1. Verdict

Operators still landed on `/incidents?status=active` and `/refunds` whenever KPI markup lacked soft-switch attributes (flag off, stale Blade, or mismatched HTML) or when embed fetch failure fell through to hardcoded legacy URLs. Phase 2 server routes and default config were already present.

| Metric | Value |
|---|---|
| Failure class | Wiring |
| Legacy KPI targets | 2 (`active_cases`, `refunds`) |
| Business logic changes | 0 |
| Rollback | Feature flag |

| Expected click | Must call | Must not navigate |
|---|---|---|
| Total Active Cases | `GET /dashboard/workspace?workspace=active_cases` | `/incidents?status=active` |
| Refunds | `GET /dashboard/workspace?workspace=refunds` | `/refunds` |

---

## 2. Investigation checks

All Phase 2 plumbing was present and tests green in this checkout. The regression path is client wiring when KPI hrefs are still legacy, or when embed AJAX fails.

| Check | Result | Status |
|---|---|---|
| `DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED` | Default true; unset in `.env` → true | Pass |
| `config/dashboard.php` | `operations_workspace_phase2_embed` wired | Pass |
| `.env` value | No override locally (defaults apply) | Pass |
| Dashboard KPI links (Blade) | Emit `/dashboard?workspace=active_cases\|refunds` when flag on | Pass |
| `data-workspace` attributes | Present on Active Cases + Refunds when Phase 2 on | Pass |
| `data-operations-workspace-link` | Emitted with workspace when Phase 2 on | Pass |
| `dashboard-operations-workspace.js` | Phase 2 embed path present in source + Vite build | Pass |
| Event delegation | Click on `#dashboard-page` → `[data-operations-workspace-link]` | Pass |
| `OperationsWorkspaceController` | `GET /dashboard/workspace` registered | Pass |
| `GET /dashboard/workspace` | Feature tests pass (`panel_html`) | Pass |
| History API | `pushState` + `popstate` for workspace soft-switch | Pass |
| Blade rendering | Hosts + flags on `#dashboard-page` | Pass |
| Vite build/version | Rebuilt `dashboard-*.js` with wiring fix | Pass |
| Cached config | No `bootstrap/cache/config.php`; cleared during investigation | Pass |
| Cached views | Cleared; compiled kpi-strip emits soft links | Pass |

### Verified behaviours

| Behaviour | Result |
|---|---|
| Active Cases → embedded Dashboard workspace | Pass (feature + vitest) |
| Refunds → embedded Dashboard workspace | Pass (feature + vitest) |
| Legacy `/incidents` and `/refunds` still work directly | Unchanged |
| Browser Back / Forward | `popstate` soft-switch |
| Dashboard shell never reloads on soft-switch | Sibling hosts hide/show |

---

## 3. Root cause

### Primary

Soft-switch only listened for `[data-operations-workspace-link]`. When Phase 2 Blade markup was missing (flag false, stale compiled views, or mixed deploy), Active Cases / Refunds KPIs kept `href="/incidents?status=active"` and `href="/refunds…"` with **no** soft attributes — the browser did a full navigation. That matches the reported Network tab.

### Secondary

On embed fetch failure, JS fell back with:

```js
location.assign(… ?? '/incidents?status=active' | '/refunds…')
```

This reintroduced legacy navigation even after a soft-switch attempt.

### Not the cause (this checkout)

| Suspect | Finding |
|---|---|
| Feature flag missing from config | Present; default true |
| Route missing | `dashboard.workspace` registered |
| Controller / queries / listing partials | Intact; not modified |
| Vite missing Phase 2 | Build contained `active_cases` + `panel_html` path |

### Decision tree

1. KPI href is `/incidents` or `/refunds`? → legacy Blade path (flag off / stale HTML).
2. Soft-switch attrs absent? → click never intercepted.
3. Soft-switch runs but `/dashboard/workspace` fails? → old code bounced to legacy URLs.
4. Fix: intercept legacy KPI hrefs when Phase 2 on; fallback only to `/dashboard?workspace=`.

---

## 4. Wiring fix

Wiring-only. No business logic, queries, controllers, or listing partials changed.

Soft-switch now maps stale legacy KPI destinations into embedded workspaces when Phase 2 is enabled, and never navigates to `/incidents` or `/refunds` on embed failure.

### Files modified

| File | Change |
|---|---|
| `resources/js/dashboard-operations-workspace.js` | `parseLegacyEmbeddedNavigationTarget`; intercept legacy KPI clicks; dashboard-only fallback; `credentials: 'same-origin'` |
| `tests/js/dashboard-operations-workspace-phase2.test.js` | Legacy intercept + no-`/incidents` fallback coverage |
| `.env.example` | Document `SOFT_SWITCH` + `PHASE2_EMBED` flags |
| `public/build/assets/dashboard-*.js` | Vite rebuild |

### Unchanged on purpose

`IncidentListingQuery`, `RefundListingQuery`, `OperationsWorkspaceController`, listing partials, KPI math, Team Activity, Phase 1 queues.

---

## 5. Test results

| Suite | Result |
|---|---|
| `tests/js/dashboard-operations-workspace-phase2.test.js` | 8 passed |
| `tests/js/dashboard-operations-workspace.test.js` | 4 passed |
| `OperationsWorkspacePhase*` (PHPUnit) | 11 passed |
| `npm run build` (dashboard entry) | `dashboard-B4l0J9Tf.js` |

---

## 6. Rollback

```bash
DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED=false
php artisan optimize:clear
```

KPI links return to `/incidents` and `/refunds`. Phase 1 soft-switch remains unless `DASHBOARD_OPERATIONS_WORKSPACE_SOFT_SWITCH` is also disabled.

Hard refresh the Dashboard after deploy so the new Vite hash loads.
