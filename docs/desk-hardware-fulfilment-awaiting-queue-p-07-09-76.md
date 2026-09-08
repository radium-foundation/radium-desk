# Hardware awaiting-fulfilment queue — RadiumDesk-P-07-09-76

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-76`  
**Mode:** Read-only production reconciliation, then a minimal read-only UI on the existing Hardware Fulfilment page. No mass ingest. No fulfilment create action. No Shiprocket write. No deploy.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-75**. This ticket: **P-07-09-76**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Answer for Avinash

**Where do I find every hardware order that needs processing?**

| Need | Where | Class |
|------|-------|-------|
| Orders already inside Desk fulfilment (the ship queue) | Inventory → Hardware → **Open Fulfilments** (`/inventory/hardware-fulfilments`) | VERIFIED |
| RDE support orders that do **not** have a fulfilment yet | Same page → **Awaiting Fulfilment** (`/inventory/hardware-fulfilments?queue=awaiting`) after this code is deployed | VERIFIED in this repo; **not on production until a separate overlay** |
| Every hardware-shaped support case (RDE + RIN) | Hardware Dashboard (`/dashboard?workspace=hardware`) | VERIFIED |
| RIN | Hardware Dashboard only. Never Desk fulfilment | VERIFIED |

`/inventory/hardware-fulfilments` was never the complete hardware-order list. Production currently shows only **RDE318421** because that is the only `hardware_fulfilments` row.

**How do I move an eligible order into Hardware Fulfilment without processing an ineligible one?**

1. Open **Awaiting Fulfilment** (default filter: Review candidates).
2. Open the Desk order / Customer 360. Review payment, product, and whether it is frozen / HOLD / blocked / historical / already completed on Desk.
3. Do **not** click anything on that list to create a fulfilment — there is no create button.
4. If the order is eligible, request a **single-order** isolated ingest (`desk:fulfil-hardware {RDE} --step=ingest --payload=…` with a verified Box handoff). Channel ingest can also open a record when Box posts a valid radiumbox.com commerce payload.
5. After a fulfilment exists, it appears under **Open Fulfilments**. Then follow the existing show-page workflow: serial → invoice (isolated, no Issue button) → packaging → courier options → select courier → create shipment → AWB → label → photos → pickup → manifest → ready for pickup.

Do not mass-create 872 fulfilments. Do not ingest frozen / HOLD / blocked / RIN / unpaid / pre-cutoff / already-completed Desk orders.

---

## Git / production

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by 2 at start of this ticket) | VERIFIED |
| HEAD before this commit | `f2ffb3efb88bfeb6764e519f5076e66f1b9a2c20` | VERIFIED |
| Production | `https://desk.radiumbox.com` → KVM `/var/www/radium-desk` | VERIFIED |
| Deploy | **NO — Not performed.** | VERIFIED |

---

## Part 1 — Data model

### Authoritative sources

| Question | Answer | Class |
|----------|--------|-------|
| Source of RDE hardware **support** orders | Desk `orders.order_id` starting `RDE` | VERIFIED |
| Hardware-shaped support (Dashboard) | `RDE` **and** `RIN` via `Order::hardwareOrderPrefixes()` | VERIFIED |
| Commerce order | `commerce_orders` from Box channel ingest or isolated ingest. Production RDE commerce rows: **1** (CO-000369 / RDE318421) | VERIFIED |
| HardwareFulfilment | `hardware_fulfilments` keyed to commerce + `source_id` + optional `support_order_id`. Production rows: **1** | VERIFIED |
| How a fulfilment is created | `ChannelIngestService` → `HardwareFulfilmentFoundationService::ensureIngested()` when `HardwareFulfilmentEligibility::shouldOpenRecord()` is true; or isolated `desk:fulfil-hardware {id} --step=ingest --payload=…` | VERIFIED |
| Operator UI create | **None.** No button, route, or job discovers Box handoffs from the 872 support orders | VERIFIED |
| Why only RDE318421 appears on the current page | `HardwareFulfilmentSerialController::index()` queries `hardware_fulfilments` only. One row exists | VERIFIED |

### `shouldOpenRecord` (live ingest)

Must be: channel `radiumbox.com`, commerce source, source id `RDE*` (not RIN), not frozen, at least one physical line (`physical_merchandise` or `model_id`). **VERIFIED**.

### Isolated extra gates (`assertIsolatedTarget`)

Not HOLD, not blocked-until-authorized, paid commerce, physical lines, persisted `ordered_at` on/after **2026-09-05 00:00:00 IST**. `created_at` is not substituted for `ordered_at`. **VERIFIED**.

Frozen: `RDE318360`, `318367`, `318378`, `318379`, `318382`, `318388`, `318391`.  
HOLD: `RDE318438`. Blocked: `RDE318400`.

### Representative RDE318421 (read-only)

| Field | Value | Class |
|-------|-------|-------|
| Fulfilment | `1`, `invoice_issued` | VERIFIED |
| Serial | allocated (unchanged by this ticket) | VERIFIED prior; not rewritten here |
| Invoice | issued | VERIFIED prior |
| Shipment / AWB / label / pickup / manifest | none | VERIFIED this ticket |
| Selected courier | none | VERIFIED |
| `updated_at` observed | `2026-09-08 15:26:02` | VERIFIED read; **this ticket did not write** |

---

## Part 2 — Eligible Hardware Fulfilment

Do not mix these queues:

| Queue | Definition | Class |
|-------|------------|-------|
| A. All hardware orders | Desk `RDE*` + `RIN*` support orders | VERIFIED |
| B. Need Desk fulfilment creation | Paid Box commerce handoff, RDE, physical line, not frozen/HOLD/blocked, isolated cutoff if using isolated path | VERIFIED rules; **membership UNKNOWN** without Box payload |
| C. Already in fulfilment | Has `hardware_fulfilments` row | VERIFIED |
| D. Ready for shipment | Fulfilment `invoice_issued` + parcel + courier selected (see P-07-09-75) | VERIFIED |
| E. Already shipped | Desk fulfilment with shipment/AWB/`shipped`; **or** shipped outside Desk | Desk shipped: **0 VERIFIED**. Outside Desk: **UNKNOWN** |

**Eligible Hardware Fulfilment** (open a record): Box RDE commerce ingest with a physical line; not frozen; not RIN. Isolated path also requires paid, not HOLD/blocked, `ordered_at` ≥ cutoff. Additional UI-only review exclusions used by Awaiting Fulfilment: unpaid Desk Cashfree, Desk serial/transaction already present, Desk `created_at` before cutoff (proxy). Those extra exclusions are **INFERRED** operational safety, not `shouldOpenRecord`.

Physical line cannot be proven from a support `orders` row. `device_model_id` is a support assignment, not a commerce line. **UNKNOWN** for 872 orders.

---

## Part 3 / 9 — Why 872 have no fulfilment (production read-only, 2026-09-08)

RDE count rose from 871 (P-07-09-75) to **873**.

| # | Population | Count | Class |
|---|------------|------:|-------|
| 1 | Total RDE support orders | 873 | VERIFIED |
| 2 | RDE with a **verified** physical commerce line | 1 (RDE318421) | VERIFIED |
| 2b | Other RDE physical lines | — | **UNKNOWN** (no commerce items) |
| 3 | RDE without HardwareFulfilment | 872 | VERIFIED |
| 4 | RDE with HardwareFulfilment | 1 | VERIFIED |
| 5 | Paid RDE without fulfilment (Cashfree on Desk order) | 869 | VERIFIED |
| 6 | Unpaid RDE without fulfilment | 3 | VERIFIED |
| 7 | Frozen / HOLD / blocked | 7 / 1 / 1 | VERIFIED |
| 8 | Cancelled/refunded Desk RDE | 0 (all 873 `active`) | VERIFIED |
| 9 | RIN | 19 | VERIFIED |
| 10 | Already shipped via Desk fulfilment | 0 (`shipments` table 0; no AWB) | VERIFIED |
| 10b | Desk transaction locked (old completion) | 796 | VERIFIED count; “already shipped” **INFERRED** |
| 10c | Desk support serial present | 431 | VERIFIED |
| 11 | Review candidates (see below) | 10 | VERIFIED Desk shape; ingest-eligible **UNKNOWN** |

### Exclusive reason for the 872 (Desk-visible, no Box line)

| Reason | Approx. | Class |
|--------|--------:|-------|
| Never ingested — no `commerce_orders` / no Box or isolated handoff | 872 | VERIFIED (commerce RDE = 1) |
| Historical / pre-cutoff Desk `created_at` and paid, no serial/tx | remainder after completed/unpaid/excluded | INFERRED using `created_at` as `ordered_at` proxy |
| Already completed on Desk (serial or transaction) | majority of the 869 paid | INFERRED not a new ship |
| Frozen / HOLD / blocked | 9 | VERIFIED |
| Unpaid | 3 | VERIFIED |
| Intentional RIN exclusion | 19 RIN, not in the 872 | VERIFIED |
| Fulfilment creation failure | Channel ingest attempts for `RDE*` accepted: **RDE318421 only**. Other accepted ingest ids are `RD*` service, not hardware fulfilment | VERIFIED |
| List-query bug | No | VERIFIED |

Post-cutoff Desk RDE: **22**. Of those, 1 has a fulfilment (RDE318421), 9 are owner-excluded or already completed on Desk, **10** are review-shaped.

**Current review candidates (paid, post-cutoff, no HF, not frozen/HOLD/blocked, no Desk serial/transaction):**

`RDE318401`, `RDE318434`, `RDE318435`, `RDE318437`, `RDE318467`, `RDE318469`, `RDE318477`, `RDE318482`, `RDE318486`, `RDE318487`.

These are **not** proven Box-physical or isolated-ready. They are the only Desk-visible “maybe ingest next” set. **INFERRED** until a verified handoff exists.

Cutoff month mix: 2026-06: 31, 07: 370, 08: 403, 09: 69. **VERIFIED**.

---

## Part 4–6 — Operator queue decision

| Option | Decision |
|--------|----------|
| A. Separate Hardware Orders namespace | Not used |
| B. Tabs on the existing page | **Implemented** (local): Open Fulfilments / Awaiting Fulfilment |
| C. Reuse an existing screen | Hardware Dashboard remains support discovery. It does not classify ingest eligibility |

No `/inventory/shipments`. No create/initialize button. Creating a fulfilment from a support order without a Box payload would be a larger domain change — **stopped**.

Default Awaiting filter = **Review candidates**. Other filters: Frozen/HOLD/blocked, Historical, Already completed on Desk, Unpaid, All RDE without fulfilment.

---

## Part 7 — Exact operator workflow

**Today on production (code not deployed):**

1. Hardware Dashboard or order search to find an RDE.
2. If **Fulfilment / Shipment** is shown → open `/inventory/hardware-fulfilments/{id}` and continue P-07-09-75.
3. If not shown → there is no fulfilment. Do not invent one. Escalate a single order for isolated ingest if it looks eligible.

**After this overlay is authorized:**

1. `/inventory/hardware-fulfilments?queue=awaiting` → review candidates.
2. Open order / Customer 360.
3. If eligible, isolated ingest (authorized operator / owner), not a page action.
4. `/inventory/hardware-fulfilments` Open Fulfilments → Open show.
5. Allocate serial → wait for isolated invoice → attach packaging → Get Courier Options → select courier → Create Shipment → Assign AWB → Generate Label → print/paste → label photo → Request Pickup → Generate Manifest → Ready for Pickup.

Invoice issue and Mark Shipped remain **absent** from the UI.

---

## Part 8 / 10 — RDE318421 and permissions

RDE318421 was not modified. No shipment started.

Avinash already has `hardware.fulfilment.operate` (admin + hardware_team). The new tab uses the same controller gate. **No new permission.** `hardware.fulfilment.ship` still does not exist.

---

## Implementation

| Item | Value |
|------|-------|
| Implementation | Minimal Option B, read-only |
| Changed | Controller index queue switch; awaiting classifier/queue; index Blade tabs |
| Migration | **NO — Not performed.** |
| Tests | `HardwareFulfilmentAwaitingQueueTest`, `HardwareAwaitingFulfilmentClassifierTest`; existing list filter still passes |
| Pint/lint | Passed |
| Create fulfilment | **NO — Not performed.** Existing ingest remains idempotent; no UI create to test |
| Browser | **NO — Not performed** on production (undeployed). Feature tests cover the HTML |

---

## Safety confirmations

- No mass fulfilment creation occurred.
- No historical orders were replayed.
- No RDE318421 data changed by this ticket.
- No shipment was created.
- No AWB was assigned.
- No label was generated.
- No pickup was requested.
- No manifest was generated.
- No Shiprocket provider-changing call was made.

---

## Completion report

| Field | Value |
|-------|-------|
| Project | Radium Desk |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| HEAD | see git after commit |
| Prompt ID | `RadiumDesk-P-07-09-76` |
| Current Hardware Fulfilment queue | Fulfilment-record open queue. Production shows RDE318421 only |
| Why only RDE318421 appears | Only one `hardware_fulfilments` row. Page queries that table |
| Total RDE | 873 VERIFIED |
| Physical RDE | 1 VERIFIED (commerce); 872 UNKNOWN |
| RDE with fulfilment | 1 |
| RDE without fulfilment | 872 |
| Paid without fulfilment | 869 |
| Eligible for fulfilment | 0 Desk-proven. 10 review-shaped UNKNOWN for Box ingest |
| Frozen/HOLD/blocked | 7 / 1 / 1 |
| RIN | 19 |
| Cancelled/refunded | 0 |
| Already shipped | 0 Desk fulfilment. 796 transaction-locked INFERRED old completion |
| Fulfilment creation rule | Box/isolated ingest + `shouldOpenRecord` (+ isolated gates) |
| Authoritative hardware-order source | Desk `orders` (`RDE`/`RIN`) |
| Authoritative fulfilment source | `hardware_fulfilments` |
| Recommended operator queue | Awaiting Fulfilment tab (review) then Open Fulfilments |
| Existing screen reused | `/inventory/hardware-fulfilments` |
| New UI required | Yes — tabs + read-only table. No new route namespace |
| Exact operator workflow | See Part 7 |
| Avinash permissions | `hardware.fulfilment.operate` already sufficient |
| New permission required | No |
| Production data changed | **NO — Not performed.** |
| RDE318421 changed | **NO — Not performed.** |
| Shiprocket calls | **NO — Not performed.** |
| Shipment created | **NO — Not performed.** |
| AWB | **NO — Not performed.** |
| Label | **NO — Not performed.** |
| Pickup | **NO — Not performed.** |
| Manifest | **NO — Not performed.** |
| Deployment | **NO — Not performed.** |
| Push | **NO — Not performed.** |
| Remaining risks/blockers | Undeployed UI. Ingest still needs a verified Box payload. Physical line UNKNOWN for 872. Do not treat review candidates as auto-eligible. `created_at` is a cutoff proxy. RDE318421 shipment still not started |
