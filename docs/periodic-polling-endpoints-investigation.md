# Periodic Polling Endpoints — Production Investigation

**Status:** Read-only (no code changes)  
**Captured:** 2026-08-07  
**Production runtime:** `realtime.provider=auto` → effective **Ably**; performance profile **balanced**

**Canvas:** [`periodic-polling-endpoints-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/periodic-polling-endpoints-investigation.canvas.tsx)

---

## Summary

There are **10 client-side HTTP polling endpoints**. Two run on every authenticated page (`/notifications/poll`, `/presence/heartbeat`). The operator dashboard commonly stacks **4+ timers** (live heartbeat, notifications, presence, My Activity, optional Team Activity / Customer360 / email).

Email Operations has **no dedicated poller** — it is a Platform zone refreshed only when stale/expanded via the 60s Mission Control timer.

---

## Production intervals (live settings)

| Setting | Value |
|---------|------:|
| `realtime.polling_interval_active_seconds` | 20 |
| `realtime.polling_interval_idle_seconds` | 60 |
| `performance.polling.notification_ms` | 20000 |
| `performance.polling.operations_ms` | 30000 |
| `performance.polling.operations_full_refresh_ms` | 120000 |
| `performance.polling.customer360_timeline_ms` | 30000 |
| `performance.polling.customer360_device_sync_ms` | 10000 |
| `performance.polling.presence_heartbeat_seconds` | 120 |
| `performance.polling.executive_dashboard_seconds` | 60 |
| Activity / Team Activity (config) | 30000 |
| Email thread (`POLL_MS`) | 20000 (hardcoded) |

With Ably healthy, `/dashboard/live` uses **heartbeat** mode: **60s** (slows to **300s** after 5 minutes user idle; pauses when tab hidden). Fast fallback uses the **20s** active interval.

---

## Endpoint table

| Endpoint | Interval | Purpose | Business criticality | Recommended interval | Can merge? | Can become manual? |
|----------|----------|---------|----------------------|----------------------|------------|--------------------|
| `GET /dashboard/live` | Heartbeat 60s / 300s idle; fast fallback 20s; legacy 20s/60s | KPI strip + case rows + filters | Critical | Keep heartbeat 60s; fast fallback 30s | Absorb My Activity | No |
| `GET /notifications/poll` | 20s (all auth pages) | Bell badge + HTML + new items | High (calls) / Medium | 45–60s or pause when Echo connected | Dashboard already has Ably `NotificationCreated` | Partial |
| `POST /presence/heartbeat` | 120s (+ on tab visible) | Presence / idle logout | High (WFM) | Keep 120s | No | No |
| `GET /dashboard/activity` | 30s | My Activity feed HTML | Low–Medium | 60–120s | **Yes → `/dashboard/live`** | Yes |
| `GET /dashboard/team-activity` | 30s while expanded + active | Team Activity roster | Medium | 60s while expanded | Optional presence snapshot | Yes when collapsed (already) |
| `GET /admin/operations/live` | 30s partial; full every 120s | OCC sections | High (admin) | 45–60s / full 180s | No | Partial (critical groups only) |
| `GET /admin/platform/zones/{zone}` | 60s; stale/unavailable priority or expanded only | Mission Control zones | Medium | Keep 60s + stale-only | Email Ops has no separate poller | Yes for non-priority (already) |
| `GET …/customer-360/timeline` | 30s while drawer open | C360 timeline | Medium | 45–60s; pause when hidden | Also one-shot from email poll | Yes on open/focus |
| `GET …/customer-360/device` | 10s while `should_poll_sync` | Device sync status | Medium (sync only) | 15–20s; stop when done (already) | No | No during active sync |
| `GET …/email-thread` | 20s hardcoded while modal open | Newer inbound email messages | High while in email workspace | 30–45s; wire to settings; pause hidden | Prefer Ably email events | Partial |

---

## Hotspots

### Duplicate polling
- **Notifications:** Ably `notifications.{userId}` / `NotificationCreated` on the dashboard **plus** global HTTP poll every 20s on all pages.
- **C360 timeline:** Periodic 30s timer **plus** one-shot refresh after email-thread poll finds new messages.

### Nested timers
- Customer360 drawer open + email modal: timeline (30s) + device (10s if syncing) + email-thread (20s).
- Not nested `setInterval` on the same URL inside one module — modules use single-flight timers.

### Hidden-tab polling
| Endpoint | Behaviour when tab hidden |
|----------|---------------------------|
| `/dashboard/live` (heartbeat) | Pauses |
| `/admin/operations/live` | Interval stopped; catch-up on visible |
| Platform zones | Interval stopped |
| Notifications / presence / activity / team-activity | Timer continues; **fetch skipped** |
| C360 timeline / device / email-thread | **Continues polling** |

### Multiple dashboard timers
On a typical visible operator dashboard (Ably OK): live heartbeat + notifications + presence + My Activity + optional Team Activity = **5 independent timers**. Add C360/email when drawer/modal open.

### Notification loops
Bell can update from Ably events and from `/notifications/poll` in the same session. Off-dashboard pages rely on poll only (no Echo subscription in global shell).

### Customer360 polling
- Timeline: 30s, no hidden pause  
- Device: 10s while syncing  
- Email thread: 20s hardcoded (not in performance settings)

### Email Operations polling
- **No dedicated endpoint timer**
- Zone key `email_operations` on Platform — **not** in `PRIORITY_AUTO_REFRESH_ZONES` (`critical_alerts`, `executive_snapshot`, `platform_health`, `integration_health`)
- Refreshed when expanded or marked stale on the 60s platform poll
- Operator email KPI widget is embedded in `/dashboard/live` HTML

---

## Technical detail (size / DB / cache / concurrency)

| Endpoint | Trigger | Avg response size (est.) | DB / cache | Concurrent browser | Timer overlap |
|----------|---------|--------------------------|------------|--------------------|---------------|
| `/dashboard/live` | Heartbeat / fast / legacy; workspace can stop | 50–300 KB | Active-incident snapshot cache 15–30s + slow scalars | 1 chain per tab | `refreshInFlight` + single timeout |
| `/notifications/poll` | Global `setInterval` | 2–15 KB | Count + latest 10 notifications; **no cache** | 1 per tab | No in-flight lock |
| `/presence/heartbeat` | Global interval + visibility | &lt;1 KB | PresenceEngine write | 1 per team tab | Interval reset after success |
| `/dashboard/activity` | Dashboard timeout chain | 5–40 KB | Activity streams; no dedicated cache | 1 | `refreshInFlight` |
| `/dashboard/team-activity` | Expanded panel | 20–150 KB | Roster build each poll | 1 | `refreshInFlight` + 5m idle gate |
| `/admin/operations/live` | OCC interval | 30–200 KB | Section cache in `OperationsDashboardService` | 1 | Single interval; no in-flight lock |
| Platform zones | 60s → up to 3 zone GETs | 5–80 KB | Snapshot store / warmers (`from_cache`) | ≤3 concurrent zone fetches | Stale-only gate |
| C360 timeline | Drawer open | 10–80 KB | Timeline query | 1 | Generation guard |
| C360 device | Syncing | 5–30 KB | Device payload | 1 | Stops when sync done |
| email-thread | Modal open | 5–100 KB (≤50 msgs) | Incoming email queries | 1 | Skips while sending |

**Event-driven (not periodic):** `GET /dashboard/live/rows` — Ably row merge only.

---

## Request rate estimate

**Scenario:** 6 operator dashboard tabs (Ably healthy), 1 OCC, 1 Platform, 2 C360 open (1 with email modal), Team Activity expanded on 3 dashboards, all tabs visible.

| Source | Current req/min | Optimized req/min |
|--------|---------------:|------------------:|
| Notifications (8 tabs @ 20s) | 24 | 8–10 |
| Presence (8 @ 120s) | 4 | 4 |
| Dashboard live heartbeat (6 @ 60s) | 6 | 6 |
| My Activity (6 @ 30s) | 12 | 0–3 |
| Team Activity (3 @ 30s) | 6 | 3 |
| OCC (1 @ 30s) | 2 | 1 |
| Platform (stale fan-out) | 1–3 | 1–2 |
| C360 timeline (2 @ 30s) | 4 | 2 |
| C360 device (0–1 @ 10s) | 0–6 | 0–3 |
| Email thread (1 @ 20s) | 3 | 1.5–2 |
| **Total** | **~72 / min** | **~38 / min** |

**Ably outage / fast fallback:** +~12 /min from dashboards alone → **~85–95 /min** for the same scenario. That increases Hostinger MySQL new-connection pressure (see `docs/mysql-2002-operation-not-permitted-investigation.md`).

Estimates are model-based (not access-log measured). Production had ~138 DB sessions with activity in 30 minutes across 16 users — concurrent open tabs are lower.

---

## Highest-value optimizations (recommendations only)

1. **Slow or pause `/notifications/poll` when Ably/Echo already delivers** (biggest always-on cost).
2. **Merge My Activity into `/dashboard/live`** (removes a 30s HTML poll per dashboard tab).
3. **Pause C360 + email-thread when `document.hidden`**.
4. **Wire email-thread interval into performance settings** (currently hardcoded 20s).
5. Keep Ably healthy — fast fallback multiplies `/dashboard/live` cost.

---

## Sources

- `resources/js/live-dashboard-polling.js`, `live-dashboard-reverb.js`, `live-notifications.js`, `presence-heartbeat.js`, `dashboard-activity-refresh.js`, `dashboard-team-activity.js`, `operations-dashboard.js`, `platform-dashboard.js`, `customer-360-drawer.js`, `service-case-email-workspace.js`
- Controllers under `app/Http/Controllers/*` for each route
- Production System Settings via read-only SSH (`SystemSettingsService` / `PerformanceRuntimeConfig` / `RealtimeRuntimeConfig`)
- Related: `docs/super-admin-h5-0-refresh-scope-inventory.md` (partially superseded — activity/team-activity added later; OCC now pauses when hidden)
