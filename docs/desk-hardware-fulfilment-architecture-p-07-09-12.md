# Desk hardware fulfilment architecture — Step 1 audit

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-12**  
**Date:** 2026-09-07  
**Type:** Read-only implementation / architecture audit. No application, config, schema, production, ingest, Shiprocket, invoice, serial, AWB, or seven-order change.  
**Verdict:** **DESIGN COMPLETE FOR STEP 1 — IMPLEMENTATION BLOCKED** until owner decisions below are locked.

Companion Box investigations (same day, not re-used as sole proof): `radiumbox.com-P-07-09-01` … `P-07-09-04`. This prompt independently re-verified production SELECTs on KVM `187.127.129.16`.

Classification: **VERIFIED** (this worktree / sibling source / this-session production SELECT), **INFERRED**, **UNKNOWN**.

---

## 0. Repository verification

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Git root | same | VERIFIED |
| Branch | `main` tracking `origin/main` | VERIFIED |
| HEAD | `e4c3aec328a2a132d3763c115aeb259021bc2585` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Worktrees | this `main`; `/Users/ravi/RadiumWebsites/radium-desk` `feat/rd-fresh-01-inventory-pos`; `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean` detached | VERIFIED |
| Latest tag | `v4.0.67` (not this HEAD) | VERIFIED |
| `docs/cursor-prompt-log.md` | **Absent.** Canonical ledger is `docs/cursor-prompt-ledger.md`. Next unused ID = this prompt. | VERIFIED |

Production Desk (read-only, this session):

| Item | Value | Class |
|------|-------|-------|
| Host | `187.127.129.16` `/var/www/radium-desk` | VERIFIED |
| Git on deploy path | none | VERIFIED |
| Overlay | `GstSplitService.php` SHA-256 matches this HEAD | VERIFIED |
| `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` | **KEY_ABSENT** | VERIFIED |
| `CHANNEL_INGEST_SECRET_RDSERVICE_IN` | SET (value not read) | VERIFIED |
| Shiprocket env | KEY_ABSENT | VERIFIED |
| Statutory issued | 61 | VERIFIED |
| `commerce_orders` channel `radiumbox_com` | **0** | VERIFIED |

Production radiumbox.com (read-only, this session):

| Item | Value | Class |
|------|-------|-------|
| Host / path / DB | same KVM `/var/www/radiumbox.com` / `radiumbox_prod` | VERIFIED |
| `DESK_INGEST_ENABLED` | `false` (config only; **Box delivery code does not read this flag**) | VERIFIED |
| `DESK_BASE_URL` | empty — **this** plus empty secret is the live HTTP gate | VERIFIED |
| `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` | empty | VERIFIED |
| `DESK_CALLBACK_SECRET` | KEY_ABSENT | VERIFIED |
| `VENDOR_SHIPROCKET_ENABLED` | `false` | VERIFIED |

Do **not** assume this HEAD is the full production tree. Production is a surgical overlay of selected files, not a tagged `deskd` of `e4c3aec`.

Sibling WIP (read-only, same repo, **not on main**): `/Users/ravi/RadiumWebsites/radium-desk` contains Shiprocket S2–S6 + structured ingest + `shipping_line_kind`. That code is **not** production and **not** this worktree.

---

## 1. VERIFIED current Desk hardware capabilities

Desk today is **not** a hardware fulfilment authority. It is a Cashfree-created support shell plus a service/POS statutory engine.

| Capability | On `main` / production | Class |
|------------|------------------------|-------|
| HMAC `POST /api/v1/channel-orders` | Yes. `radiumbox_com` channel exists in config. Production secret for Box is **absent** → 401 | VERIFIED |
| Persist `commerce_orders` | Yes. Idempotency `statutory:{channel}:{source_type}:{source_id}` + unique source + payload hash | VERIFIED |
| Auto-mint on ingest | Hard-off `channel_ingest.auto_issue_invoice=false` | VERIFIED |
| `issueFromCommerceOrder()` | Yes. Delegates to single `mint()` | VERIFIED |
| `issueFromSupportOrder()` | Yes, but **only** `rdservice_in` + `source_id = orders.order_id` | VERIFIED |
| Hardware / RDE support orders | Cashfree webhook creates `orders` + incident. No lines, GSTIN, address, HSN, serial, invoice | VERIFIED |
| Hardware fulfilment state machine | **None** | VERIFIED |
| C360 issue invoice for RDE | **None** (P-06-09-13 still true in code) | VERIFIED |
| Shiprocket on main | **Absent** (0 files) | VERIFIED |
| Inventory serials | Yes. POS `reserve` / `completeSale` / unique `inventory_serials.serial_number` | VERIFIED |
| Map Box `modelid` → Desk SKU | **Absent** | VERIFIED |
| Desk → Box fulfilment callback client | **Absent** | VERIFIED |

Dual identity for the same `RDE*` (VERIFIED on all seven pending orders):

1. **Box** `orders.ordercode` Paid / Processing + pending `desk_order_handoffs`
2. **Desk Cashfree** `orders.order_id` active + incident `awaiting_product_details`
3. **Desk commerce** — row **does not exist**

`issueFromSupportOrder()` cannot mint these. Payment webhook must not mint. Target writer remains **one** `StatutoryInvoiceService::mint()`.

---

## 2. VERIFIED current radiumbox.com handoff capabilities

Source: `/Users/ravi/RadiumWebsites/radiumbox.com` (read-only).

| Piece | Behaviour | Class |
|-------|-----------|-------|
| Paid persist | Local `RDE{id}` before payment; Cashfree marks `Paid` / `Processing` | VERIFIED |
| Outbox | `PaidOrderFulfillmentService` enqueues `desk_order_handoffs`; `desk:process-outbox` | VERIFIED |
| HMAC client | `DeskChannelClient`: `X-Desk-Channel/Timestamp/Signature`, `Idempotency-Key` | VERIFIED |
| Idempotency | `statutory:radiumbox_com:commerce_order:{ordercode}` | VERIFIED |
| Unconfigured Desk | Does **not** burn attempts; `last_error=Desk ingest is not configured.` | VERIFIED this session |
| Pending handoffs | 30 total; 17 paid `radiumecom` | VERIFIED |
| Invoice callback receiver | `POST /api/desk/invoice-status` HMAC; invoice fields only | VERIFIED |
| Serial / AWB / shipped callback | **Not implemented** | VERIFIED |
| Local invoice/serial/AWB writer | **Not in this repo.** Historical writer = Old Admin `GenrateInvoice` / `PosController::Serial` / `ShipRocketController` | VERIFIED source |
| `VENDOR_SHIPROCKET_ENABLED` | false | VERIFIED |

### Current Box → Desk payload (what Box actually builds)

From `DeskOutboxService::payload()`:

**Sent today:** channel, source_type/id, payment paid/cashfree/reference/method, INR, customer name/phone/email/gstin, structured billing address, **shipping_address = billing**, optional parcel, `place_of_supply_state` = `orders.state`, discount, timestamps, metadata `{radiumbox_order_id, ordertype}`, lines.

**Per line sent:** description, sku=`modelid` else `productid`, qty, unit_price, hsn_sac, taxable_value, tax_total, line_total, rdserviceid/amcid/otgid, `shipping_line_kind`, `requires_shipping`.

**Not sent (VERIFIED absent from builder):**

| Field | Needed for hardware fulfilment? | Recommended source |
|-------|--------------------------------|--------------------|
| `gst_percentage` | Yes — Desk mint fail-closed | Box catalog `products.gst_percentage` on **model** row (parent often NULL) |
| `seller_gstin` / `branch_code` | Product issuer requires mapped branch | **UNKNOWN** — must not invent from shipping/billing |
| `cgst` / `sgst` / `igst` | Optional; Desk can split | Desk `GstSplitService` after issuer known |
| Warehouse / pickup / serials | Yes for ship + allocate | **UNKNOWN** — not on Box order |
| Separate shipping address | If it exists | Box currently copies billing. Seven orders have one `userdetails` address. Whether a distinct ship-to exists elsewhere is **UNKNOWN** |
| Country | Shiprocket often wants it | `userdetails.country` is null on all seven |
| Parcel L/W/H/weight | Shiprocket | Catalog measurements NULL on these models |
| Desk SKU | Serial allocation | No map. Box `sku_code` ≠ Desk `inventory_products.sku` |

Desk `main` validator **persists** customer, flattened addresses, `billing_state`, POS, payment, tax amounts if supplied. It **strips** `shipping_line_kind`, `requires_shipping`, `rdserviceid`/`amcid`/`otgid`, and parcel (no columns on `commerce_orders` in this worktree). WIP branch persists those; do not merge that dirty tree blindly.

Physical vs service (Box, VERIFIED): `modelid` present → `physical_merchandise`. Combined Product+Service stays **one hardware line**; RD/AMC/OTG FKs are additive and do not flip shipping. Desk `main` never stores that kind.

---

## 3. VERIFIED statutory invoice capabilities

One authoritative writer on Desk: `StatutoryInvoiceService::mint()`.

| Topic | Current | Hardware-safe? |
|-------|---------|----------------|
| Identity / idempotency | `statutory:{channel}:{source_type}:{source_id}` unique | Yes, if hardware uses `radiumbox_com` + `commerce_order` + `RDE*` |
| Sequence / FY | Location series `INV-{state}{FY}{seq}`; Delhi B2C service isolated `INV-671…` | Product path exists (`INV-07671` / `INV-27671`) **if** branch mapped. Historical hardware is `IND67*` / `INM67*` — **different family** |
| Issuer | Product: `DELHI-RETAIL` / `MUMBAI` only. Service: billing_state / GSTIN | **Do not apply service rules to hardware.** Product path needs `branch_code`. Box does not send it. Historical issuer = operator dropdown (`request->branch` → `radium_branch.slug`) |
| B2B / B2C | Buyer GSTIN validated; invalid fail-closed | Usable once ingest has GSTIN |
| GST split | `GstSplitService` on **commerce** mint only (`applyServiceGstSplit` returns early unless `sourceType=commerce_order`). POS product mint leaves CGST/SGST/IGST NULL | Hardware **online** path can reuse it after issuer/POS + `gst_percentage`. Do **not** use POS `issueFromPosSale` as the Box fulfilment writer |
| HSN / SAC | HSN `^\d{4,8}$`; `99*` = service else product; mixed fail-closed | Seven-order lines are HSN `84716050` / `85269190` (product). Bundled RD FKs are **not** separate SAC lines |
| PDF / storage | Private disk after issued number; HMAC document GET. Serials are **not** printed | Hardware PDF must add allocated serials (not present today) |
| E-invoice | Null gateway; B2C skip; B2B not live | Hardware B2B (RDE318388) IRN = **UNKNOWN / later** |
| Cancel / credit note | Status cancel only. No CN series | **UNKNOWN** for hardware returns |
| Audit | Invoice + allocation + ingest attempts | Need fulfilment audit table |
| Journals | `post_finance_journals=false` | Keep off until CA |
| Auto-issue | All flags OFF | Keep off. Hardware must not mint from Cashfree |

**Do not** reuse Old Admin `GenrateInvoice`. It increments `radium_branch.invoice_no` into `IND*`/`INM*` on `radiumbox_prod`. Desk must not write that table.

Historical `IND*` / `INM*` / `INV67*` remain immutable. Do not renumber. Last hardware invoice this session: `IND671904` / `RDE318338` / branch `radium_delhi` / 2026-09-05 / Shipped.

---

## 4. VERIFIED stock / serial capabilities

### Historical Box/Admin (do not copy as Desk writer)

`PosController::Serial`: operator pastes serials; count must equal `order_details.qty`; `product_stock.label` + `is_sold='No'` + `product_id=modelid`; marks sold; writes `product_stock_log` + `order_details.label`. Clone-on-move duplicates labels. **Not** a safe final architecture.

### Desk inventory (this worktree + production)

| Invariant | Desk POS today | Class |
|-----------|----------------|-------|
| One serial row globally unique | `inventory_serials.serial_number` unique | VERIFIED |
| Reserve then sell | `reserveSerials` / `completeSale` locks sorted serials; qty must equal distinct serials | VERIFIED |
| Branches | `DELHI-RETAIL` (1), `MUMBAI` (2) | VERIFIED |
| Cross-DB to `product_stock` | **No application path. Do not grant.** | VERIFIED |

Production Desk on-hand (available serials, this session) for **name-similar** SKUs — **not** a verified Box `modelid` map:

| Desk SKU | Available | Possible Box model |
|----------|-----------|--------------------|
| `RBIMSOE3L1` | 1083 | 951 `PIDMORE3L1` |
| `RBMFS110L1` | 932 | 946 `PMTMFS110Z` (title also says MFS 100/110) |
| `RBMFS100L0` | 134 | same Box line — **ambiguous** |
| `RBMIS100IR` | 651 | 1006 `PMTMIS100Z` |
| `RBFUTFS80H` | **2** | 930 `PFUFS80HQZ` |
| `RBUGR86GPS` | 167 | 1723 named UGR 86 but Box `sku_code=PRUGR89GPS` |
| `RBUGR89GPS` | exists | same ambiguity |

**Stock ledger for fulfilment is UNKNOWN.** Desk opening inventory is a separate ledger from live `radiumbox_prod.product_stock`. Prefer a Box-owned stock reservation API **or** an Owner declaration that Desk `inventory_serials` is now the warehouse of record. Do not dual-write. Do not paste serials as the final design.

Required invariant (target):

> one physical unit → one unique serial → one order-line allocation → one fulfilment event → no duplicate allocation. Multi-qty (RDE318360 line qty 2) must reserve N distinct serials in one transaction.

---

## 5. VERIFIED shipping / Shiprocket capabilities

| Surface | Status | Class |
|---------|--------|-------|
| Desk `main` | No Shiprocket PHP | VERIFIED |
| Production Desk env | no `SHIPROCKET_*` keys | VERIFIED |
| Box live ship | `VENDOR_SHIPROCKET_ENABLED=false`; seven orders `awb` / `s_order_id` / `s_shipment_id` NULL | VERIFIED |
| Old Admin | Hardcoded login + one controller; **do not copy credentials or that POST collapse** | VERIFIED prior P-02-09-12 |
| WIP worktree | S2–S6: `shipments` / outbox / Null+Fake / mapper / AWB / pickup. Flag default OFF. **No live HTTP client** | VERIFIED files on `feat/rd-fresh-01-inventory-pos` |

Required integration boundary (design only):

- Secrets only via `SHIPROCKET_API_EMAIL` / `SHIPROCKET_API_PASSWORD` / pickup nickname / channel id in Desk env
- Merchant `order_id` = Desk shipment number (WIP already uses `shipment_no`)
- Create only after invoice + serials (Owner may swap order; default below)
- **WIP S4 eligibility does not require invoice or serial.** Porting that scaffold without new gates would ship unpaid-tax / unallocated units. Add those gates in P5.
- Idempotent create/search-reconcile; never two Shiprocket orders for one fulfilment
- Pickup nickname **UNKNOWN** — do not invent `RADDELHI`

---

## 6. VERIFIED callback / synchronization capabilities

| Direction | Exists? | Payload |
|-----------|---------|---------|
| Box → Desk ingest | Client + outbox **yes**; production **disabled** | commerce order |
| Desk GET status / PDF | HMAC GET on Desk **yes** | invoice number / PDF |
| Desk → Box invoice callback | Box receiver **yes**; Desk sender **no** | invoice number/status/doc/IRN |
| Desk → Box serial / AWB / shipped | **Neither side** | — |
| Box lookup API | `GET /api/integrations/v1/rd-orders/{id}` read-only | not a fulfilment write |

Minimum **new** Box callback (or additive fields on the existing HMAC endpoint) — design only:

```
source_id
desk_order_no
invoice { number, status, document_reference }
serials [ { line_id, serial } ]
shipment { provider, provider_order_id, shipment_id, awb, tracking_url }
fulfilment_status
```

Idempotent apply. Do not let Box mint or allocate from this callback.

---

## 7. UNKNOWN items requiring owner decision

| ID | Decision | Why it blocks implementation |
|----|----------|------------------------------|
| **H-1** | Hardware **issuer / branch** rule | Historical = operator dropdown (`radium_delhi` / Mumbai slug). Must not silently use shipping state, billing state, POS, inventory location, or GSTIN. Product mint requires `DELHI-RETAIL` or `MUMBAI`. |
| **H-2** | Hardware **number series** | Continue `IND67*`/`INM67*` after `IND671904`, or use Desk product `INV-07671`/`INV-27671`. Families must not collide. Historical numbers stay immutable. CA confirm. |
| **H-3** | **Stock ledger of record** | Desk `inventory_serials` vs Box `product_stock` vs Box reservation API. No cross-project DB grant. |
| **H-4** | **SKU map** Box `modelid`/`sku_code` → Desk `inventory_products.sku` | Name matching is unsafe (MFS 100 vs 110; UGR 86 vs 89). |
| **H-5** | Shiprocket **pickup nickname** and which branch ships | Not on orders. |
| **H-6** | Bundled RD/AMC/OTG on the **tax invoice** | Today one hardware line + FKs. Splitting SAC 99* with HSN 8471 fail-closes mixed issuer. |
| **H-7** | Hardware B2B **IRN** now vs later | RDE318388 has GSTIN. Live IRN still OFF. |
| **H-8** | Link rule for existing Cashfree Desk `orders` vs new `commerce_orders` | Same `RDE*`. Must not mint a second identity. |
| **H-9** | Invoice-before-serial vs serial-before-invoice | Recommended: invoice then serial then ship. Confirm. |
| **H-10** | Whether Desk opening stock is authorised for **online** fulfilment | POS ledger ≠ proven warehouse for Box SKUs. |

Stop short of implementation until **H-1, H-2, H-3, H-4** are locked.

---

## 8. Required data contract

Additive. Do not invent missing fields. Box must send only values it already stores.

**Keep:** current HMAC + idempotency + paid commerce envelope.

**Add (from verified Box columns / catalog):**

| Field | Source | Required to fulfil |
|-------|--------|--------------------|
| `lines[].gst_percentage` | model `products.gst_percentage` | mint |
| `lines[].product_id` / `model_id` | `order_details` | allocation map |
| `lines[].catalog_sku` | model `sku_code` | map / audit |
| `lines[].shipping_line_kind` / `requires_shipping` | existing Box classifier | ship filter |
| `lines[].rdserviceid` / `amcid` / `otgid` | existing | display / later service |
| structured `shipping_address` | `userdetails` if that is ship-to; **do not copy billing if a real ship row exists** | ship |
| `place_of_supply_state` | already `orders.state` | GST split |
| `buyer_gstin` | already `orders.gst_no` / userdetails | B2B |
| Cashfree ids | already | correlation |

**Do not send unless a verified source exists:** warehouse id, pickup nickname, serials, Desk branch, seller GSTIN, country=`India`, invented parcel.

Desk must persist structured address parts, parcel if present, line kind, and catalog ids. `main` does not today.

---

## 9. Proposed hardware fulfilment state machine

New table `hardware_fulfilments` (name may change). Do **not** overload `commerce_orders.status` (invoice-only today) or Cashfree incident status.

```
PAID
  → INGESTED
  → READY_FOR_FULFILMENT
  → INVOICE_ISSUED
  → SERIALS_ALLOCATED
  → SHIPMENT_CREATED
  → AWB_ASSIGNED
  → SHIPPED
  → SYNCED
```

| From | To | Owner | TX boundary | Idempotency key |
|------|----|-------|-------------|-----------------|
| (Box paid) | PAID | Box Cashfree | Box order row | Box `ordercode` |
| PAID | INGESTED | Box outbox → Desk ingest | Desk commerce unique source | `statutory:radiumbox_com:commerce_order:RDE*` |
| INGESTED | READY_FOR_FULFILMENT | Desk eligibility + **H-1 issuer** | fulfilment row | `hw:{commerce_id}:ready` |
| READY | INVOICE_ISSUED | Desk `mint()` only | mint allocate + invoice | same statutory key |
| INVOICE_ISSUED | SERIALS_ALLOCATED | Desk stock service | lock N serials + allocation rows | `hw:{id}:serials` |
| SERIALS_ALLOCATED | SHIPMENT_CREATED | Desk Shiprocket gateway | local shipment + provider create/search | `hw:{id}:shipment` / `shipment_no` |
| SHIPMENT_CREATED | AWB_ASSIGNED | Desk AWB outbox | AWB column unique | `hw:{id}:awb` |
| AWB_ASSIGNED | SHIPPED | Desk (provider track or explicit) | status only | `hw:{id}:shipped` |
| any terminal-ish | SYNCED | Desk → Box callback | Box handoff apply | `hw:{id}:callback:{version}` |

Valid transitions: forward only except `SYNCED` retry. No skip. Operator may hold at READY if issuer is manual (H-1).

### Failure / compensation

| Success / fail | Behaviour |
|----------------|-----------|
| Invoice OK, serial fail | **Keep invoice.** Retry allocation. Never remint. Never cancel for stock miss unless Owner/CA defines CN. |
| Serial OK, shipment fail | Keep allocation **reserved/sold-to-order**. Retry provider with same `shipment_no`. Search-reconcile before create. |
| Shiprocket OK, callback fail | Keep shipment/AWB. Retry HMAC callback only. |
| Ingest 409 conflict | Stop. Do not mutate payload. Human reconcile. |
| Webhook / outbox / worker restart | All keys unique. Duplicate = ACK. |

Rollback of a minted number is **not** allowed. Compensation is credit-note **later** (H-7/CN UNKNOWN).

Reconciliation: nightly job — Box paid `radiumecom` without fulfilment row; fulfilment vs invoice vs serial vs provider search vs Box display copy.

---

## 10. Required database / schema changes (design)

Additive only. No production migrate in this step.

1. `commerce_order_items`: `shipping_line_kind`, `requires_shipping`, `product_id`, `model_id`, `catalog_sku`, persist `gst_percentage` (column exists).
2. `commerce_orders`: structured ship/bill city/pin/country/parcel/pickup **if** still missing on the deployed tree (WIP had some; `main` has `billing_state` only).
3. `hardware_fulfilments`: commerce_id, support_order_id nullable, state, issuer_location, idempotency, timestamps.
4. `hardware_fulfilment_serials`: fulfilment_id, line_id, serial_id unique, serial_number unique-where-allocated.
5. `shipments` (port from WIP) + events.
6. Box: extend `desk_order_handoffs` for serials/AWB/fulfilment_status **or** child table.
7. Optional `channel_sku_map` after H-4.

No Desk FK into `radiumbox_prod`.

---

## 11. Required APIs / events

| API / event | Owner | Notes |
|-------------|-------|-------|
| Existing ingest / status / document | Desk | Extend persist; still no auto-mint |
| Existing Box outbox | Box | Send new fields; keep disabled until secrets |
| `POST /api/desk/fulfilment-status` (or extend invoice-status) | Box receive / Desk send | HMAC, replay window, additive |
| Optional Box `POST /api/integrations/v1/stock/reserve` | Box | Only if H-3 chooses Box ledger |
| Shiprocket HTTP adapter | Desk | New; Null default; flag OFF |
| Outbox `statutory.invoice.issued` / `hardware.serials` / `shipping.shiprocket.*` | Desk | Worker; no mint from payment |
| Finance Hub / fulfilment UI | Desk | Explicit issue + allocate + ship. Not Cashfree webhook. Not C360 service-reference. |

---

## 12. Idempotency strategy

| Action | Key |
|--------|-----|
| Ingest | `statutory:radiumbox_com:commerce_order:{RDE*}` + payload hash. Hash **omits** `metadata`, `ordered_at`, `paid_at`, `support_order_id` — changing only those still 200-duplicates. Put fulfilment fields in the hashed subset (lines/address/tax/ids) |
| Mint | same key inside `mint()` |
| Serial allocate | `hw:{fulfilment}:serials` + unique serial_number |
| Shipment create | `shipment_no` as merchant order_id; provider search on retry |
| AWB | one AWB column unique |
| Callback | `{fulfilment}:{payload_version}` ; Box apply is overwrite-if-same-or-newer |

Cashfree retries, Box outbox retries, and worker restarts must ACK duplicates.

---

## 13. Failure / retry strategy

- Box unconfigured: no attempt burn (already VERIFIED).
- Transient HTTP: exponential backoff, cap, then `failed`.
- 422/409: stop, no payload mutation.
- Provider timeout: search-before-create (WIP S4 pattern).
- PDF fail after mint: number stays; regenerate (existing).
- Never compensate by deleting `IND*`/`INV*` or restocking without a typed movement.

---

## 14. Security boundaries

- No Desk application user on `radiumbox_prod`.
- No Box write of Desk sequences.
- HMAC per channel; empty secret = disabled.
- Do not reuse Cashfree / ingest / callback secrets.
- Never copy Old Admin Shiprocket credentials.
- Secrets never logged.
- Document GET remains HMAC, not public.
- Production SELECTs in this audit used host `sudo mysql` read-only; that is **not** an app integration.

---

## 15. Deployment / rollback strategy

1. Ship code with flags OFF (`DESK_INGEST_ENABLED`, `SHIPROCKET_ENABLED`, `auto_issue_*`, `worker_may_mint`).
2. Additive migrations only, rehearsed.
3. Configure matching `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` on **both** sides without enabling ingest.
4. HMAC reject test, then one non-production fixture.
5. Enable ingest only. Confirm commerce row. **Do not mint.**
6. Enable fulfilment UI for a controlled order **after** H-1…H-4.
7. Shiprocket last, Null→HTTP, flag still explicit.

Rollback: flags off → stop outbox → stop provider. Minted invoices stay. Reserved serials need an explicit typed release. Do not revert sequences.

Do not process the seven orders in an early phase.

---

## 16. Exact implementation phases

| Phase | Work | Implements? |
|-------|------|-------------|
| **P0** | Owner lock H-1…H-4 (and H-5 if shipping in v1) | Docs only |
| **P1** | Contract + Desk persist (gst%, ids, kind, structured address) | Code, flags off |
| **P2** | `hardware_fulfilments` + link Cashfree `orders` by `order_id=RDE*` (H-8) | Code, no mint |
| **P3** | Hardware issue path = `issueFromCommerceOrder()` + **product** issuer only (no service matrix) | Code, manual, flags off |
| **P4** | Atomic serial allocate from chosen ledger (H-3/H-4) | Code |
| **P5** | Port/clean Shiprocket S2–S6 onto `main` + real HTTP adapter still disabled | Code |
| **P6** | Desk → Box fulfilment callback | Both repos |
| **P7** | Enable Box ingest secrets + flag; observe; no mint | Config |
| **P8** | Controlled fulfilment of **new** paid orders | Ops |
| **P9** | Only then consider the seven pending orders, one at a time | Ops |

This prompt stops after P0 design.

---

## 17. Phase dependencies

```
P0 ──► P1 ──► P2 ──► P3 ──► P4 ──► P5 ──► P6 ──► P7 ──► P8 ──► P9
              │              │
              └──────────────┴── P3 also depends on H-1/H-2
                                 P4 depends on H-3/H-4
                                 P5 depends on H-5 for live pickup
```

P3 must not start without P0 H-1/H-2. P5 must not enable HTTP without P4 if serials are required on the label (recommended).

---

## 18. Can this architecture support the seven pending orders?

**Eventually: YES, if P0–P7 are done and each order is processed under the new machine.**  
**Today: NO. Do not process them.**

Independently re-verified 2026-09-07 (this session). Untouched.

| Order | Paid | State | GSTIN | Lines (qty × model) | Invoice | Serial | AWB / SR | Box handoff | Desk Cashfree | Desk commerce |
|-------|------|-------|-------|---------------------|---------|--------|----------|-------------|---------------|---------------|
| RDE318360 | ₹8997 | MP | — | 2×951 MSO1300 + 1×1006 MIS100 | none | none | none | pending, 0 att. | 50935 / SC52042 | **no** |
| RDE318367 | ₹2549 | WB | — | 1×946 MFS100/110 L1 | none | none | none | pending | 51001 / SC52108 | no |
| RDE318378 | ₹4899 | MP | — | 1×930 FS80H | none | none | none | pending | 51107 / SC52214 | no |
| RDE318379 | ₹3549 | UK | — | 1×1006 MIS100 | none | none | none | pending | 51111 / SC52218 | no |
| RDE318382 | ₹3049 | AS | — | 1×951 MSO1300 | none | none | none | pending | 51118 / SC52225 | no |
| RDE318388 | ₹1999 | PB | `03JXBPK4262F1ZO` | 1×1723 UGR86 | none | none | none | pending | 51176 / SC52283 | no |
| RDE318391 | ₹2971 | MH | — | 1×946 MFS100/110 L1 | none | none | none | pending | 51192 / SC52299 | no |

All Box `ordertype=radiumecom`, `payment_type=cashfree`, `branch` NULL, `invoice` 0 rows, `product_stock` 0, `product_stock_log` 0. Desk `serial_number` / `product_name` / `invoice_number` NULL. Incidents `awaiting_product_details` / source `cashfree`.

Address: one `userdetails` JSON each (line/city/state/pin). Country null. Catalog parcel L/H/W null.

Blockers for these seven: H-1, H-2, H-3, H-4, missing ingest secret, missing `gst_percentage` on wire, FS80H Desk stock only 2 (qty 1 — capacity OK **if** map is `RBFUTFS80H`), MFS/UGR map ambiguous.

---

## Recommended lifecycle (payment trigger)

```
Cashfree paid
  → Box local Paid/Processing + outbox (already)
  → Desk Cashfree shell (already; journals/eligibility only)
  → Desk commerce ingest (when enabled; no mint)
  → Operator/system READY_FOR_FULFILMENT (issuer H-1)
  → mint() hardware invoice
  → allocate serials
  → Shiprocket
  → callback display copies to Box
  → Box status Shipped
```

Not a Cashfree side effect. Not Old Admin `GenrateInvoice`. Not a second Desk writer.

---

## Remaining risks / blockers

1. H-1 issuer UNKNOWN — hard stop for mint.
2. H-2 series UNKNOWN — `IND*` vs `INV-*`.
3. H-3/H-4 stock + SKU UNKNOWN.
4. Two live identities per RDE without a link table.
5. WIP Shiprocket must be **ported cleanly**, not merged from dirty `feat/rd-fresh-01-inventory-pos`.
6. Production Desk is overlaid, not tagged to this HEAD.
7. Mixed Product+Service commercial display vs single HSN line (H-6).
8. Country/parcel/pickup gaps for live Shiprocket.
9. B2B IRN and credit notes unset.
10. Enabling Box ingest before P1 persist will **strip** kind/ids and freeze a payload hash — do not enable first.
11. `DESK_INGEST_ENABLED` is not the Box HTTP gate; empty `DESK_BASE_URL` / secret is. Do not treat flipping the unread flag as enablement.
12. POS `issueFromPosSale` is a walk-in product writer, not the online hardware path. It also skips GST split and does not print serials. Do not route RDE* through POS.
13. Hardware Orders UI on Desk is a support-case queue plus an unused warehouse placeholder blade — not a fulfilment engine.
