# Platform Mission Control vs System Settings — IA Cleanup

**Business rule:** Platform = Observe · System Settings = Configure

Information-architecture cleanup only. Routes, permissions, APIs, DB, cache, migrations, and monitoring logic unchanged.

---

## Phase 1 — Duplication matrix

| Component | Keep | Move | Remove | Reason |
|-----------|------|------|--------|--------|
| Overview config widgets (Cashfree/Gmail/Telegram/Interakt/Meta/SMTP) | ✓ | | | Configure — configuration presence |
| Overview Environment / Version / build | ✓ | | | Configure — environment summary |
| Overview Last change + Audit History | ✓ | | | Configure / Administration |
| Overview links to Platform | ✓ | | | Observe entry point |
| Overview Queue / Workers / CPU / Memory / Response / Realtime / Browser Sync / Polling | | → Platform | ✓ (already removed) | Live monitoring duplicate |
| Diagnostics CPU / Memory / Queue / Failed Jobs / WebSocket / Response Time | | → Platform | ✓ | Live monitoring duplicate |
| Diagnostics Memory peak / Polling active | | → Platform | ✓ | Live runtime signals |
| Diagnostics configured profile / polling / broadcast / provider / hybrid flags | ✓ | | | Configure — read-only config snapshot |
| Realtime live Connection status / last connected / polling active | | → Platform | ✓ | Live monitoring duplicate |
| Realtime Test / Force Reconnect / Reset + setting rows | ✓ | | | Configure — admin tools + toggles |
| Operational Center / notifications / performance profiles / automation toggles / advanced | ✓ | | | Configure |
| Administration “System Health” status pills | | → Platform | ✓ | Duplicate observe surface |
| Administration Open Platform / Open Settings CTAs | ✓ | | | Correct dual entry points |
| Platform zones (alerts, exec, health, integrations, performance, automation, communications, finance, operations, tools) | ✓ | | | Observe — single Mission Control |
| Mission Control nav Platform Health tab | ✓ | | | Observe — stays on Platform |

---

## Phase 2 — Configuration Overview

System Settings Overview (`#section-overview`) is **Configuration Overview** only:

- Connected / Not configured for Cashfree, Gmail, Telegram, Interakt, Meta, SMTP
- Environment summary
- Version / build
- Last configuration change
- Audit History shortcut
- Links: Platform monitoring · Integration Health · Tools & Diagnostics

Runtime cards removed from Overview (prior phase) and from Diagnostics / Realtime Connection (this phase).

---

## Phase 3 — Platform is single source of truth (verified)

| Required surface | Platform zone | Present |
|------------------|---------------|---------|
| Executive Snapshot | `executive_snapshot` | ✓ |
| Platform Health | `platform_health` | ✓ |
| Integration Health | `integration_health` | ✓ |
| Automation | `automation` | ✓ |
| Communications | `communications` | ✓ |
| Finance | `finance_overview` | ✓ |
| Operations | `operations_overview` | ✓ |
| Performance | `performance` | ✓ |
| Diagnostics | `tools` (Tools & Diagnostics) | ✓ |
| Critical Alerts | `critical_alerts` | ✓ |

No equivalent live monitoring widgets remain on System Settings.

---

## Phase 4 — Navigation cleanup

| Want | Go to |
|------|-------|
| Runtime health / alerts / diagnostics | **Platform** (`/admin/platform`) |
| Edit settings / keys / flags | **System Settings** (`/admin/system-settings`) |

Changes:

- Settings sidebar: “Diagnostics” → **Environment** (config snapshot); new **Observe → Platform monitoring**
- Overview / Operational Center CTAs point at Platform zones
- Administration home: status strip replaced with **Observe or configure** gateway (Platform vs Settings) — no live health pills

---

## Phase 5 — Constraints honored

- Existing routes and permissions preserved (`#section-overview`, `#category-system`, `#realtime-settings-card` IDs kept)
- No database / API / cache / migration / monitoring-logic changes
- UI / IA only

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/Administration/ConfigurationHealthSummaryService.php` | Env + version/build + Platform tools URL |
| `resources/views/admin/system-settings/partials/overview-section.blade.php` | Configuration Overview + env/version widgets |
| `resources/views/admin/system-settings/partials/diagnostics-section.blade.php` | Environment config snapshot; runtime metrics removed |
| `resources/views/admin/system-settings/partials/realtime-card.blade.php` | Live connection card → Connection tools + Platform link |
| `resources/views/admin/system-settings/partials/operational-center-section.blade.php` | Integration Health hash fixed |
| `app/Support/Settings/SettingsCenterNav.php` | Configure vs Observe groups |
| `resources/views/admin/administration/index.blade.php` | Gateway CTAs; no live status pills |
| Tests (Operational / Performance / Realtime / Administration / ConfigurationHealth unit) | Assertions updated |

---

## Before / after navigation

### Before

```
System Settings Overview  → Queue, CPU, Memory, Workers, Realtime…
System Settings Diagnostics → Live CPU/Queue/WebSocket…
System Settings Realtime → Live connection status
Administration → System Health status pills (Platform + Integration)
```

### After

```
System Settings Overview  → Configuration Overview (config presence, env, version, audit)
System Settings Environment → Configured values only + link to Platform Tools
System Settings Realtime → Settings form + Connection tools (no live status widgets)
Administration → Observe or configure (links only)
Platform              → Sole Mission Control for live monitoring
```

---

## Regression analysis

| Risk | Mitigation |
|------|------------|
| Operators miss Overview queue/CPU glance | Explicit Platform links; Mission Control remains primary |
| Diagnostics bookmark `#category-system` | ID preserved; title → Environment |
| Realtime “Connection Status” string in tests/UI | Replaced with Connection tools; admin actions retained |
| Administration status pills gone | Gateway CTAs remain; live status on Platform |
| Routes / permissions / health calculators | Unchanged |

---

## Operator boundary (screenshots as description)

**`/admin/system-settings#section-overview`**  
Configuration Overview grid: Cashfree…SMTP · Environment · Version/build · Last change · Audit · buttons to Platform.

**`/admin/system-settings#category-system`**  
Environment & configuration snapshot (profile, configured polling, broadcast driver, hybrid flags) — no CPU/Queue widgets.

**`/admin/system-settings#realtime-settings-card`**  
Connection tools (Test / Reconnect / Reset) + setting rows; no live connected/polling pills.

**`/admin/platform`**  
Unchanged Mission Control zones listed in Phase 3.

**`/admin/administration`**  
“Observe or configure” card with Open Platform / Integration Health / System Settings.

---

## Why this is safe

Display and navigation ownership only. No business logic, scheduler, queue workers, health thresholds, cache keys, or APIs were modified. All former live-monitoring features remain available on Platform.
