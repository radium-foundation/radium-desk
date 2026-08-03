# Platform Mission Control vs System Settings

IA cleanup · Platform = Observe · Settings = Configure

**URLs preserved** · **No monitoring logic changes** · **Diagnostics runtime removed**

## Business rule

Nothing that represents live production monitoring should exist inside System Settings. Platform is the single Super Admin Mission Control.

| Surface | Responsibility |
|---------|----------------|
| **Platform** | Observe — live Mission Control |
| **Settings** | Configure — keys, flags, forms |

---

## 1. Duplication matrix

| Component | Keep | Move | Remove | Reason |
|-----------|------|------|--------|--------|
| Overview config / env / version / audit | Yes | | | Configure |
| Overview Queue/CPU/Workers/Realtime widgets | | Platform | Yes | Live monitoring duplicate |
| Diagnostics CPU/Queue/WebSocket/Failed Jobs | | Platform | Yes | Live monitoring duplicate |
| Diagnostics configured profile/polling/flags | Yes | | | Config snapshot |
| Realtime live Connection status | | Platform | Yes | Live monitoring duplicate |
| Realtime Test/Reconnect + setting rows | Yes | | | Configure tools |
| Admin System Health status pills | | Platform | Yes | Duplicate observe |
| Admin Platform/Settings CTAs | Yes | | | Correct gateways |
| All Platform zones | Yes | | | Mission Control SoT |

---

## 2. Platform coverage (Phase 3)

| Required | Zone | OK |
|----------|------|----|
| Executive Snapshot | `executive_snapshot` | Yes |
| Platform Health | `platform_health` | Yes |
| Integration Health | `integration_health` | Yes |
| Automation | `automation` | Yes |
| Communications | `communications` | Yes |
| Finance | `finance_overview` | Yes |
| Operations | `operations_overview` | Yes |
| Performance | `performance` | Yes |
| Diagnostics | `tools` | Yes |
| Critical Alerts | `critical_alerts` | Yes |

---

## 3. Before / after navigation

### Before

- Settings Overview showed Queue/CPU/Workers
- Diagnostics showed live health metrics
- Realtime showed live connection status
- Admin home showed System Health pills

### After

- Settings Overview = Configuration Overview
- Environment = configured values only
- Realtime = settings + connection tools
- Admin = Observe or configure gateways
- Platform = sole live Mission Control

---

## 4. Files changed

| File | Change |
|------|--------|
| `ConfigurationHealthSummaryService.php` | Env + version/build + tools URL |
| `overview-section.blade.php` | Configuration Overview |
| `diagnostics-section.blade.php` | Environment; runtime removed |
| `realtime-card.blade.php` | Connection tools; no live status |
| `operational-center-section.blade.php` | Integration Health hash |
| `SettingsCenterNav.php` | Configure vs Observe |
| `administration/index.blade.php` | Gateway CTAs only |
| Related feature/unit tests | Assertions updated |

---

## 5. Regression

| Risk | Mitigation |
|------|------------|
| Lose Overview queue glance | Platform links + Mission Control |
| `#category-system` bookmark | ID preserved; title Environment |
| Admin status pills gone | CTAs remain; live status on Platform |
| Routes / health calculators | Unchanged |

---

## Why safe

UI / IA only. No routes, permissions, APIs, DB, cache, migrations, or monitoring logic changes. All observe features remain on Platform.

---

Canvas counterpart: `~/.cursor/projects/Users-ravi-radium-service-desk/canvases/platform-mission-control-ia.canvas.tsx`

Extended report: [platform-mission-control-ia-cleanup.md](./platform-mission-control-ia-cleanup.md)
