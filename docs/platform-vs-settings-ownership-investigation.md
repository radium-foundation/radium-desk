# Platform vs System Settings ownership

Information-architecture cleanup · Phase 1 analysis + Phase 2 Overview ownership fix

**URLs preserved** · **No business logic changes** · **Overview → Configuration Health**

## Boundary after Phase 2

Platform owns runtime monitoring. System Settings Overview now shows configuration presence only (Cashfree, Gmail, Telegram, Interakt, Meta, SMTP, last change, audit shortcut) plus links to Platform.

| Surface | Responsibility |
|---------|----------------|
| **Platform** | Runtime / alerts / diagnostics |
| **Settings** | Config / flags / preferences |
| **Overview** | Config health only |

---

## 1. Classification matrix

| Component | Current location | Class | Correct owner | Duplicate? | Move risk |
|-----------|------------------|-------|---------------|------------|-----------|
| Overall / Platform / Integration health | Platform zones | Runtime | Platform | No | — |
| Scheduler / Queue / DB / Cache / Storage probes | Platform Health | Runtime | Platform | No | — |
| Automation Health + Scheduler & Workers | Platform Automation | Runtime | Platform | No | — |
| Critical Alerts / Performance / Ops KPIs | Platform zones | Runtime | Platform | No | — |
| Overview: Queue / Workers / CPU / Memory / Response | Settings Overview (before) | Runtime | Platform | Yes vs Platform | Low — display only |
| Overview: Realtime connection widget | Settings Overview (before) | Runtime | Platform (+ Settings Realtime config) | Yes vs Realtime card | Low |
| Overview: Configuration Health widgets | Settings Overview (after) | Configuration | System Settings | No | — |
| Operational Center / channel toggles | System Settings | Configuration | System Settings | No | — |
| Realtime / Performance / Automation toggles | System Settings | Configuration | System Settings | No | — |
| Diagnostics health metrics grid | System Settings Diagnostics | Runtime (page-load) | Platform (future) | Partial vs Platform | Medium — Phase 2 left in place |
| Audit History drawer | System Settings chrome | Administration | System Settings | No | — |
| Users / Roles / Permissions | Administration (not these pages) | Administration | Administration | No | — |

---

## 2. Before / after ownership

### Before

**System Settings Overview**

Realtime, Polling, Queue, Browser Sync, Memory, Workers, CPU, Response Time — runtime duplicates of Platform.

**Platform**

Full runtime mission control (already correct owner).

### After (Phase 2)

**System Settings Overview**

Cashfree / Gmail / Telegram / Interakt / Meta / SMTP configured? Last configuration change · Audit History shortcut · links to Platform monitoring.

**Platform**

Unchanged single source of truth for runtime health, alerts, diagnostics, performance, operations.

---

## 3. Files affected

| File | Change |
|------|--------|
| `app/Services/Administration/ConfigurationHealthSummaryService.php` | New — config presence summary only |
| `app/Http/Controllers/OperationalSystemSettingsController.php` | Pass configurationHealth to view |
| `resources/views/.../overview-section.blade.php` | Runtime widgets → Configuration Health |
| `resources/views/.../diagnostics-section.blade.php` | Description clarifies Platform owns live monitoring |
| `app/Support/Settings/SettingsCenterNav.php` | Nav label Overview → Configuration Health (same hash) |
| `tests/Unit/Administration/ConfigurationHealthSummaryServiceTest.php` | New unit coverage |
| `tests/Feature/OperationalSystemSettingsTest.php` | Assert new Overview; no runtime widget dupes |
| `tests/Feature/Performance\|RealtimeSystemSettingsTest.php` | Align stale assertSee labels with current UI |

---

## 4. Regression analysis

| Risk | Mitigation |
|------|------------|
| Operators lose Overview queue/CPU glance | Links to Platform monitoring; Diagnostics section still shows page-load metrics |
| Bookmark `#section-overview` | ID preserved; title text only changed |
| Routes / permissions / APIs | Unchanged |
| Health calculation / warmers | Unchanged — new service only reads config/settings flags |
| Settings pages / forms | Unchanged — no settings moved |

---

## 5. New boundary (operator view)

**`/admin/system-settings#section-overview`** — Configuration Health widgets + “Open Platform monitoring” / “Open Integration Health” + Audit History button.

**`/admin/platform`** — Runtime health, alerts, scheduler/workers, queues, integration live status, performance.

Diagnostics (`#category-system`) still shows page-load CPU/queue snapshot for the settings form; Phase 2 intentionally did not strip that section.

---

## Why this is safe

Display ownership only. No routes, permissions, APIs, DB, config files, or Platform health calculators changed. Configuration checks reuse the same presence signals already used elsewhere (mail driver, API keys, system setting flags). Features remain reachable via Platform links and existing Settings sections.
