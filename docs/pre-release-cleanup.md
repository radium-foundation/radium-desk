# Pre-Release Cleanup — Performance Sprint Bundle

**Date:** 2026-08-05  
**Scope:** Performance Sprint, Email Intake, Cashfree Phase A, Customer360, Dashboard, Platform Health  
**Method:** Read-only audit + safe cleanup only (no risky refactors)  
**No Canvas.**

---

## Executive summary

| Area | Status | Notes |
|------|--------|-------|
| Performance Sprint | Clean (code) | Dashboard cache hardening complete (`v2` array payload). Two known test budget failures are deferrable perf/contract drift. |
| Email Intake | **Cleaned** | Removed temporary Gmail OAuth JWT diagnostic logging. |
| Cashfree Phase A | Clean | `CashfreeHealthService` + system-user preflight; integrity tests pass. |
| Customer360 | Clean (code) | Structured drawer timing logs remain (observability, not debug dumps). Seven presentation-test failures deferrable. |
| Dashboard | **Cleaned** | Removed ops JS version banner, orphan Blade partial, duplicate Vite entry. |
| Platform Health | Clean (release) | `PlatformCacheAudit` is temporary but default-off; safe to ship. |

**PHP debug dumps:** None (`dd`, `dump`, `ray`, `var_dump`) in application code.  
**TODO/FIXME/HACK:** None in sprint-touched application code.

---

## Safe cleanups applied

| Change | File(s) | Reason |
|--------|---------|--------|
| Removed temporary OAuth JWT `Log::info` on every uncached token fetch | `app/Services/IncomingEmail/Gmail/GmailAccessTokenService.php` | Production log spam; marked "remove after OAuth grant troubleshooting" |
| Removed test asserting temporary diagnostic log | `tests/Unit/IncomingEmail/Gmail/GmailAccessTokenServiceTest.php` | Paired with logging removal |
| Removed unguarded version `console.info` | `resources/js/operations-dashboard.js` | Mission Control load noise |
| Deleted unused Blade partial | `resources/views/admin/operations/partials/lazy-load-error.blade.php` | Never `@include`d; JS builds inline error UI |
| Removed duplicate Vite entry | `vite.config.js` | `service-case-show.js` already imported by `pages/service-cases.js` |
| Added superseded/update banners | `docs/dashboard-snapshot-cache-safety-investigation.md`, `docs/performance-release-blockers.md`, `docs/performance-sprint-dashboard.md` | Prevent stale investigation docs from misleading release reviewers |

---

## Area reviews

### Performance Sprint

**Dashboard snapshot cache**

- `OperatorDashboardCache` uses `operator.dashboard.snapshot:v2` with `ActiveIncidentSnapshotPayload` (plain arrays, never Eloquent).
- Legacy `v1` keys forgotten on write/forget — migration cleanup, not duplication.
- Canonical record: [dashboard-snapshot-cache-hardening.md](./dashboard-snapshot-cache-hardening.md).

**Known test failures (deferrable)**

| Test | Status | Classification |
|------|--------|----------------|
| `DashboardTeamActivitySortingAndIraTest::test_panel_build_query_count_stays_bounded` | Fails (~194 queries vs bound 120) | Perf budget guard; workforce/attendance cost grew since bound was set. Not a cache correctness regression. |
| `WorkspaceDashboardAssignTest::test_dashboard_assign_action_returns_row_and_kpi_refresh_payload` | Fails (no `replace_row`) | Intentional `remove_row` when case leaves Admin Ready queue. Frontend handles correctly. |
| `Customer360RadiumBoxSyncTest` (7 methods) | Fails | Sync functional; assertion drift on device-card presentation copy/markup. |

**Cashfree blocker resolved:** `CashfreePaymentIntegrityTest` passes after Phase A (`phpunit.xml` force + `EnsuresCashfreeSystemUser`).

### Email Intake

- No remaining temporary logging in Gmail token path.
- Error-path logging (`Log::error` on OAuth rejection) retained — intentional, redacts secrets.
- Feature flag `inbound_email.enabled` (System Settings) correctly gates ingestion.

### Cashfree Phase A

**Architecture (not duplicate helpers):**

| Class | Role |
|-------|------|
| `CashfreeHealthService` | Read-only self-test DTO (`CashfreeHealthReport`), system-user preflight |
| `OperationsCashfreeHealthService` | Ops widget + 30s cache; delegates to health service + integrity read model + probe |
| `CashfreeIntegrationHealthProbe` | Platform Health probe boundary |

All new Phase A artifacts are referenced and tested. No unused services.

### Customer360

- No unguarded frontend `console.log` in drawer modules.
- `Customer360Controller` emits structured `Log::info('customer360.drawer.*')` with timings — observability from performance audit, not temporary debug. **Defer** trimming until post-sprint log-volume review.
- No unused imports in modified sprint files.

### Dashboard

**Dead config (documented, not removed):**

`config/dashboard.php` keys `live_mode` and `poll_interval_ms` are **never read in PHP**. Runtime uses `RealtimeRuntimeConfig` + System Settings (`realtime.provider`, polling intervals). `.env.example` and several Reverb docs still reference `DASHBOARD_LIVE_MODE` — config drift only, not a functional bug.

**Active rollback flags (keep):**

- `operations_workspace_soft_switch`, `phase2_embed`, `phase3_native`
- `snapshot_cache_enabled`, `snapshot_cache_ttl_seconds`
- `slow_scalars_cache_ttl_seconds`

**Realtime debug (intentional, gated):**

- `data-realtime-debug` / `data-realtime-lifecycle-debug` on dashboard root
- `live-dashboard-reverb.js`, `dashboard-refresh-lifecycle.js` — `console.debug`/`warn` only when flags set
- `keyboard/index.js` — gated by `localStorage radium.keyboardDebug`

### Platform Health

**`PlatformCacheAudit` (temporary, default-off)**

- Class doc: "Temporary P0 investigation auditor"
- Enabled only via `PLATFORM_CACHE_AUDIT=true`
- Wired into ~12 Platform services; no-op unless enabled
- **Defer** full removal until snapshot corruption investigation closes

**`InteraktProbeTemplateSendCommand` (temporary diagnostic)**

- Doc: "Safe to delete after P04-07-006 investigation"
- Not referenced outside its file; Laravel auto-discovers it
- **Defer** deletion until P04-07-006 is confirmed closed

---

## Checklist results

| Check | Finding |
|-------|---------|
| Debug code | No PHP dumps. Unguarded JS: ops version banner **removed**. Gmail temp log **removed**. Guarded debug retained (realtime, keyboard, BonVoice latency probe). |
| TODO/FIXME | None in sprint application code |
| Temporary comments | Gmail diagnostics **removed**. PlatformCacheAudit + InteraktProbe **defer** |
| Dead feature flags | `dashboard.live_mode` / `poll_interval_ms` unread — document drift, safe to remove post-release with doc sync |
| Duplicate helpers | Cashfree health stack is layered, not duplicated — **keep** |
| Obsolete Blade | `lazy-load-error.blade.php` **deleted** |
| Unused services | None in Phase A / dashboard hardening set |
| Stale docs | Superseded banners added to three investigation docs; untracked sprint docs reconciled below |
| Orphan CSS | None — `.operations-lazy-error` in `app.css` still used by JS |
| Orphan JS | Duplicate Vite entry **removed**; no other confirmed orphans in sprint scope |
| Unused imports | None in modified PHP files reviewed |

---

## Stale / untracked docs (git status)

| Doc | Action |
|-----|--------|
| [dashboard-snapshot-cache-hardening.md](./dashboard-snapshot-cache-hardening.md) | **Keep** — canonical dashboard cache record |
| [cashfree-phase-a-hardening.md](./cashfree-phase-a-hardening.md) | **Keep** — pair with release notes |
| [cashfree-integrity-root-cause.md](./cashfree-integrity-root-cause.md) | **Keep** — audit trail |
| [control-center-first-paint-fix.md](./control-center-first-paint-fix.md) | **Keep** — aligns with `ControlCenterFirstPaintTest` |
| [dashboard-snapshot-cache-safety-investigation.md](./dashboard-snapshot-cache-safety-investigation.md) | **Superseded banner added** |
| [performance-release-blockers.md](./performance-release-blockers.md) | **Update banner added** (Cashfree resolved) |
| [performance-sprint-regression-analysis.md](./performance-sprint-regression-analysis.md) | **Keep** — historical root-cause; many items since resolved |
| [performance-sprint-dashboard.md](./performance-sprint-dashboard.md) | **v2 note added** |

---

## Tests verified during cleanup

```
GmailAccessTokenServiceTest          4/4 passed
CashfreePaymentIntegrityTest         passed
CashfreeHealthVisibilityTest         passed
CashfreeSystemUserPreflightTest      passed
ControlCenterFirstPaintTest          passed
OperatorDashboardCacheTest           passed
IncomingEmailIntakePhase1Test        passed

DashboardTeamActivitySortingAndIraTest::test_panel_build_query_count_stays_bounded  FAILED (defer)
WorkspaceDashboardAssignTest::test_dashboard_assign_action_returns_row_and_kpi_refresh_payload  FAILED (defer)
Customer360RadiumBoxSyncTest         7 FAILED (defer — presentation)
```

---

## Post-release backlog (do not block push)

1. Remove `PlatformCacheAudit` + call sites + `PlatformCacheAuditReproductionTest` after P0 investigation closes.
2. Delete `InteraktProbeTemplateSendCommand` when P04-07-006 is closed.
3. Remove dead `live_mode` / `poll_interval_ms` from `config/dashboard.php`; update `.env.example` and Reverb docs to point at System Settings.
4. Rebaseline or relax Team Activity query-count test bound.
5. Update `WorkspaceDashboardAssignTest` to assert `remove_row` when appropriate.
6. Refresh `Customer360RadiumBoxSyncTest` assertions to match current device-card markup.
7. Trim Customer360 drawer structured logs if log volume is a concern.
8. Gate or remove BonVoice incoming latency `console.info` after IVR latency sprint closes.

---

## Release guard

Latest tag: `v4.0.3`. Next release would be `v4.0.4`.

**`CHANGELOG.md` has no `4.0.4` entry.** Per release workflow, draft and approve changelog before tag/push/deploy.

Suggested bullets for `4.0.4`:

- Dashboard snapshot cache hardening (array payload, cross-request cache)
- Cashfree Phase A production hardening (system-user preflight, Platform Health visibility)
- Control Center first-paint improvements
- Email intake OAuth diagnostic cleanup
- Operations dashboard lazy-load cleanup

---

## Verdict

### Remaining items

| Item | Reason | Blocks push? |
|------|--------|--------------|
| `CHANGELOG.md` missing `4.0.4` | Release workflow requirement | **Yes** — before tag/deploy |
| 9 known failing tests in sprint scope | 2 deferrable perf/contract; 7 Customer360 presentation | **Maybe** — if CI runs full suite without allowlist |
| `PlatformCacheAudit` temporary wiring | Default-off investigation tool | No |
| `InteraktProbeTemplateSendCommand` | Temporary diagnostic command | No |
| Dead `DASHBOARD_LIVE_MODE` config keys | Doc/config drift only | No |
| Customer360 structured drawer logs | Observability, not debug | No |

**Safe cleanup on the branch is complete.** The branch is ready to push for review once CHANGELOG is drafted and CI expectations for deferrable test failures are confirmed. Do **not** tag or deploy until `CHANGELOG.md` is approved and the release checklist is satisfied.
