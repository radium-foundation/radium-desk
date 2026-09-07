# Hardware fulfilment H-1…H-4 design resolution — Step 2

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-13**  
**Date:** 2026-09-07  
**Type:** Read-only design-resolution. No application, schema, config, production, invoice, serial, shipment, or seven-order change.  
**Depends on:** `docs/desk-hardware-fulfilment-architecture-p-07-09-12.md` (Step 1 / P-07-09-12)  
**Verdict:** **SPEC READY FOR IMPLEMENTATION DESIGN** on identity, writer, state machine, APIs, and schema. **NOT READY TO CODE** until owner locks S1-H-1 (issuer), S1-H-2 (series), S1-H-3 (stock ledger), S1-H-4 (SKU map), and S1-H-5 (pickup).

ID note: **this prompt’s H-1…H-4 are design areas.** Step 1 owner blockers are cited as **S1-H-***. Do not conflate them.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| HEAD | `e4c3aec328a2a132d3763c115aeb259021bc2585` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Worktrees | this `main`; `radium-desk` `feat/rd-fresh-01-inventory-pos`; `radium-desk-phase1-clean` detached | VERIFIED |
| Ledger | `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md`) | VERIFIED |
| Step 1 doc | present, untracked | VERIFIED |

Step 1 findings re-checked against current `main` PHP this session: payload_hash omission, commerce-only GST split, `issueFromSupportOrder` → `rdservice_in` only, no Shiprocket on `main`, Box checkout snapshots `users_address` into `userdetails`. All **VERIFIED**.

---

## H-1 — Hardware ingest identity / contract

### Findings re-verified

| Claim | Result |
|-------|--------|
| `payload_hash` omits `metadata`, `ordered_at`, `paid_at`, `support_order_id` | **VERIFIED** `ChannelIngestService::payloadHash` |
| Same source + same hash → HTTP 200 duplicate; different hash → 409 | **VERIFIED** |
| Hardware RDE* live path is Cashfree → `orders`/`incidents`, not commerce ingest | **VERIFIED** production: 7 Cashfree shells, `commerce_orders` `radiumbox_com` = 0 |
| Box already has HMAC commerce outbox for `RDE*` | **VERIFIED** `DeskOutboxService` / `PaidOrderFulfillmentService` |
| `DESK_INGEST_ENABLED` unread; empty URL/secret is the HTTP gate | **VERIFIED** |

### Path choice (architecture, not preference): **B — commerce-order path**

| Option | Verdict | Why |
|--------|---------|-----|
| A. Remain on support-order path | **Reject** | Cashfree `createOrder` stores no lines, HSN, GSTIN, address, qty, or model. `issueFromSupportOrder()` looks up **only** `rdservice_in`. Hardware queue is a support placeholder, not fulfilment. |
| B. Commerce-order path | **Select** | Box outbox, Desk ingest, `mint()` / `issueFromCommerceOrder()`, and WIP Shiprocket first-wave are all `commerce_orders`. Unique `(channel, source_type, source_id)` already exists. |
| C. New unified hardware ingest adapter | **Reject** | No such adapter exists. Inventing a third identity would create a second writer surface. |

After ingest, **link** the existing Cashfree support row (`orders.order_id = RDE*`) onto `commerce_orders.support_order_id`. That is identity correlation, not option C.

### Canonical identity (immutable)

| Layer | Value |
|-------|--------|
| Business id | Box `orders.ordercode` = `RDE{orders.id}` |
| Desk commerce | `channel=radiumbox_com`, `source_type=commerce_order`, `source_id=RDE*` |
| Idempotency / mint key | `statutory:radiumbox_com:commerce_order:RDE*` |
| Desk support shell | `orders.order_id=RDE*` (Cashfree). Optional FK `commerce_orders.support_order_id` |
| Cashfree | Store **both** Box `payment_id` and Desk `cashfree_payment_id`. They are **not** the same integer on the seven orders (VERIFIED). Correlate by `RDE*`, not by assuming one payment id. |

Retries: same key + same **canonical hash** → 200. Same key + different hash → 409, no mutation. Never create a second commerce row for the same `RDE*`.

`support_order_id` on the ingest payload is optional and **must not** be an alternate unique identity. If supplied, Desk links only when `orders.order_id === source_id`; mismatch fail-closed.

### Payload hashing (required change before enablement)

Today’s hash is insufficient for hardware. Canonical hash **must** include every field that affects tax, allocation, or ship:

- identity: channel, source_type, source_id, source_order_id
- payment: status, provider, reference, method, currency
- customer name/phone/email, buyer_gstin
- structured billing + shipping parts (not only flattened text)
- billing_state, place_of_supply_state
- branch_code / seller fields if ever sent
- discount
- every line: description, qty, unit_price, sku, catalog_sku, product_id, model_id, hsn_sac, gst_percentage, taxable/tax/line totals, components, shipping_line_kind, requires_shipping, rdserviceid/amcid/otgid

Still omit: secrets, wall-clock `paid_at` if Box sends `now()` on every retry (**VERIFIED** Box sets `paid_at` = `Carbon::now()` on each payload build — hashing it would 409 every retry). Persist first `paid_at`; do not hash Box’s retry timestamp.

### Minimum payload contract (only verified sources)

| Need | Field | Source | Required to fulfil? |
|------|-------|--------|---------------------|
| Order identity | `source_id` / `source_order_id` | `ordercode` | Yes |
| Payment | `payment_status=paid`, provider `cashfree`, `payment_reference` | Box `payment_id` or ordercode | Yes |
| Customer | `customer.name/phone/email` | user / `userdetails` | Yes (name or phone already required) |
| GSTIN | `customer.gstin` | `orders.gst_no` / address `gst_no` | Yes if B2B; omit if empty |
| Billing / shipping | structured `billing_address` / `shipping_address` | **Same snapshot:** checkout copies `users_address` → `userdetails` (**VERIFIED** `CheckoutController`) | Yes |
| Billing state | `billing_address.state` | that snapshot | Yes for B2C service issuer; for hardware issuer **not** used unless Owner says so |
| Place of supply | `place_of_supply_state` | `orders.state` (copied from that address) | Yes for GST split |
| Line identity | `product_id`, `model_id`, `catalog_sku`, `sku` | `order_details` + model `sku_code` | Yes for allocation map |
| Qty / money | qty, unit_price, taxable, tax_total, line_total, **gst_percentage** | details + **model** `products.gst_percentage` (parent often NULL) | Yes for mint |
| Physical marker | `shipping_line_kind` / `requires_shipping` | existing Box classifier (`modelid` → physical) | Yes for ship filter |
| Warehouse / pickup | — | **not on the order** | **UNKNOWN** (S1-H-5) |
| Serials | — | not allocated at ingest | Must not send |
| Desk branch / seller GSTIN | — | operator/Owner rule, not Box | **UNKNOWN** (S1-H-1) |
| Country / parcel | only if catalog/address has them | seven orders: country NULL, model L/W/H NULL | Optional; do not invent |

Do not enable Box ingest until Desk persists kind/ids/gst% **and** hashes them. Enabling now would strip fields and freeze a weak hash.

---

## H-2 — Hardware statutory invoice

### Findings re-verified

| Claim | Result |
|-------|--------|
| `GstSplitService` only on `commerce_order` mint | **VERIFIED** `applyServiceGstSplit` early-return |
| `issueFromPosSale` is walk-in product writer; no split; no serials on PDF | **VERIFIED** |
| Hardware must not use `issueFromPosSale` | **Confirmed** — POS has no Box lines, no RDE identity |
| `issueFromSupportOrder` cannot mint RDE* today | **VERIFIED** — `rdservice_in` only |
| One writer | `StatutoryInvoiceService::mint()` | **VERIFIED** |
| Historical `IND*`/`INM*` | last hardware `IND671904` / RDE318338 / `radium_delhi` | **VERIFIED** Step 1 SELECT |
| Old Admin `GenrateInvoice` | operator `$request->branch` → `radium_branch.slug` | **VERIFIED** |

### Authoritative service (design)

**Writer:** existing `mint()` only.  
**Entry:** `issueFromCommerceOrder($commerce)` for `radiumbox_com` + product HSN.  
**Do not** add `issueFromHardwareOrder` as a second allocate path. A thin `HardwareFulfilmentService::issueInvoice()` may **delegate** to `issueFromCommerceOrder` after fulfilment-state + issuer guards.

| Requirement | Spec |
|-------------|------|
| Idempotency | `statutory:radiumbox_com:commerce_order:RDE*` unique |
| FY / sequence | Existing `StatutoryLocationSeries` + `invoice_sequences` lockForUpdate |
| B2B/B2C | Existing `BuyerGstin`; invalid GSTIN fail-closed |
| GST | Commerce `GstSplitService`: seller GST state vs `place_of_supply_state`; requires `gst_percentage` |
| HSN | Product HSN 4–8 digits, not `99*`. Seven-order lines `84716050` / `85269190`. Bundled RD FKs stay on the **same hardware line** (S1-H-6) unless CA requires split (mixed 99+8471 fail-closes) |
| Arithmetic | Ingest amounts authoritative; split must match taxable × rate |
| PDF | Private disk; **defer generate until serials allocated** (current generate-at-mint would freeze a no-serial PDF; documents are immutable once written) |
| Audit | existing invoice + allocation + new `hardware_fulfilment_events` |
| Linkage | `statutory_invoices.source_*` + `commerce_orders.statutory_invoice_id` unique + fulfilment.invoice_id |
| Immutable | existing `IMMUTABLE_AFTER_ISSUE` |
| Cancel / CN | `cancel()` status-only today. Hardware returns **UNKNOWN** — do not auto-CN. Do not cancel a number to retry mint |
| No Admin writer | Desk does not INSERT `radiumbox_prod.invoice` or increment `radium_branch.invoice_no` |
| No remint of `IND*`/`INM*`/`INV67*` | scope ≥ 2026-09-01 new Desk numbers only |

Auto-issue / `worker_may_mint` stay **OFF**. Payment webhook must not mint.

### Issuer / series — UNKNOWN (do not invent)

Owner-locked **service** matrix must **not** be applied to hardware.

Owner-locked **product** rule is: issuer = inventory/POS branch `DELHI-RETAIL` → `INV-07671…`, `MUMBAI` → `INV-27671…`. That is a **walk-in stock location** rule, not a verified online-fulfilment rule.

Historical online hardware: **operator dropdown** (`radium_delhi` / Mumbai slug) → `IND*` / `INM*`.

**Must not** silently use shipping state, billing state, place of supply, GSTIN, or current POS stock location.

**Owner decisions required before P3 code:**

| ID | Decision |
|----|----------|
| **S1-H-1** | How is hardware issuer chosen? Explicit operator action, or a documented default branch for all online hardware, or another Owner rule. |
| **S1-H-2** | Number family: continue `IND*`/`INM*` after `IND671904`, or Desk product `INV-07671`/`INV-27671` under a mapped Desk location. Families must not collide. CA confirm. |

Until then, fulfilment may ingest and stop at `READY_FOR_FULFILMENT`. Mint fail-closed without issuer.

---

## H-3 — Hardware stock / serial allocation

### Existing integrations (do not invent a live one)

| Option | Exists? | Verdict |
|--------|---------|---------|
| A. Box stock reserve API | **No** (Box only SELECTs `product_stock` for storefront display; no reserve/commit route) | Cannot consume what does not exist |
| B. Stock allocation outbox/event | **No** | — |
| C. Existing legitimate Desk allocator | **Yes** — `InventoryStockService` (`reserveSerials` / `lockAvailableSerialsForSale` / `markSerialSold`), unique `inventory_serials.serial_number`, branch `DELHI-RETAIL`/`MUMBAI` | Only existing safe engine **on Desk** |
| D. Desk SQL to `radiumbox_prod.product_stock` | Forbidden. Labels not unique. No app connection | Reject |

**Boundary:** Desk must not open a `radiumbox_prod` connection. If Owner declares Box `product_stock` the warehouse of record, Box must **build** a HMAC reserve/commit/release API (new work, not an existing integration). If Owner declares Desk opening stock authorised for online fulfilment (S1-H-10), use C.

**SKU map (S1-H-4) is UNKNOWN** either way. Name matching is unsafe (MFS 100 vs 110; UGR 86 vs `PRUGR89GPS`). Required table `channel_sku_map` (`channel`, `model_id`/`catalog_sku` → `inventory_products.id`) filled by Owner, not inferred.

### Invariants

- One serial → one fulfilment line unit; unique allocation row; serial status not available
- Serial’s product must match mapped model
- Multi-qty: N distinct serials, sorted locks, one DB transaction
- Retry: return existing allocation for `hw:{fulfilment_id}:serials`; never pick a new serial if rows exist
- Audit: `inventory_movements` + `hardware_fulfilment_serials` + fulfilment events
- No operator-paste as the final design (Admin `PosController::Serial` is historical only)

### Sequence: invoice, then allocate (with PDF deferred)

| Option | Evidence | Failure |
|--------|----------|---------|
| Allocate then invoice | Extra hold; cross-system compensate if Box stock | Mint fail → must release; more moving parts |
| Same DB txn invoice+allocate | Sequence number + stock in one txn | Rollback after allocate() can gap sequences; Box API cannot join the txn |
| **Invoice then allocate** | Historical Admin: invoice → serial → Shiprocket. User-required SM. Immutable number | Stock miss: **keep invoice**, retry same fulfilment, never remint |
| PDF at mint | Current code; file immutable | Hardware PDF would lack serials |

**Selected:** `INVOICE_ISSUED` then `SERIALS_ALLOCATED`. **Do not generate/store the hardware PDF until serials are allocated** (or generate only after; GET remains allowed to build once). If Owner later requires serials on the face before allocation, switch to reserve-then-mint.

Compensation: allocation fail → state stays `INVOICE_ISSUED`, outbox retry. Do not CN for stock miss unless CA defines it.

---

## H-4 — Shiprocket / shipping

### Findings re-verified

- `main`: no Shiprocket PHP. Production `SHIPROCKET_*` KEY_ABSENT.
- Box: `VENDOR_SHIPROCKET_ENABLED=false`; track-only controller; **hardcoded login in source — do not copy**.
- WIP: S2–S6 Null-bound; create eligibility = paid + address + parcel + pickup + physical lines; **no invoice, no serial**.
- Live Box ingest gate: empty URL/secret.

### New Desk shipping (design only)

Port WIP schema/processors **onto `main` as a clean slice**, then add gates. Bind Null. `SHIPROCKET_ENABLED=false`. Credentials only from env (`SHIPROCKET_API_EMAIL`, `SHIPROCKET_API_PASSWORD`, `SHIPROCKET_CHANNEL_ID`, `SHIPROCKET_PICKUP_LOCATION`, webhook key). Never from Admin/Box PHP.

**Create allowed only when all are true:**

1. `hardware_fulfilments.state = SERIALS_ALLOCATED`
2. statutory invoice issued and linked
3. allocated serial count = sum of physical line qty
4. structured ship-to (line, city, state, pincode) present
5. pickup nickname present from **Owner-configured** `SHIPROCKET_PICKUP_LOCATION` or fulfilment snapshot — **UNKNOWN today; do not invent `RADDELHI`**
6. commerce paid

| Topic | Spec |
|-------|------|
| Idempotency | `shipment_no` unique = merchant `order_id`; outbox `shipping:shiprocket:create:{shipment_id}`; unique `commerce_order_id` (one shipment per commerce order for v1) |
| Duplicate prevent | Registrar reuse; search-by-merchant-id before create on retry/timeout |
| Persist | `external_order_id`, `external_shipment_id`, `awb` (unique), courier fields, snapshots |
| Retry | Existing outbox backoff; non-retryable (`ShiprocketNonRetryableException`) fail-closed |
| Timeout / uncertain | Do not create again; `search` / `track`; bind if found; else retryable |
| Partial success | Provider created, local persist fail: reconcile job searches `shipment_no`, then persist |
| Webhook | WIP has no route. v1 = poll/track + outbox. Inbound webhook later, HMAC/API key, optional |
| SHIPPED | Track status mapped to shipped **or** explicit operator confirm. Then callback |
| Ship OK, callback fail | Keep AWB; retry `hw:{id}:callback:{ver}` only |
| Pickup | After AWB; nickname fail-closed if unset |

COD: seven orders are Cashfree prepaid. Map `cashfree` → Prepaid (WIP already does). COD aliases reserved; do not invent COD for these.

---

## State machine (final proposed)

Bookend `INGESTED` and `SYNCED` around the required spine. Do not reuse `commerce_orders.status` or incident status as the machine.

```
PAID → INGESTED → READY_FOR_FULFILMENT → INVOICE_ISSUED
  → SERIALS_ALLOCATED → SHIPMENT_CREATED → AWB_ASSIGNED → SHIPPED → SYNCED
```

| State | Owner | Source of truth | Trigger | Idempotency | Retry | Failure | Reconcile |
|-------|-------|-----------------|---------|-------------|-------|---------|-----------|
| PAID | Box | `radiumbox_prod.orders` Paid/Processing | Cashfree confirm | `ordercode` | Box payment retry | stay unpaid / mismatch | Box fetchOrder |
| INGESTED | Box outbox → Desk | `commerce_orders` unique source | HMAC POST | `statutory:radiumbox_com:commerce_order:RDE*` + hash | outbox backoff; unconfigured does not burn | 409 conflict stop; 422 reject | attempts table |
| READY_FOR_FULFILMENT | Desk | `hardware_fulfilments` | eligibility + **S1-H-1 issuer set** | `hw:{id}:ready` | wait owner/operator | stay INGESTED | report missing issuer/gst%/map |
| INVOICE_ISSUED | Desk `mint()` | `statutory_invoices` | operator/worker after READY | same statutory key | return existing invoice | stay READY; no number consumed on eligibility fail | findBySource |
| SERIALS_ALLOCATED | Desk stock or Box API | `hardware_fulfilment_serials` | after invoice | `hw:{id}:serials` | same serials only | stay INVOICE_ISSUED | unique serial + fulfilment |
| SHIPMENT_CREATED | Desk Shiprocket | `shipments` | after serials + gates | `shipment_no` / create outbox | search-before-create | stay SERIALS_ALLOCATED | provider search |
| AWB_ASSIGNED | Desk | `shipments.awb` | after create bind | `hw:{id}:awb` | search/track | stay SHIPMENT_CREATED | unique AWB |
| SHIPPED | Desk | fulfilment + shipment status | track or operator | `hw:{id}:shipped` | track poll | stay AWB_ASSIGNED | track |
| SYNCED | Desk → Box | Box handoff display copies | HMAC callback | `hw:{id}:callback:{ver}` | callback only | stay SHIPPED | Box GET / handoff row |

Forward-only except `SYNCED` retry. No skip.

### Explicit failure models

| Case | Behaviour |
|------|-----------|
| Invoice OK / serial fail | Keep invoice. Retry allocation. Never remint. No CN unless CA. |
| Serial OK / shipment fail | Keep sold/reserved-to-order serials. Retry create with same `shipment_no`. Search first. |
| Shiprocket OK / callback fail | Keep provider ids + AWB. Retry HMAC callback. |
| Callback OK / Box status update fail | Box `storeInvoiceCopy` vs `orders.status` are separate. Callback v1 writes display copies + optional `invoicecode`; **Box `status=Shipped` is a distinct Box write**. If that update fails, retry callback apply; Desk stays SHIPPED/SYNCING. Do not recreate AWB. |
| Worker crash after provider call, before persist | Reconcile: search merchant `order_id` / track; persist then advance. Outbox at-least-once + unique keys. |
| Worker crash after mint, before fulfilment row update | `findBySource` + link invoice id; advance to INVOICE_ISSUED. |

---

## Database / API design (do not migrate)

### Tables (additive)

**`hardware_fulfilments`**

| Column | Notes |
|--------|-------|
| id | PK |
| commerce_order_id | FK unique — one fulfilment per commerce order |
| support_order_id | nullable FK-less id of Desk `orders` |
| state | enum string, indexed |
| issuer_location | `delhi`/`mumbai` — set only by Owner rule or operator; nullable until READY |
| statutory_invoice_id | nullable unique FK |
| shipment_id | nullable unique FK |
| idempotency_key | unique `hw:commerce:{id}` |
| last_error, attempts | |
| timestamps | |

Indexes: `state`, `support_order_id`.

**`hardware_fulfilment_serials`**

| Column | Notes |
|--------|-------|
| fulfilment_id | FK |
| commerce_order_item_id | FK |
| serial_id | nullable if Box ledger (store `serial_number` only) |
| serial_number | |
| status | reserved/allocated |

Uniques: `serial_number` (allocated), `(fulfilment_id, commerce_order_item_id, serial_number)`.

**`hardware_fulfilment_events`** — append-only: fulfilment_id, from_state, to_state, actor, payload_json, created_at.

**`channel_sku_map`** — unique `(channel, model_id)`; `inventory_product_id`; Owner-maintained.

**`commerce_order_items` additive:** `shipping_line_kind`, `requires_shipping`, `product_id`, `model_id`, `catalog_sku`.

**`commerce_orders` additive if still missing on `main`:** structured city/pin/country, parcel, pickup (WIP had some). `billing_state` already exists.

**`shipments` / `shipment_events`:** port WIP migration as-is (uniques on `shipment_no`, `commerce_order_id`, `awb`, provider external ids).

**`outbox_events`:** reuse. Types: `hardware.fulfilment.advance`, `statutory` existing, `shipping.shiprocket.create|assign_awb|pickup`, `hardware.box.callback`.

Box: additive columns on `desk_order_handoffs` for serials JSON, awb, shipment ids, fulfilment_status — or child table. Unique `source_id` already implied.

### APIs

| Endpoint | Auth | Behaviour |
|----------|------|-----------|
| `POST /api/v1/channel-orders` | HMAC `X-Desk-*` | persist; no mint; 201/200/409 |
| `GET /api/v1/channel-orders/{type}/{id}` | HMAC | status + invoice number |
| `GET …/document` | HMAC | PDF after serials+generate |
| Desk operator: Finance/fulfilment Issue / Allocate / Ship | session + new `hardware.fulfilment.operate` | delegates to services; no payment mint |
| Desk → `POST {BOX}/api/desk/fulfilment-status` | HMAC `DESK_CALLBACK_SECRET` | invoice + serials + shipment + state |
| Box existing `POST /api/desk/invoice-status` | HMAC | keep for invoice-only; prefer additive fulfilment endpoint so serial/AWB are not jammed into invoice{} |
| Optional Box `POST /api/integrations/v1/stock/{reserve,commit,release}` | Desk HMAC | **only if S1-H-3 = Box ledger** |

Replay: 300s timestamp window, `hash_equals`. Empty secret = 401.

Authorization: channel secret ≠ user session. Operator actions Admin-only. No public document URL.

---

## Seven pending orders

Re-verified **2026-09-07 this session**. **UNTOUCHED.** No invoice, serial, AWB, Shiprocket id, Box branch, or Desk commerce row.

| Order | Present | Missing for implementation |
|-------|---------|----------------------------|
| All seven | Paid, Processing, lines, HSN, tax amounts, `userdetails` address, `orders.state`, Cashfree Desk shell, pending handoff 0 attempts | Desk commerce row; `gst_percentage` on wire; model→Desk SKU map; issuer/branch; pickup; country; parcel dimensions; serials; invoice; AWB; Box `status=Shipped` |
| RDE318360 | qty 2 on MSO line | two distinct serials after map |
| RDE318388 | GSTIN `03JXBPK4262F1ZO` | IRN policy (S1-H-7); still no mint now |
| RDE318378 | FS80H 930 | Desk `RBFUTFS80H` only 2 available **if** map confirmed |
| RDE318367 / 391 | MFS 946 `PMTMFS110Z` | 110 vs 100 SKU UNKNOWN |
| RDE318388 | UGR 1723 `PRUGR89GPS` named UGR 86 | 86 vs 89 SKU UNKNOWN |

Do not process them in early phases.

---

## Owner decisions (cannot infer)

### VERIFIED (no decision)

Commerce path B; `mint()` only; HMAC ingest; Cashfree shells exist; Box outbox exists; invoice callback receiver exists; GST split is commerce-only; WIP Shiprocket lacks invoice/serial gates; seven orders unpaid-tax / unallocated.

### INFERRED (do not upgrade)

- `userdetails` is both bill and ship for v1 (checkout snapshots one `users_address`).
- Prepaid = Cashfree for these seven.
- PDF should wait for serials because current document rows are immutable.
- One shipment per commerce order in v1.

### UNKNOWN (block implementation)

| ID | Decision |
|----|----------|
| **S1-H-1** | Hardware issuer / branch |
| **S1-H-2** | `IND*`/`INM*` vs `INV-07671`/`INV-27671` |
| **S1-H-3** | Desk `inventory_serials` vs new Box stock API |
| **S1-H-4** | Explicit SKU map for at least the seven models |
| **S1-H-5** | Pickup nickname |
| **S1-H-6** | Bundled RD/AMC on the tax face (keep one hardware line unless CA says split) |
| **S1-H-7** | B2B IRN now vs later |
| **S1-H-10** | If Desk stock: is opening inventory authorised for **online** fulfilment? |

---

## Implementation phases (do not implement here)

| Phase | Scope | Non-goals | Expected change | Schema | APIs | Tests | Deploy gate | Rollback | Depends |
|-------|-------|-----------|-----------------|--------|------|-------|-------------|----------|---------|
| **P0** | Lock S1-H-1…H-5 | Code | docs only | none | none | none | Owner sign-off | n/a | — |
| **P1** | Persist gst%/ids/kind/structured address; **widen payload_hash**; ignore Box retry `paid_at` in hash | Enable ingest, mint | `ChannelIngest*` | additive item/address cols | existing POST | hash 409 vs 200; strip vs persist | flags off | revert persist; no live Box traffic | P0 fields that are known |
| **P2** | `hardware_fulfilments` + link Cashfree `orders.order_id=source_id` | Mint/ship | new service | fulfilment tables | none public | link + unique | flags off | drop unused empty tables | P1 |
| **P3** | `HardwareFulfilmentService::issueInvoice` → `issueFromCommerceOrder`; product issuer only; PDF deferred | Auto-issue, POS, Admin remint, service matrix | statutory + fulfilment | none beyond P2 | operator Issue | idempotent mint; reject POS path; reject no issuer | flags off; issuer must be locked | flag off; issued numbers stay | P0 S1-H-1/H-2, P2 |
| **P4** | Atomic allocate via chosen ledger + map | Paste UI as final; radiumbox_prod SQL | stock + map | `channel_sku_map`, serial rows | optional Box stock API | concurrency N-qty; retry same serials | map rows for SKUs in play | release typed movement only | P0 S1-H-3/H-4/H-10, P3 |
| **P5** | Clean-port WIP Shiprocket + **invoice+serial+pickup gates**; Null HTTP | Live provider | shipping port + eligibility | WIP shipments | none live | Fake gateway; reject without invoice/serial | `SHIPROCKET_ENABLED=false` | flag off | P4, S1-H-5 for live |
| **P6** | Desk→Box fulfilment HMAC callback | Box status invent | Desk client + Box apply | Box handoff cols | `POST /api/desk/fulfilment-status` | HMAC replay; idempotent apply | secrets set, ingest still optional | disable sender | P3+ |
| **P7** | Enable Box ingest secret + URL; observe 7+new handoffs as commerce rows | Mint/ship | env only | none | existing | 401 then 201 fixture | secret match; hash includes new fields | empty URL | P1, P2 |
| **P8** | Fulfil **new** paid orders end-to-end | The seven | ops | none | operator | staging then one live new order | all flags explicit | flags off | P0–P7 |
| **P9** | Seven pending, **one at a time** | Bulk backfill | ops | none | operator | checklist per order | Owner go | stop after any fail | P8 + S1-H-4 rows for those models |

**Minimum safe path to the seven:** P0 → P1 → P2 → P7 (ingest only, confirm 7 commerce rows, no mint) → P3–P6 on a **new** order → P8 pass → P9 one RDE.

---

## Remaining risks / blockers

1. S1-H-1…H-5 unlocked.  
2. Enabling ingest before P1 hash/persist.  
3. Dirty WIP merge vs clean port.  
4. Production Desk is an overlay, not tagged `e4c3aec`.  
5. Dual payment ids (Box vs Desk Cashfree).  
6. Immutable PDF vs serials.  
7. Credit notes / IRN unset.  
8. Parcel/country gaps for live Shiprocket even after gates.

---

## Amendment — serial-first (2026-09-07, P-07-09-15)

The invoice-then-serial order in this document is **superseded**. Owner-locked sequence is now SERIALS_ALLOCATED before INVOICE_ISSUED. See P0 §12 and `docs/desk-hardware-fulfilment-p1-foundation-p-07-09-15.md`.
