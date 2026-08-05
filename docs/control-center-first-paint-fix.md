# Control Center First Paint — Test Fix

**Date:** 2026-08-05  
**Scope:** `ControlCenterFirstPaintTest` only  
**Out of scope:** Dashboard architecture, caching, Canvas

---

## 1. Failure

`ControlCenterFirstPaintTest::test_control_center_ssr_caches_first_paint_sections_not_full_dashboard` failed asserting that SSR HTML contains:

- `Loading integration health`
- (also expected) `Loading Ira insights`

The PHPUnit message pointed at the missing `Loading integration health` string. Ira placeholder text was still present.

---

## 2. Determination

| Expectation | Status | Verdict |
|-------------|--------|---------|
| `Loading Ira insights` | Still rendered via `lazy-tab-placeholder` in `operations-ira-briefing-compact` | Keep — placeholder still exists |
| `Loading integration health` | Removed from SSR | **Intentionally changed** — not stale by accident |

### What SSR first paint actually does now

From `resources/views/admin/operations/index.blade.php`:

- **Ira compact** → lazy skeleton: `Loading Ira insights…`
- **Health row** → eager include of `health-status-compact` (no loading stub)

`health-status-compact` is a static Platform demotion strip:

- heading: **Platform Health**
- copy: monitoring moved to Platform
- CTA: **Open Platform Dashboard**

This matches the Mission Control / Platform IA demotion (integration diagnostics live on Platform, not as an Operations first-paint hydrate stub). Related contract coverage already exists in `ExecutiveCommandCenterPhaseETest` (`Open Platform Dashboard`, no integration-health heading embeds).

### Not the cause

- Placeholder text was not a typo-only rename for integration health
- Blade placeholder for Ira was not removed
- First-paint section cache behaviour under test (warm section key, no full `operations:dashboard:latest:v2`, exclude `INTEGRATION_HEALTH` bundle) was unchanged

---

## 3. Fix applied

**Test-only update** in `tests/Feature/Operations/ControlCenterFirstPaintTest.php`:

- Assert Platform Health demotion strip on first paint (`Platform Health`, `Open Platform Dashboard`)
- Assert `Loading integration health` is **absent**
- Keep `Loading Ira insights` (deferred placeholder still intentional)
- Leave cache key / section-bundle assertions unchanged

No production Blade/JS/cache/architecture changes.

---

## 4. Verification

```bash
php artisan test --filter=ControlCenterFirstPaintTest
php artisan test tests/Feature/Operations
```

---

*End of note.*
