# Dashboard realtime + Shiprocket status investigation

**Prompt ID:** RadiumDesk-P-07-09-289  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Branch:** `main` @ `dbfd7256`  
**Type:** Investigation only — no code, DB, production, Shiprocket mutation, or Ably test events.

---

## Pre-investigation

| Item | Value | Class |
|------|-------|-------|
| Repository | `/agent/repos/radium-desk` → `github.com/radium-foundation/radium-desk` | VERIFIED |
| Branch | `main` | VERIFIED |
| HEAD | `dbfd72560f1f349f6dc19f889daf4d152375058b` | VERIFIED |
| Worktree | Clean at investigation time | VERIFIED |
| Remote | `origin/main` matches local HEAD | VERIFIED |
| Production deploy mechanism | Named-file overlay to KVM `/var/www/radium-desk` (`tools/config.sh`); not `deskd` / not full `deploy-kvm.sh --delete` | VERIFIED (docs + prior gates) |
| Ledger | `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md` on this line) | VERIFIED |

### Relevant recent commits (dashboard / hardware / Ably / Shiprocket)

| Commit / prompt | Summary |
|-----------------|---------|
| `27feec21` / P-07-09-92 | Services-style Hardware dashboard workspace + sub-queues Ready/Exceptions/Pickup/Completed |
| `1a8c2e83` / P-07-09-93 | Production overlay of P-92 UI |
| `aa4aaa8e`, `09f2986f`, `494b0c35` / P-07-09-153 | Recovered Commerce Open Fulfilment; real blockers in product cell; recoverable errors off Exceptions |
| `bab4b61c` | Hardware top chip aligned to work-queue total (not all RDE incidents) |
| `e34bf7ca` / P-07-09-139 | Shiprocket address 190-char normalization |
| Ably cutover docs | `docs/reverb-setup.md`, `docs/ready-queue-event-driven-architecture-investigation.md` |
| Shiprocket enable | `docs/desk-hardware-fulfilment-shiprocket-enable-p-07-09-71.md` — production HTTP gateway enabled 2026-09-08 |

---

## B. Owner screenshot analysis

**Status: UNKNOWN — screenshots not present in this cloud workspace.**

The investigation cannot diff the owner’s three supplied screenshots against live production from artifacts here. Visual conclusions below are from **code + deployed UX docs (P-07-09-92/93)**, not from those images.

### Expected current UI (VERIFIED from code)

**Top-level Operations Dashboard tabs** (`config/operations.php`, `recent-service-cases.blade.php`):

| Tab label | Query | Purpose |
|-----------|-------|---------|
| Ready Queue | `queue=action_required` | Service-case incidents needing admin action (not hardware fulfilment) |
| Exceptions | `queue=attention` | Service-case attention queue |
| Scheduled | `queue=scheduled` | Support appointments |
| Hardware | `queue=hardware` or `workspace=hardware` | Hardware fulfilment work list |

There is **no top-level tab named “Shipped” or “All”** on `/dashboard`. “Shipped” maps to the Hardware sub-tab **Completed** (`hw_queue=completed`).

**Hardware sub-tabs** (only when Hardware workspace active):

| Sub-tab | Param | Enum |
|---------|-------|------|
| Ready | `hw_queue=ready` | `HardwareDashboardQueue::Ready` |
| Exceptions | `hw_queue=exceptions` | `HardwareDashboardQueue::Exceptions` |
| Pickup | `hw_queue=pickup` | `HardwareDashboardQueue::Pickup` |
| Completed | `hw_queue=completed` | `HardwareDashboardQueue::Completed` |

**No explicit “All” chip** — omitting `hw_queue` shows the full filtered work list. Inventory deep-link `/inventory/hardware-fulfilments` **does** expose an explicit **All** section chip.

**Second navigation surface** (`/inventory/hardware-fulfilments`): Work Queue / Open Fulfilments / Awaiting Fulfilment, with section chips **All**, Needs Fulfilment, In Progress, **Ready for Pickup**, Exceptions. This is a **different menu** from the dashboard Hardware workspace.

### What changed functionally (VERIFIED)

P-07-09-92 (2026-09-08) replaced ad hoc hardware visibility with:

1. Compact Services-style table on `/dashboard?workspace=hardware`.
2. Derived sub-queues Ready / Exceptions / Pickup / Completed.
3. Full page load when entering/leaving Hardware (Ably service-case row merge disabled for that table).
4. Customer 360 as context hub; fulfilment show as execution surface.

P-07-09-153 adjusted **awaiting/recovered** rows (Open Fulfilment action, blocker text) without rewriting queue enums.

### Likely owner-visible “Ready queue/menu inconsistency” (INFERRED)

| Observation | Likely cause | Class |
|-------------|--------------|-------|
| “Ready” on dashboard ≠ “Ready for Pickup” in inventory | **Different filters**: dashboard **Ready** = broad pre-pickup work; inventory **Ready for Pickup** = `ReadyForPickup` + `Completed` stages | VERIFIED (code) |
| Missing “Shipped” tab | Renamed/replaced by **Completed** in P-92 | VERIFIED (code) |
| Counts differ between Hardware chip and sub-tabs | Sub-tab counts computed on full page render only; top Hardware chip uses `chipCount()` overlay on live refresh | VERIFIED (code) |
| Menu differs between dashboard and inventory | Two intentional surfaces (P-92 doc) | VERIFIED (docs) |

---

## C. Dashboard workflow / next action

### End-to-end flow (VERIFIED)

```
Commerce order / RIN
  → eligibility + ingest
  → hardware_fulfilments.state (DB)
  → serial allocation
  → statutory invoice
  → Shiprocket create / AWB / label / pickup / manifest (operator actions)
  → ready_for_pickup_at (Desk operator milestone)
  → markShipped (CLI/isolated; not normal operator UI)
  → Box callback → synced
```

### Source of truth by layer

| Layer | Source | DB / field | UI |
|-------|--------|------------|-----|
| Fulfilment lifecycle | Desk state machine | `hardware_fulfilments.state` | Classifier stage + dashboard sub-queue |
| Operational presentation | Derived classifier | — | Status label, next action, tab bucket |
| Shipment provider IDs | Shiprocket at write time | `shipments.external_*`, `awb` | Courier/AWB columns |
| **Ready for Pickup** | **Desk operator** | `ready_for_pickup_at` | Label + Pickup sub-tab |
| In-transit / delivered | **Not synced from Shiprocket** | No track-status column | Stays on Desk milestones |

### Classifier / next action

Primary: `HardwareFulfilmentOperationalClassifier` + `HardwareShipmentEligibility::inspect()`.

Examples (VERIFIED):

| Stage | Status label | Next action |
|-------|--------------|-------------|
| Awaiting Serial | Awaiting Serial | Allocate Serial |
| Ready for Shipment | Ready for Shipment | Create Shipment / courier prep |
| AWB Pending | AWB Pending | Assign AWB |
| PickupManifestPending | Label generated / Pickup Requested | Request Pickup / Generate Manifest |
| ReadyForPickup | Ready for Pickup | Ready (non-mutating) or Upload Package Photo |
| Completed (no photo) | Ready / Open evidence | Upload Package Photo |

**Product mapping required** → `BlockedReview` / Exceptions when SKU map missing (`HardwareAwaitingFulfilmentReason::ProductMappingRequired`).

### Dashboard sub-queue mapping (`HardwareDashboardQueue::fromStage`)

| Sub-tab | Includes |
|---------|----------|
| Ready | Most in-progress rows **except** Exceptions, Pickup stages, and Completed-with-photo |
| Pickup | `PickupManifestPending`, `ReadyForPickup` |
| Exceptions | `BlockedReview` or unrecoverable provider rejection |
| Completed | `Completed` stage **and** package photo recorded |

Special: `Completed` without photo stays in **Ready**, not Completed.

### Mismatch hotspots (VERIFIED)

1. **Label “Ready for Pickup” before `ready_for_pickup_at`**: classifier line 357 can emit status `'Ready for Pickup'` while stage is still `PickupManifestPending` (manifest not generated).
2. **Inventory section “Ready for Pickup”** includes **`Completed`** rows (`HardwareOperationsSection::fromStage`).
3. **Dashboard does not auto-refresh** after hardware actions (see §D).

---

## D. Ably / realtime investigation

### Stack (VERIFIED)

| Layer | Detail |
|-------|--------|
| PHP | `ably/ably-php` ^1.1; `config/broadcasting.php` driver `ably` when `BROADCAST_CONNECTION=ably` |
| Transport | Laravel Echo + `pusher-js`; Ably Pusher adapter (`realtime-pusher.ably.io`) |
| Runtime | `RealtimeRuntimeConfig` — admin System Settings override env |
| Docs | `docs/reverb-setup.md`, `docs/ready-queue-event-driven-architecture-investigation.md` |

### Channels (VERIFIED — `routes/channels.php`, `DashboardBroadcastEvent`)

| Channel | Scope | Auth |
|---------|-------|------|
| `private-dashboard.{userId}` | Per authenticated user with `incidents.view` | Same user only |
| `private-notifications.{userId}` | Bell / alerts | Same user only |

No tenant-wide hardware channel. Actor excluded via `DashboardBroadcastService::recipientsExcept($actor)`.

### Event names (VERIFIED)

**Dashboard channel (`broadcastAs`):**

- `ServiceCaseCreated`, `SlaStatusChanged`, `ServiceCaseRemarked`
- Hybrid: `ReferenceNumbersUpdated`, `ServiceCasesAssigned`, `ServiceCasesResolved`, `ServiceCasesClosed`
- `DashboardKpisUpdated`

**Notifications channel:**

- `NotificationCreated`, `IncomingCallReceived`, `OperatorAlertRaised`, `RealtimeNotificationDelivered`

### Publishers (VERIFIED)

Central: `app/Services/DashboardBroadcastService.php`.

Service-case mutations (assign, remark, close, waiting state, reference number, quick create, etc.) publish. **Zero** references under `app/Services/HardwareFulfilment/` or hardware controllers.

### Subscribers (VERIFIED)

- `resources/js/live-dashboard-reverb.js` — Echo private channels
- `resources/js/live-dashboard.js` + `live-dashboard-merge.js` — row patch / KPI reconcile
- Entry: `resources/js/pages/dashboard.js`

On event: patch/remove rows via `list_actions`, optional `GET /dashboard/live/rows`, debounced `kpis_only` reconcile — **not** full page reload when Ably healthy.

### Hardware workspace explicitly opts out (VERIFIED)

```35:35:resources/views/dashboard/index.blade.php
data-live-updates-enabled="{{ (($dashboardLiveUpdatesEnabled ?? true) && ($operationQueue ?? '') !== 'hardware') ? '1' : '0' }}"
```

P-92 also forces full page navigation when switching to/from Hardware so service-case Ably merge cannot overwrite the hardware table.

### Manual refresh — root cause classification

| Code | Cause | Hardware? | Service cases? |
|------|-------|-----------|----------------|
| **A** | No event emitted | **YES** — all HW fulfilment POST actions | Partial — some paths KPI-only |
| **D** | Surface not subscribed / live disabled | **YES** — hardware tab | N/A |
| **E** | Client suppresses apply (search/filter, workspace lock) | Possible | VERIFIED patterns exist |
| **F** | Transport down → heartbeat 60s / fast poll 20s | Possible | VERIFIED |
| **G** | Row updated but counts lag (inline assign chip) | N/A for HW | VERIFIED for Ready Queue (Phase 3 gap) |
| **B** | Feature gates off (`hybrid_realtime.*`, Cashfree deferred broadcast default false) | N/A | VERIFIED |

**Conclusion (VERIFIED):** Admin manual refresh on **Hardware dashboard** is expected today: no Ably publisher + live updates disabled on that tab. Service-case Ready Queue is largely event-driven; remaining gaps documented in ready-queue investigation (inline assign count, heartbeat full merge).

Ably **is** used successfully elsewhere (service cases, notifications, hybrid row fetch). Do **not** introduce a second realtime system.

---

## E. Shiprocket integration architecture

### Gateways (VERIFIED)

| Class | Role |
|-------|------|
| `ShiprocketGateway` | Contract — create, AWB, label, pickup, manifest, **track**, cancel |
| `HttpShiprocketGateway` | Live API when `shipping.enabled` + `provider=shiprocket` + `http_enabled` + credentials |
| `NullShiprocketGateway` | Default safe stub |

Binding: `AppServiceProvider::shouldBindHttpShiprocket()`.

Production enablement documented P-07-09-71 (2026-09-08). **Current live bind state not re-queried in this gate** — treat as UNKNOWN unless independently reverified.

### Webhooks (VERIFIED)

**No Shiprocket inbound webhook** in `routes/api.php` or `routes/web.php`. Only Cashfree, Interakt, Bonvoice webhooks.

Box ↔ Desk fulfilment callback exists (`POST /api/desk/fulfilment-status`) — HMAC, optional, **not** Shiprocket tracking.

Architecture doc H-4: v1 = poll/track + outbox; inbound Shiprocket webhook **planned later, not implemented**.

### Shipment progression (VERIFIED)

Operator-synchronous services (not outbox workers today):

- `HardwareShipmentService` — create + search-before-create idempotency
- `HardwareShipmentDocumentsService` — label, pickup, manifest, **`markReadyForPickup()`** (no Shiprocket call)

`markReadyForPickup` sets `ready_for_pickup_at` only; **`hardware_fulfilments.state` stays `awb_assigned`**.

### Tracking / polling (VERIFIED)

- `HttpShiprocketGateway::trackByAwb()` / `trackByShipment()` parse `tracking_data.shipment_status` or `status` into `ShiprocketTrackResult`.
- **No production caller** invokes track methods outside gateway + test fake.
- **No scheduled reconcile job** for shipment track status.

`searchOrders()` used only for create-idempotency; returned provider `status` is **not mapped** to fulfilment UI.

### Authoritative source after handoff (VERIFIED)

| Concern | Authority |
|---------|-----------|
| Create / AWB / label / pickup at action time | Shiprocket API response → persisted once |
| **Ready for Pickup** | **Desk operator** (`ready_for_pickup_at`) |
| In transit / picked up / delivered | **Neither synced** — Desk frozen at operational milestones |
| Shipped terminal | Desk `markShipped()` (restricted actor); not from Shiprocket track |

**Principle mismatch (VERIFIED):** After Shiprocket handoff, **Desk does not follow Shiprocket shipment progression** in code today.

---

## F. Shiprocket status mapping

### Desk `shipments.status` enum (VERIFIED)

`pending_create`, `created`, `awb_assigned`, `failed`, `ambiguous` — **no in-transit/delivered values**.

Labels via `HardwareShipmentEligibility::shipmentStatusLabel()`: Not created / Created / Created (AWB assigned) / reconcile messages. **No “Ready for Pickup” at shipment row level.**

### “Ready for Pickup” label origin (VERIFIED)

| Layer | Mechanism |
|-------|-----------|
| DB | `hardware_fulfilments.ready_for_pickup_at` |
| Write | `HardwareShipmentDocumentsService::markReadyForPickup()` — **no Shiprocket API** |
| UI enum | `HardwareFulfilmentOperationalStage::ReadyForPickup` → `'Ready for Pickup'` |
| Inventory pill | `HardwareOperationsSection::ReadyForPickup` → `'Ready for Pickup'` (includes Completed) |

It is a **Desk operator milestone**, not a verified Shiprocket scan status in persisted data.

### Shiprocket statuses received in code (VERIFIED — write-path DTOs only)

| Operation | Result statuses in gateway layer |
|-----------|----------------------------------|
| createOrder | `created`, `failed`, `rejected` |
| assignAwb | `assigned`, `failed`, `rejected` |
| requestPickup | `requested`, `already_requested`, `failed`, `rejected` |
| generateLabel / manifest | `generated`, `failed`, `rejected` |
| trackByAwb / trackByShipment | raw string defaulting to `unknown` — **not persisted** |
| searchOrders | optional provider status string — **unused for UI** |

Docs-only reference: Postman filter status **12 = Pickup Queue** (P-07-09-88). **No code maps numeric 12 → Desk state.**

Fake test gateway track statuses (`in_transit`, `pickup_requested`, etc.) are **test-only**.

### Why UI stays at “Ready for Pickup” (VERIFIED root causes)

1. **By design terminal Desk milestone** — once `ready_for_pickup_at` is set, classifier shows Ready for Pickup until `shipped`/`synced`; no operator UI for `markShipped`; no track job.
2. **No Shiprocket → Desk ingestion** — webhook absent; track never called.
3. **Misleading label before ready timestamp** — classifier shows “Ready for Pickup” while still `PickupManifestPending` (line 357).
4. **Inventory pill buckets Completed with Ready for Pickup** — post-shipped rows awaiting photo appear under same pill.
5. **Historical pickup reconciliation gap** — P-07-09-88: Shiprocket “Already in Pickup Queue” could leave `pickup_requested_at` NULL until operator retries (doc pattern RDE318421).
6. **Dashboard does not refresh** after Shiprocket steps — even correct DB changes require manual navigation/reload on Hardware tab.

---

## G. Shiprocket safety

This gate performed **no** Shiprocket API calls, **no** production `.env` reads, **no** mutations. Credential existence documented historically in P-07-09-71 only.

---

## H. Database / status model

| Entity | Field(s) | Role |
|--------|----------|------|
| `hardware_fulfilments` | `state`, `ready_for_pickup_at`, `shipped_at`, `synced_at`, AWB/shipment FKs | Primary fulfilment lifecycle |
| `shipments` | `status`, `external_*`, `awb`, label/manifest URLs, `pickup_requested_at` | Provider binding + documents |
| `shipment_events` | append-only activity log | Audit, not used for dashboard refresh |
| Service cases | incident queues | Separate from hardware workspace |

**Multiple layers (VERIFIED):** persisted fulfilment state + derived operational stage + dashboard sub-queue + inventory section — they can diverge (especially around Ready for Pickup labeling).

No schema changes proposed in this gate.

---

## I. Cache / refresh mechanisms

| Surface | Mechanism | Auto-update after admin action? |
|---------|-----------|----------------------------------|
| Service-case queues | Ably + partial HTTP reconcile + 60s heartbeat | Largely yes (with known gaps) |
| Hardware dashboard table | Server render on navigation | **No** — live disabled |
| Inventory hardware fulfilments | Server render | **No** Ably |
| Hardware top chip count | Overlay on `/dashboard/live` KPI refresh | Partial — sub-tab counts not live |
| Email intake / team activity | Poll / lazy fetch | Separate |

Manual refresh required because **authoritative HW state changes are not broadcast** and **Hardware tab disables live client**.

---

## J. Proposed implementation (next gate — do not implement here)

Priority order per owner request:

### A. Correct status / source-of-truth

1. Add **read-only Shiprocket track ingestion** (scheduled job or webhook) mapping verified `shipment_status` → Desk `hardware_fulfilments.state` / new persisted provider_status field — design before coding.
2. Separate **Desk “operator ready”** from **Shiprocket pickup/in-transit** in labels (fix classifier line 357 conflation).
3. Split inventory **Ready for Pickup** pill from **Completed** if owner expects shipped rows elsewhere.
4. Document authoritative rule: pre-create = Desk; post-create IDs = Shiprocket for movement; post-shipped = track-driven.

### B. Ably realtime dashboard updates

1. Publish **`HardwareFulfilmentUpdated`** (name TBD) on `private-dashboard.{userId}` after successful HW POST mutations (serial, invoice, shipment docs, ready-for-pickup) — mirror `DashboardBroadcastService` patterns.
2. Enable targeted refresh on Hardware workspace: **counts + affected row** via existing merge primitives or new `/dashboard/hardware/rows` endpoint — **not** full page reload.
3. Revisit `data-live-updates-enabled=0` only after row-safe patch path exists.
4. Keep actor HTTP response immediate; peers get Ably.

### C. Next-action mapping

1. Drive next action from **persisted provider status** once track ingestion exists.
2. Replace static “Ready” non-mutating action when Shiprocket shows pickup complete / in transit / delivered.
3. Keep package-photo gate independent of courier state.

### D. Dashboard visual / menu correction

1. Align owner terminology: **Completed** vs “Shipped”; add explicit **All** chip on dashboard if required (inventory parity).
2. Clarify **Ready** vs **Ready for Pickup** in labels/tooltips.
3. Optionally unify or cross-link dashboard Hardware vs inventory Work Queue nav — product decision.

### Risks / blockers

| Risk | Notes |
|------|-------|
| Shiprocket webhook contract | Not implemented; need verified payload + auth spec |
| Production overlay drift | HW/Shiprocket files may differ from `main`; named-file deploy only |
| UPI migrations still pending on production | Full deploy-kvm unsafe |
| Owner screenshots not on file here | Visual delta vs pre-P-92 UI **UNKNOWN** |
| Live Shiprocket bind / track on production | **UNKNOWN** in this gate — verify before implementation |

---

## Investigation safety attestation

| Action | Performed? |
|--------|------------|
| Code changed | **NO** |
| Database changed | **NO** |
| Production changed | **NO** |
| Shiprocket mutation | **NO** |
| Ably production test event | **NO** |
| Deploy / restart | **NO** |
