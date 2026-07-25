# H5-0 — Refresh Scope Inventory

**Phase type:** Planning only — **no implementation**  
**Date:** 2026-07-25  
**Status:** Complete inventory for H5 approval gate

This document maps every live refresh scope before any Dashboard Refresh Platform work. H5 coordinates timing only; it must not change business logic, Snapshot/ReadModel ownership, Reverb publishers, or payloads.

---

## 1. Refresh scope taxonomy

A **scope** is the smallest unit that shares one HTTP fetch or one Reverb apply path. Widget labels (Ready Queue, Waiting, KPI strip) often share a scope.

| Scope ID | Surface | Primary transport | Safety poll | Endpoint / event |
|---|---|---|---|---|
| `operator.live.full` | Operator dashboard | Reverb + heartbeat/fast poll | 20s / 60s | `GET /dashboard/live` |
| `operator.live.rows` | Operator hybrid row merge | Reverb | heartbeat | `GET /dashboard/live/rows` |
| `operator.kpi.reverb` | KPI strip + filter counts | Reverb `DashboardKpisUpdated` | heartbeat | partial apply |
| `operations.live.partial` | OCC critical/summary/health/ira | Poll only | 30s | `GET /admin/operations/live?groups=…` |
| `operations.live.full` | OCC all sections | Poll only | 120s | `GET /admin/operations/live` |
| `notifications.bell` | Navbar bell | Reverb + poll | 20s | `GET /notifications/poll` |
| `notifications.hybrid` | Calls / toasts / alerts | Reverb | none | `notifications.{userId}` channel |
| `presence.heartbeat` | Team presence | POST | 120s | `POST /presence/heartbeat` |
| `customer360.device` | C360 drawer device sync | Poll | 10s | `…/customer-360/device` |
| `customer360.timeline` | C360 timeline | Poll | 30s | `…/customer-360/timeline` |
| `customer360.ai` | AI workbench | Manual | — | `…/customer-360/ai-workbench` |
| `platform.executive.card` | Mission Control cards | Manual | 60s reserved (unwired) | `GET /admin/platform/cards/{card}` |
| `agent.reminder.local` | Appointment reminder UI | Local timer | 60s | none |

---

## 2. Shared JS modules (current owners)

| Module | Role |
|---|---|
| `live-dashboard.js` | `refreshDashboard`, apply KPIs/rows |
| `live-dashboard-polling.js` | legacy / fast / heartbeat schedulers |
| `live-dashboard-reverb.js` | Echo client, reconnect, event routing |
| `operations-dashboard.js` | OCC 30s / 120s poll |
| `live-notifications.js` | Bell poll |
| `presence-heartbeat.js` | Presence POST + visibility resume |
| `customer-360-drawer.js` | C360 device/timeline poll |

---

## 3. Visibility behaviour (today)

| Scope | Pauses when hidden? | Resume catch-up? |
|---|---|---|
| `operator.live.*` | Fetch blocked; heartbeat paused | Reschedule only (no forced reconcile) |
| `operations.live.*` | **No** | No |
| `notifications.bell` | Skip fetch | No |
| `presence.heartbeat` | Skip | **Immediate** |
| `customer360.*` | **No** | No |

---

## 4. Duplicate / overlap hotspots

1. `performance.polling.dashboard_live_ms` (admin UI) vs `realtime.polling_interval_*` (actual operator intervals).
2. Reverb heartbeat + notification poll + presence + C360 when drawer open.
3. OCC continuous poll while tab hidden.
4. Operator visibility resume without reconciliation.

---

## 5. H5 platform mapping (future — not implemented)

| Platform component | Owns |
|---|---|
| Refresh Manager | Orchestration only |
| Refresh Registry | Scope metadata + adapters |
| Reverb Manager | Existing `live-dashboard-reverb.js` lifecycle |
| Poll Scheduler | Profile mapping: Operational 30s, Summary 60s, Executive configurable, Presence 120s |
| Visibility Manager | Pause/resume rules per scope |
| Reconciliation Engine | Single-flight + coalesce per scope |
| Telemetry | Client metrics (sampled) |

**Rule:** H5 adapters call the same endpoints and apply paths listed above. No new SQL, cache, or KPI formulas.

---

## 6. H5 entry criteria

- [x] H4 complete (ReadModel consumers through operator summary counts)
- [x] Scope inventory frozen (this doc)
- [ ] Product approval for H5-1 facade (behaviour-preserving)
- [ ] Reverb + Ready Queue + Operations live suites remain green on each H5 step

---

## 7. Explicitly out of H5 scope

- `DashboardSnapshot` / `CaseQueueReadModel` ownership
- Queue membership / assignment / SLA formulas
- Reverb event payloads or publishers
- Mission Control KPI definitions (KEEP SEPARATE from operator open/waiting)

**Next:** H5-1 thin Refresh Manager facade (feature-flagged) — **await approval**.
