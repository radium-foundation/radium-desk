# Performance Sprint — Email Intake (Q1–Q3)

**Date:** 2026-08-05  
**Source:** `docs/radium-desk-performance-audit.md` Quick Wins Q1, Q2, Q3  
**Scope:** Presentation fix + read-only categorization + short-TTL widget cache  
**Out of scope:** Gmail API, routing, business logic, UI redesign, Canvas

---

## Delivered

### Q1 — Email Intake KPI hover

**Problem:** Hover tooltip showed only the divider.

**Fixes:**
- Replaced invalid `<dl>` / nested `<div>` / `<dt>` / `<dd>` markup with `<div>` rows and label/count spans in `resources/views/dashboard/partials/email-intake-kpi-card.blade.php`.
- Set `.dashboard-kpi-strip-host { overflow: visible }`.
- While Email Intake KPI is hovered/focused, `.dashboard-kpi-strip` uses `overflow: visible` so the tooltip is not clipped by the horizontal scroller.
- Raised tooltip `z-index` to 40.

### Q2 — Categorization read-only on dashboard

**Problem:** `IncomingEmailAttentionCategoryService::categorize()` called `matchAndAudit()`, so dashboard/KPI reads could write `incoming_email.priority_detected` audit rows.

**Fixes:**
- Categorize now uses `IncomingEmailPriorityPhraseService::match()` only.
- `matchAndAudit()` runs during ingest processing in `IncomingEmailProcessorService` (after filter pass; also idempotent in the failure path).

### Q3 — Cache dashboard widget (30–60s)

**Fixes:**
- `IncomingEmailIntakeCounterService::dashboardWidget()` caches the built payload under `incoming_email:dashboard_widget:{Y-m-d}`.
- TTL from `config('inbound_email.dashboard_widget_cache_seconds')` (env `INBOUND_EMAIL_DASHBOARD_WIDGET_CACHE_SECONDS`, clamped 30–60, default 45).
- Cache forgotten after each `IncomingEmailProcessorService::process()` so ingest keeps the strip reasonably fresh.
- No Gmail API on this path.

---

## Files touched

| File | Change |
|------|--------|
| `resources/views/dashboard/partials/email-intake-kpi-card.blade.php` | Valid hover markup |
| `resources/css/app.css` | Overflow / z-index / label styles |
| `app/Services/IncomingEmail/IncomingEmailAttentionCategoryService.php` | Read-only `match()` |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | Cache + forget helper |
| `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | Ingest audit + cache forget |
| `config/inbound_email.php` | Widget cache TTL |
| `tests/Feature/IncomingEmail/IncomingEmailDashboardV2Test.php` | Hover, read-only, ingest audit, cache |
| `tests/Unit/IncomingEmail/IncomingEmailPriorityPhraseServiceTest.php` | `match` vs `matchAndAudit` |

---

## Verification

```bash
php artisan test --filter=IncomingEmailDashboardV2Test
php artisan test --filter=IncomingEmailPriorityPhraseServiceTest
```

Manual: open dashboard as admin → hover Email Intake KPI → confirm Sales / Orders / Escalations and ignored rows render above the card.

---

## Notes

- Existing Needs Review rows created before this change may lack priority audits until reprocessed; categorization still classifies them via read-only `match()`.
- Admin intake queue pages and `counts()` / `visibleCounters()` are unchanged (not part of the widget cache).
