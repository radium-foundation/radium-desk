# Hardware fulfilment operator map and Avinash SOP — RadiumDesk-P-07-09-75

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-75`  
**Mode:** Read-only investigation and operational mapping. No production write. No Shiprocket write. No deploy. No application change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-74**. This ticket: **P-07-09-75**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Verdict

**C. Hardware Dashboard is the team’s discovery / support queue. Hardware Fulfilments is the operational shipment queue.**

Avinash cannot treat the Hardware Dashboard as the complete ship list. Almost every hardware-shaped support case has **no** `HardwareFulfilment`, so **Fulfilment / Shipment** is absent and Desk cannot ship it.

Today production has **one** fulfilment: RDE318421 / `/inventory/hardware-fulfilments/1`. That is the only Desk-shippable hardware order.

---

## Git / production (read-only)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| HEAD | `931b6b598fee492b93c3438f278ed6b8c4101d66` | VERIFIED |
| Worktree | Unrelated dirty statutory/investigation docs; not used | VERIFIED |
| Production | KVM `/var/www/radium-desk` · `https://desk.radiumbox.com` | VERIFIED |

---

## Part 1–2 — Where hardware orders live

### Two different “hardware” lists

| Screen | URL | What it is | Class |
|--------|-----|------------|-------|
| Hardware Dashboard | `/dashboard?workspace=hardware` | Support-case queue. Membership = active incident whose Desk `orders.order_id` starts with `RDE` or `RIN`. Row click → Customer 360. | VERIFIED |
| Hardware Fulfilments | `/inventory/hardware-fulfilments` | Inventory operational list of `hardware_fulfilments` rows. Default “Open queue” = ingested → awb_assigned (not shipped/synced/failed). Paginate 40. | VERIFIED |

Hardware Dashboard is **not** a fulfilment table. Classifier: completed/inactive incidents leave Hardware and go to Completed. `RIN*` is hardware-shaped for support, not Desk fulfilment.

### HardwareFulfilment creation rule

A fulfilment is created only when channel ingest (or isolated `desk:fulfil-hardware`) opens a record:

- channel `radiumbox.com`;
- commerce order source;
- source id starts with **`RDE`** (not RIN);
- not in frozen list;
- at least one physical merchandise line.

Cutoff `2026-09-05 00:00:00 IST` applies to **isolated** fulfilment, not to Dashboard membership.

Frozen (never open): `RDE318360`, `318367`, `318378`, `318379`, `318382`, `318388`, `318391`.  
HOLD: `RDE318438`. Blocked until authorized: `RDE318400`.

### Production coverage (2026-09-08, read-only)

| Population | Count | Class |
|------------|-------|-------|
| Desk `RDE*` orders | 871, all `active` | VERIFIED |
| Desk `RIN*` orders | 19 | VERIFIED |
| Every RDE/RIN has a support incident | 871 / 19 | VERIFIED |
| `commerce_orders` RDE | 1 | VERIFIED |
| `hardware_fulfilments` | **1** (RDE318421, `invoice_issued`) | VERIFIED |
| RDE orders with no fulfilment | **870** | VERIFIED |

So: **not** every paid hardware order creates a HardwareFulfilment. **Not** every Hardware Dashboard row has a fulfilment. A paid RDE can exist only as a support case.

### Can Avinash miss an order?

| Risk | Finding | Class |
|------|---------|-------|
| Paid RDE on Dashboard, no fulfilment | Yes — no Fulfilment / Shipment link. 870 current examples including RDE318477, RDE318482 | VERIFIED |
| Fulfilment without Dashboard link | Possible if `support_order_id` / `source_id` do not match the incident’s order. RDE318421 **is** linked | VERIFIED mapping; mismatch INFERRED possible |
| Hidden by Dashboard pagination / search | Hardware queue uses case page size (35) + load more (25). Search is case-row text, not fulfilment state | VERIFIED sizes; miss risk INFERRED |
| Hidden by Fulfilments default filter | Open queue omits `shipped` / `synced` / `failed`. Must pick Fulfilment state | VERIFIED |
| Branch scope | Avinash `admin` + `hardware_team`; `inventory.branches.operate-all` **true**. List is not branch-filtered; show is | VERIFIED |
| RIN never on Fulfilments | `shouldOpenRecord` requires `RDE` | VERIFIED |
| Completed support case leaves Hardware queue | Classifier `isCompleted` wins over `isHardware` | VERIFIED |
| Cancelled/refunded Desk RDE | **0** with status cancelled/refunded; all 871 `active` | VERIFIED today |

**Source of truth for unprocessed Desk-shippable work:** `hardware_fulfilments` in open states, especially `invoice_issued` with no shipment.  
**Source of truth for “every hardware-shaped customer case”:** Hardware Dashboard + order search.  
**No existing reconciliation page** lists paid RDE without fulfilment. **UNKNOWN** whether Box still ships those outside Desk.

---

## Part 3 — Avinash journey (only when a fulfilment exists)

All shipment actions are on **one show page**:  
`https://desk.radiumbox.com/inventory/hardware-fulfilments/{id}`  
Permission: `hardware.fulfilment.operate` (+ branch allow). Same permission for serial, parcel, courier, create, AWB, label, evidence, pickup, manifest, ready-for-pickup.

Country correction uses `hardware.fulfilment.correct-country` (Avinash has it) but the show page currently sets `canCorrectCountry=false` and India is applied by rule. No country form.

**Invoice cannot be issued from this UI.** Issuance is `HardwareFulfilmentInvoiceService` via isolated `desk:fulfil-hardware`, not a button.

**Mark Shipped is not on the UI.** `markShipped()` is isolated/system only.

| Stage | Screen / route | Button | Prerequisite | Success | If blocked |
|-------|----------------|--------|--------------|---------|------------|
| 1 Find shippable order | Inventory → Hardware `/inventory/hardware-fulfilments` or Dashboard **Fulfilment / Shipment** | Open | Fulfilment exists | Show page | No link → not Desk-shippable yet. Do not invent a fulfilment |
| 2 Open fulfilment | `hardware-fulfilments.show` | — | operate + branch | Cards: serial, invoice, parcel, courier, shipment | 403 if other-branch and not operate-all |
| 3 Review order/product | Same show + optional 360/order | — | — | Order, product, qty, customer | Missing commerce → ingest not done |
| 4 Payment | Show readiness `payment` | — | Cashfree/paid recognition | **Paid** | Not verified → do not ship; escalate |
| 5 Invoice | Show invoice card | View invoice / GST PDF | Invoice already issued | `/finance/invoices/{id}` | No invoice → wait for isolated invoice. Avinash has no Issue button |
| 6 Serial | Show serial card | Allocate Serial | State `ready_for_fulfilment`; SKU map; not frozen | State `serials_allocated`; branch from stock | No map / frozen / wrong state → do not type a serial from memory |
| 7–8 Branch / pickup | Show after allocate | — | Allocated serial | Branch + Shiprocket nickname/postcode | Unset branch or missing postcode → fix stock/config, do not type pickup |
| 9–10 Address / India | Show ship-to | — | Structured address | Country **India** (no input) | Incomplete address → fix on order/360, not by inventing country |
| 11–12 Parcel | Show parcel | Attach verified packaging | Catalog pack verified; snapshot missing | Snapshot on fulfilment only | No verified pack → verify on Inventory Stock packaging first. Never write `commerce_orders.parcel` |
| 13 Package-before-label | Evidence card | Record package photo | Not terminal | `package_before_label` | Optional. Not proof of labelling |
| 14–17 Courier | Courier card | Get Courier Options → select → Select Courier | Invoice + parcel + Shiprocket on; options fresh | Selected returned id; **Prepaid** | Stale options → fetch again. Do not type courier id. Do not treat `cod` as COD |
| 18–19 Create shipment | Shipment card | Create Shipment | Valid courier selection; confirm block | Provider shipment id; state `shipment_created` | Duplicate/bound → stop. Reconcile only if UI says Reconcile |
| 20 AWB | AWB card | Assign AWB | Bound shipment; courier stored | AWB displayed | Hidden until created. Never type AWB |
| 21–23 Label | Label card | Generate Shipping Label → Print | AWB assigned | PDF URL; print; paste physically | No URL → do not fake a label |
| 24 Label photo | Evidence | Record labelled package | AWB assigned | `package_label_applied` | Rejected before AWB |
| 25 Pickup | Manifest/pickup card | Request Pickup | AWB | `pickup_requested_at` | Explicit; do not click to test |
| 26–27 Manifest | Same | Generate Manifest; Download only if URL | AWB + pickup requested | Persist URL/id if provider returns them | Print Manifest **does not exist**. No URL → no download |
| 28 Ready for pickup | Same | Mark Ready for Pickup | AWB + label-applied photo | `ready_for_pickup_at` | Timestamp only; not a new enum |
| 29 In transit | No Desk Mark Shipped | — | After pickup | Provider tracking / isolated shipped | Do not invent shipped state in UI |

GET/show/`inspect()` do **not** call create/AWB/label/manifest/pickup. Recommendation is display-only.

---

## Part 4 — Representative cases (read-only)

| Example | Process? | Action |
|---------|----------|--------|
| Paid / no serial — frozen `RDE318360` (and six peers). Paid support case, **no** fulfilment | **No** ship | Support only. Frozen list cannot allocate/invoice/ship |
| Serial / no invoice | **No production row** | When it exists: allocate is done; **wait** for isolated invoice. No Issue button |
| Serial+invoice / no parcel | **No production row** | Attach verified packaging on show. Do not ship |
| Fully ready — **RDE318421** fulfilment 1 | **Yes** | Select returned courier → Create Shipment → AWB → label → paste → photo → pickup → manifest if URL → Ready for Pickup. Do not refetch options unless stale. Do not create yet unless owner authorizes the live transaction |
| Shipped Desk fulfilment | **None** | Open queue hides shipped. Filter state `shipped` when they exist |
| Support-only — `RDE318477`, `RDE318482` | **No** ship | Dashboard + 360/order only. No Fulfilment / Shipment |
| RIN — `RIN3512331` | **No** Desk ship | Hardware Dashboard support only. Not RDE ingest |
| HOLD / blocked — `RDE318438`, `RDE318400` | **No** ship | Do not ingest or advance |
| Cancelled/refunded Desk RDE | **None** with that status | If one appears later, do not ship |

RDE318421 was **not** modified. inspect(): Paid, India, parcel snapshot present, Prepaid, courier options present, **Courier selection required**, no shipment/AWB.

---

## Part 5 — Filter gaps (do not implement here)

Already filterable on Fulfilments: order, serial, fulfilment **state**, branch, coarse shipment_status (`ready` = invoice_issued + no shipment, not_created, created/no AWB, awb).

**Not** filterable: serial pending, invoice pending, parcel pending, courier selected, label pending, label-applied pending, pickup pending, ready-for-pickup, in transit.

Dashboard filters are support SLA/assignment, not fulfilment readiness.

Smallest safe later enhancement: Fulfilments columns/filters for parcel / courier / label / pickup / ready-for-pickup, plus a **read-only** “RDE paid, no fulfilment” reconciliation. Do not invent a second ship UI.

---

## Part 6 — Missed-order source of truth

Unprocessed **Desk-shippable** orders = open `hardware_fulfilments`.  
Unprocessed **customer hardware cases** = Hardware Dashboard (and 870 RDE without fulfilment).

No reconciliation report exists.

---

## Part 7 — Avinash permissions (user 2)

Roles: `admin` + `hardware_team`.

| Capability | Permission | Has? |
|------------|------------|------|
| Hardware Dashboard | `dashboard.hardware.view` | Yes |
| Fulfilments list/show + all ship actions | `hardware.fulfilment.operate` | Yes |
| Country overlay | `hardware.fulfilment.correct-country` | Yes (UI currently hidden) |
| All branches | `inventory.branches.operate-all` | Yes |
| Invoice view (linked) | operate + linked-invoice gate | Yes |
| Invoice **issue** from fulfilment UI | No route/button | N/A — isolated command |
| `hardware.fulfilment.ship` | Does not exist | — |

`hardware_team` alone would get operate but not correct-country / finance admin extras, and would be branch-scoped. Avinash’s admin role covers the gaps.

---

## Part 8 — Shipment safety (current production)

- Recommendation does not auto-select.  
- Courier must be a returned id.  
- Create / AWB / label / pickup / manifest are explicit POSTs.  
- Duplicate create is lock + search-before-create.  
- GET/inspect do not write provider records.  
- Collection mode **Prepaid**; COD ordering off.  
- Shiprocket enabled; `HttpShiprocketGateway`; India; Delhi `110019` / Mumbai `400104`. Credentials/channel unchanged.

---

## Recommended minimal improvements (not implemented)

1. Treat Hardware Fulfilments as the daily ship queue in training (this SOP).  
2. Later: read-only “paid RDE without fulfilment” report so 870 cases are visible as **not yet Desk-shippable**, not forgotten.  
3. Later: Fulfilments filters for parcel / courier / label / pickup.  
4. Do **not** add Fulfilment / Shipment links that invent ids.  
5. Do **not** auto-ingest all Dashboard RDE rows.

---

## Not performed

- Production writes / deploy / migrate / permission change: **NO — Not performed.**  
- RDE318421 mutation / courier select / shipment / AWB / label / manifest / pickup: **NO — Not performed.**  
- Live Shiprocket write or serviceability refetch: **NO — Not performed.**  
- Implementation of filters/reconciliation: **NO — Not performed.**
