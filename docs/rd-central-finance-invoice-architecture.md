# Central Finance + Statutory Invoice Architecture

> **Canonical status (RadiumDesk-P-05-09-08):** Statutory numbers are `INV-{GST_STATE}{FY}{SERIAL}`. FY26–27 first numbers are Delhi `INV-07671` and Mumbai `INV-27671`. Product issuer is branch-based; service issuer is B2B/B2C + customer state. Historical Admin numbers are not reminted. See `docs/desk-statutory-location-numbering.md`.

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Prompt:** User-labelled `RadiumDesk-P-01-09-13` (already used on this branch for the inventory Day-1 blocker audit). Ledger ID for this investigation: **RadiumDesk-P-01-09-14**. Implementation foundation: **RadiumDesk-P-01-09-15**. Channel ingest: **RadiumDesk-P-01-09-16**. Accountant month-end reports: **RadiumDesk-P-01-09-17**.  
**Date:** 2026-09-01  
**Type:** Investigation (P-01-09-14) plus Desk-only implementation foundation (P-01-09-15 through P-01-09-17). Not the final legal invoice-series decision. No production, Admin, RadiumBox, rdservice.in, or radiumsign.com changes.  
**Canvas:** [`rd-central-finance-invoice-architecture.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-central-finance-invoice-architecture.canvas.tsx)

Classification used throughout: **VERIFIED** (read from Desk/Admin-documented code or prior production inspections already in this repo), **INFERRED** (consistent with code/docs but not re-executed here), **UNKNOWN** (owner/CA/legal or unreinspectable).

This document does **not** choose a final legal invoice series. That is a business/CA decision. See §7 and §18.

---

## 1. Executive summary

Owner-confirmed business model:

- **One legal entity.**
- `desk.radiumbox.com` becomes the **central operational and financial hub**.
- Desk POS, rdservice.in, radiumbox.com, rdservice.net, radiumsign.com, and future channels feed that hub.
- The accountant/CA gets a **restricted Desk login** for month-end and statutory reporting.
- Invoice numbering/series must **not** be invented independently by each website.
- Do **not** build a separate statutory invoice engine for rdservice.in.

Target principle:

```
ONE LEGAL ENTITY
  → ONE CENTRAL FINANCIAL SOURCE OF TRUTH (Desk)
    → ONE AUTHORITATIVE STATUTORY INVOICE ENGINE
      → ONE CONTROLLED INVOICE-SERIES STRATEGY
        → MULTIPLE ORDER CHANNELS
          → ONE ACCOUNTANT / CA REPORTING PORTAL
```

What exists today is **not** that system:

| Layer | Today | Target |
|-------|-------|--------|
| Statutory invoice | Admin `GenrateInvoice` on shared ERP tables (**VERIFIED**) | Desk-only engine |
| POS invoice | Internal `INV-{branch}-{year}-{seq}` — labelled not GST/IRN (**VERIFIED**) | Keep as internal receipt; link to statutory invoice |
| Support `orders.invoice_number` | Fill-missing copy of Admin `invoicecode` (**VERIFIED**) | Display of Desk statutory number, not a second issuer |
| Channel invoicing | rdservice.net paid fulfill does **not** mint invoices; Admin does (**VERIFIED**) | Channel sends order/payment; Desk mints invoice |
| Finance GL | Double-entry exists; revenue on payment/sale; no GST payable split (**VERIFIED**) | Invoice-aware tax + cash/bank/UPI; Cash Book-style reversals |
| CA portal | Missing (**VERIFIED**) | Restricted accountant role + month-end exports |

**Do not destroy** the current POS `INV-*` document. It stays an internal operational receipt. The statutory GST invoice is a **new** Desk document that may be created from a POS sale, a channel commerce order, or (later) a linked support order.

**Stop on numbering format:** a concurrency-safe numbering **service** can be designed. The **legal series string** (prefix, GSTIN scope, financial-year reset, continuation of Admin `INV67` / `IND67` / `INS…`) cannot be established without owner/CA confirmation. That item is **UNKNOWN**. This investigation continues around that gap; it does not implement numbering and does not mint invoices.

---

## 2. Current Desk capabilities

Inspected this ticket from Desk code plus already-verified docs: `docs/rd-fresh-01-inventory-pos-foundation.md`, `docs/rd-fresh-01-pos-finance-gap-audit.md`, `docs/finance-architecture-audit.md`, `docs/rdservice-successful-orders-invoice-path-investigation.md`, `docs/rdservice-desk-order-api-integration.md`, `docs/rd-fresh-01-inventory-opening-field-matrix.md`, `docs/rd-fresh-01-radiumbox-inventory-investigation.md`, `docs/cash-book-phase1.md`, `docs/radium-desk-v2-master-architecture.md`. Admin/production were **not** re-queried.

### 2.1 Map

| Domain | What exists | Usable as-is? | Must evolve | Class |
|--------|-------------|---------------|-------------|-------|
| POS sale | `inventory_sales` + lines + serials; one-transaction complete | Yes, as **operational** sale | Must not become the statutory invoice | VERIFIED |
| Support order | `orders` (Cashfree/legacy/intake); unique `order_id`, unique `cashfree_payment_id` | Yes, as **service** identity | Lacks lines, tax split, addresses, HSN, seller GSTIN | VERIFIED |
| Invoice | POS printable internal invoice; `orders.invoice_number` is an external copy | Internal POS print yes | Need a new statutory invoice entity | VERIFIED |
| Finance journal | `JournalPostingService`: balanced, unique `idempotency_key`, `lockForUpdate` | Yes, as GL engine | Need tax accounts, invoice/credit-note sources, period lock | VERIFIED |
| Cash | GL `1000` + Cash Book + Cash Ledger UI | Ops cash yes | Not a statutory cash book; POS cash already posts | VERIFIED |
| Bank | GL `1100` clearing + Bank Ledger UI | Clearing yes | No settlement↔GL recon; UPI not a distinct GL | VERIFIED |
| Payment | Cashfree webhook → order columns; POS `payment_method` + optional reference | Payment capture yes | No `payments` table; no split tender; no allocation to invoices | VERIFIED |
| Customer | Support identity on `orders`; POS `inventory_customers` (unique phone, optional GSTIN) | Partial | No billing address, place of supply, or party master shared across channels | VERIFIED |
| Branch | `inventory_branches` (code, name, GSTIN, `invoice_sequence`) | Location yes | Missing address/state/city/pincode required for GST invoice print | VERIFIED |
| GST | Exclusive line price × `gst_percentage`; one tax total; HSN stored on product, **not printed** | Retail calc yes | No CGST/SGST/IGST, place of supply, B2B/B2C classification | VERIFIED |
| Cancellation | POS: restock + reversing `pos_sale` journal; invoice number kept | Internal reverse yes | Not a GST credit note / IRN cancel | VERIFIED |
| Return | Sale-level return; same reverse as cancel; no partial lines | Ops restock yes | Need partial return + credit note | VERIFIED |
| Finance reversal | Cash Book pattern: original journal kept; debit/credit swapped; unique reverse key | **Preserve this pattern** | Extend to invoice/credit-note journals | VERIFIED |
| Permissions | Spatie roles; finance is admin-team **view**; no accountant role | Isolation for hardware/POS yes | Need `accountant` role + invoice/report perms | VERIFIED |
| Reporting | Finance TB/P&L/GST reports **missing**; cash/bank movement lists only | GL data exists | Build CA reports from invoice + journal facts | VERIFIED |

### 2.2 POS sale (keep)

```
completeSale (one DB transaction)
  1. Idempotency lookup (no FOR UPDATE on a missing unique key)
  2. Lock branch (invoice_sequence)
  3. Create sale + lines
  4. Lock serials / deduct quantity
  5. Assign INV-{branch}-{year}-{seq}
  6. Consume reservation if any
  7. PosSaleJournalService::postForSale(failClosed: true)
     Dr cash 1000 or bank 1100 / Cr revenue 4000
  8. InventorySaleCompleted event
```

**VERIFIED** in `PosSaleService` and P-01-09-09/13 InnoDB tests. Unique `inventory_sales.invoice_number`. Calendar year in the string, not Indian FY. Sequence is **per branch**, not per GSTIN.

Cancel/return: stock restore + `pos_sale:reverse:{sale_id}:{journal_id}`; original `finance_journal_id` kept; handoff `reversed`. **Not** a GST credit note.

### 2.3 Support order (keep, do not overload)

`orders` is the service-desk commercial case: Cashfree payment, customer identity, serial for **service**, enrichment from RDService/Admin, refund commercial state. It stores `gst_number` and `invoice_number` as fill-missing copies. It does **not** store line items, HSN, tax, billing address, or seller GSTIN (**VERIFIED**).

Do **not** turn `orders` into the statutory invoice table. Link it.

### 2.4 What is already reusable

- `JournalPostingService` + unique journal idempotency
- Cash Book reversing-entry pattern
- POS sale idempotency (`inventory_sales.idempotency_key`)
- Cashfree payment uniqueness on `orders.cashfree_payment_id`
- Branch GSTIN column (value completeness is a data problem)
- Product HSN + GST %
- `inventory_customers.gstin` (counter UI does not collect it today)
- `audit_logs` morph audit
- Spatie permission seeder (idempotent)

### 2.5 What must not be reused as statutory truth

- `inventory_branches.invoice_sequence` / POS `INV-*`
- `orders.invoice_number` (Admin copy)
- Admin `radium_branch.rd_no` / `invoice_no` (live commercial; copying desyncs Admin)
- `media.radiumbox.com` as a hard-wired GSP (abstraction required)

---

## 3. Current Admin invoice architecture

Read-only from prior investigations. Admin and `radiumbox_prod` were **not** modified or re-queried this ticket.

### 3.1 Who actually numbers invoices

Not the storefront checkout. **Admin** (**VERIFIED**, P-31-08-14):

```
Admin GET genrate/invoice/{orders.id}   GenrateInvoice::Invoice
  → INSERT invoice (prefix from radium_branch.rd_slug + rd_no+1)
  → increment radium_branch.rd_no
  → UPDATE orders.invoicecode, invoice_date, branch
  → UPDATE order_rdservice.is_requestinvoice = 2
  → if GSTIN and envoice=1:
        GET https://media.radiumbox.com/api/einvoice/{id}/{branch}/einvoice
        → UPDATE orders.einvoice_respose
```

Reprint (no new number): `GET print/invoice/{id}`. **VERIFIED.**

Paid fulfill on rdservice.net (`GenrateOrder`) does **not** set `invoicecode` / `invoice_date` / `einvoice_respose`. **VERIFIED.**

rdservice.net my-account `INS{n}` path is **not usable** on production (unrouted, models/views missing). **VERIFIED.**

WhiteBooks / `order_einvoices` / `invoice_sequences` exist in RDService.net git WIP, **absent** on production filesystem and `rdservice_net_prod`. **VERIFIED.**

### 3.2 Branch series (Admin)

From `radiumbox_prod` branch snapshot (P-01-09-06). Sequence **values** are intentionally not copied here.

| slug | name | GSTIN | state | invoice_slug | rd_slug | Class |
|------|------|-------|-------|--------------|---------|-------|
| radium_delhi | Radium - Delhi | 07AAICP1128M1Z9 | Delhi | IND67 | INV67 | VERIFIED |
| radium_mumbai | Radium - Mumbai | 27AAICP1128M1Z7 | Maharashtra | INM67 | NULL | VERIFIED |
| radium_up | Radium - UP | 09AAICP1128M1Z5 | Uttar Pradesh | INU67 | NULL | VERIFIED |
| radium_bihar | Radium - Bihar | 07AAICP1128M1Z9 | Bihar | NULL | NULL | VERIFIED value; GSTIN correctness **UNKNOWN** (copy of Delhi) |
| radium_chennai | Radium -Chennai | 33AAICP1128M1ZE | Tamil Nadu | INT67 | NULL | VERIFIED |
| delhi_wharehouse | Radium-Delhi-Govindpuri | empty | Delhi | NULL | NULL | VERIFIED |

Same PAN fragment `AAICP1128M` across GSTINs supports **one legal entity, multiple state GSTINs**. Legal name of the entity is **UNKNOWN** (not stored in Desk).

### 3.3 Observed invoice prefixes (shared ERP copy)

On `rdservice_net_prod` Paid `invoicecode` (**VERIFIED** P-31-08-14): INS 146,304; INV 96,646; IND 13,381; INM 491. Last invoice on **that** DB: `INV6796998` (2026-08-30). Whether Admin production continues numbering on `radiumbox_prod` vs this DB is **UNKNOWN**.

Meaning of `67` inside `INV67` / `IND67` is **UNKNOWN** (not documented as FY, company code, or frozen prefix).

Two Admin counters exist on `radium_branch`: `invoice_slug`/`invoice_no` (goods/POS inferred) and `rd_slug`/`rd_no` (RD invoices). **VERIFIED** columns; which document type uses which counter is **INFERRED** from names + GenrateInvoice using `rd_slug`.

### 3.4 Admin GST / invoice content (from POS field matrix)

| Topic | Admin behaviour | Class |
|-------|-----------------|-------|
| Price | Exclusive `selling_price` + line GST% | VERIFIED (Desk matches when shipping=0) |
| Header discount | After line tax; does not reduce GST | VERIFIED |
| Shipping | 18% reverse GST | VERIFIED Admin; **not** cloned in Desk |
| TCS | Present on Admin invoice path | VERIFIED as gap; do not invent |
| HSN | `order_details.hsn_code` from model | VERIFIED |
| Place of supply | `orders.state` / `district` | VERIFIED columns |
| Customer GSTIN | `orders.gst_no` / address GST | VERIFIED |
| E-invoice | media.radiumbox.com when GSTIN + `envoice=1` | VERIFIED trigger; GSP internals **UNKNOWN** |
| Credit note | Admin `credit_note.*`; restock often manual | VERIFIED as Admin path; Desk has no table |
| IRN JSON helper | Builds NIC 1.1 JSON; does **not** POST to NIC in that method | VERIFIED |

Admin invoice concurrency (whether `rd_no` increment is row-locked) was **not** re-read this ticket. **UNKNOWN.** Duplicate-number history on production is **UNKNOWN.**

---

## 4. Target architecture

```
Channels (POS, rdservice.in, radiumbox.com, rdservice.net, radiumsign.com, future)
        │  HTTPS API / signed events — no shared database
        ▼
Desk commerce order  (channel-neutral; idempotent)
        │
        ├─► fulfillment (POS stock / support case / dispatch)   operational
        ├─► payment record + allocation                         financial
        ▼
Desk statutory invoice engine  (only issuer of GST invoice numbers)
        │
        ├─► e-invoice adapter (IRN / QR / cancel)               pluggable GSP
        ├─► credit note / debit note                            statutory
        ▼
Desk finance ledger  (existing journals; new tax lines)
        ▼
Accountant / CA portal  (restricted role, month-end export)
```

**Non-negotiables**

1. One statutory invoice engine, in Desk.
2. Channels never allocate GST invoice numbers.
3. No direct DB coupling to Admin, rdservice_net_prod, or radiumbox_prod **for operational writes, invoicing, or live enrichment**. A scoped read-only exception exists for the RadiumBox Read API (`radiumbox.read`, named connection `radiumbox_read`, default OFF): SELECT-only access to the KVM8 replica through Desk’s application boundary. It is not a merge into `radium_desk` and must not replace `RadiumBoxClient`. See [desk-radiumbox-read-api.md](desk-radiumbox-read-api.md).
4. POS `INV-*` remains internal and unique in `inventory_sales`.
5. Support `orders` remains the service case; it may **reference** a commerce order and a statutory invoice.
6. Cancelled statutory numbers remain auditable; they are not recycled.
7. Retries use idempotency keys; they must not consume extra invoice numbers after a successful allocate.

---

## 5. Order-source architecture

Prefer API/event integration. Desk is the writer of commerce orders and invoices. Channels are clients.

Shared contract for every source:

| Concern | Target rule |
|---------|-------------|
| Order ownership | Desk owns the commerce order after accept. Channel owns the channel-side cart/receipt. |
| Integration | HTTPS JSON API (push) + optional webhook callback. Idempotent `PUT`/`POST` with `Idempotency-Key`. No shared MySQL. |
| Authentication | Per-channel HMAC or bearer token in Desk config (same pattern as `DESK_ORDER_API_TOKEN`, never logged). mTLS **UNKNOWN** / not required for v1. |
| Desk order ID | Desk-generated `commerce_orders.id` / `order_no` (e.g. `CO-…`). |
| Source order ID | Channel’s stable id (`rdorderid`, storefront order code, POS `sale_no`). Unique per `(channel, source_order_id)`. |
| Payment ID | Provider id (Cashfree `cf_payment_id`, UPI ref, cash drawer slip). Unique where the provider guarantees uniqueness. |
| Customer | Party snapshot on the order (name, phone, email, GSTIN, billing address, state). Desk may later attach a party master; snapshot remains immutable on the order. |
| Tax information | Lines: HSN/SAC, taxable value, GST rate, tax amount. Header: place of supply, customer GSTIN, seller GSTIN, discount treatment. |
| Fulfillment | Channel-specific: POS serials, RD service activation, ecom dispatch. Not required to mint the invoice if the sale is complete and paid, unless CA says invoice-on-dispatch. **UNKNOWN** for goods vs service. |
| Invoice eligibility | Paid (or explicitly credit/B2B), tax-complete, seller GSTIN present, place of supply present, lines with HSN/SAC. B2B requires customer GSTIN. |
| Invoice creation | Desk job after eligibility. Never the channel. |
| Failure/retry | Outbox + job with backoff. Payment already captured must not be lost. Invoice allocate is a separate idempotent step. |
| Idempotency | `channel + source_order_id` for order upsert; `channel + payment_id` for payment; `commerce_order_id` (or explicit `invoice_request_key`) for invoice mint. |

### 5.1 Desk POS

| Field | Design |
|-------|--------|
| Source | `desk_pos` |
| Order ownership | Desk (`inventory_sales` is the operational sale; a commerce order is projected from it) |
| Integration | In-process event `InventorySaleCompleted` → commerce projection (not HTTP) |
| Authentication | Existing Desk session + `pos.sell` |
| Order ID | New `commerce_orders.order_no`; `source_order_id` = `inventory_sales.sale_no` |
| Source order ID | `POS-######` / sale id |
| Payment ID | POS `payment_reference` (optional today) |
| Customer | `inventory_customers` snapshot; GSTIN/address must be collected before statutory invoice |
| Tax | Existing exclusive + line GST; evolve to CGST/SGST/IGST using branch state vs customer state |
| Fulfillment | Stock already deducted in the POS transaction |
| Invoice eligibility | Not automatic on complete. Internal `INV-*` prints immediately. Statutory invoice when tax party data is complete (or a defined B2C default). |
| Invoice creation | Desk engine; link `inventory_sales.statutory_invoice_id` (future column — not migrated here) |
| Failure/retry | POS complete already fail-closed for stock+internal invoice+GL. Statutory mint is async and must not roll back stock. |
| Idempotency | Existing sale `idempotency_key` plus `invoice_request_key = pos_sale:{sale_id}` |

### 5.2 rdservice.in

| Field | Design |
|-------|--------|
| Source | `rdservice_in` |
| Order ownership | Channel until paid; Desk after accept |
| Integration | Channel **pushes** a paid (or invoice-requested) order to Desk commerce API. Do **not** give rdservice.in a statutory engine. Do **not** attach Desk to `rdservice_net_prod` / in-DB. |
| Authentication | Channel service token |
| IDs | `source_order_id` = RD `rdorderid` / `ordercode`; payment id = gateway payment id when present |
| Customer / tax / fulfillment | Must be in the payload (today Desk enrichment does **not** store lines/tax/address) |
| Invoice eligibility | Desk rules; historical Admin invoices stay in Admin until a cutover |
| Invoice creation | Desk only, after cutover for **new** orders |
| Failure/retry | Channel retries POST with same idempotency key; Desk returns the existing commerce order |
| Idempotency | `(rdservice_in, rdorderid)` |

**UNKNOWN:** whether in.live checkout and Admin still share one ERP DB; cutover date; who stops calling Admin `genrate/invoice`.

### 5.3 radiumbox.com

| Field | Design |
|-------|--------|
| Source | `radiumbox_com` |
| Order ownership | Storefront cart until paid; Desk after accept |
| Integration | Push paid order + lines + tax + shipping + customer GSTIN. No storefront-issued GST number. |
| Authentication | Channel token |
| IDs | Storefront order code + payment id |
| Tax | Preserve verified exclusive+GST behaviour; shipping tax treatment is a CA decision (Admin used 18% reverse GST — do not copy blindly) |
| Fulfillment | Dispatch remains storefront/Admin until a later phase; invoice may wait on dispatch **UNKNOWN** |
| Invoice creation | Desk |
| Idempotency | `(radiumbox_com, storefront_order_id)` |

### 5.4 rdservice.net

| Field | Design |
|-------|--------|
| Source | `rdservice_net` |
| Order ownership | Today: Cashfree webhook already creates a Desk **support** `orders` row (**VERIFIED**). Target: that payment also upserts a **commerce** order. |
| Integration | Prefer extending the existing Cashfree → Desk path (invoice-flow investigation Option C) plus RDService GET for tax/party snapshot. Do **not** add a second payment webhook. Channel may later POST a full commerce payload; until then Desk may assemble from Cashfree + RDService API. |
| Authentication | Existing Cashfree signature + `DESK_ORDER_API_TOKEN` for RDService pull |
| Order ID | Desk commerce `order_no`; support `orders.order_id` remains Cashfree/RD id |
| Source order ID | `rdorderid` (may include `T` + hex suffix — exact string match **VERIFIED**) |
| Payment ID | `cashfree_payment_id` (unique on support orders) |
| Customer / tax | Must start **storing** address, lines, HSN, GST split — currently **not stored** (**VERIFIED**) |
| Fulfillment | Support case / RD activation (existing) |
| Invoice eligibility | Paid + tax snapshot complete; B2B if GSTIN present |
| Invoice creation | Desk; never Admin `genrate/invoice` after cutover |
| Failure/retry | Existing enrichment job retries; invoice job separate, unique per commerce order |
| Idempotency | Cashfree payment id + `(rdservice_net, rdorderid)` |

Live Desk RDService enrichment is **OFF**; live rdservice.net has **no** Desk order API route (**VERIFIED** P-31-08-14). Activation is a later, separate ticket.

### 5.5 radiumsign.com

Admin `orders.ordertype=radiumsign` had **24** rows in the `radiumbox_prod` snapshot (**VERIFIED** count). Checkout, payment, and invoice path were **not** inspected. **UNKNOWN** — treat as a future channel with the same API contract; do not invent a special engine.

### 5.6 Future channels

Same commerce API. New `channel_code`. Same numbering service. No new invoice engine.

---

## 6. Central invoice architecture

### 6.1 Documents

| Document | Role | Numbered by |
|----------|------|-------------|
| POS internal invoice | Operational receipt / stock proof | Existing `INV-{branch}-{year}-{seq}` |
| Commerce order | Channel-neutral sale | Desk `CO-…` (not a GST number) |
| Statutory tax invoice | GST invoice | Desk numbering service only |
| Credit note | GST credit note (cancel/return/discount) | Separate series (**CA**) |
| Debit note | GST debit note | Separate series (**CA**) |
| E-invoice record | IRN, signed QR, ack, cancel | NIC via adapter; does not replace invoice number |

### 6.2 Statutory invoice entity (conceptual)

Seller: one legal entity; **seller GSTIN** from the supplying location (state registration).  
Buyer: snapshot (name, GSTIN or B2C, billing address, state).  
Place of supply: mandatory.  
Lines: description, HSN/SAC, qty, unit, taxable value, GST rate, CGST, SGST, IGST, cess if any (**cess UNKNOWN** / not in Desk today).  
Totals: taxable, tax, rounding, invoice value.  
Links: `commerce_order_id`, `source_channel`, `source_order_id`, payment allocations, optional `inventory_sale_id`, optional support `orders.id`.  
PDF: generated from Desk data only.  
Status: `draft` (unnumbered) → `issued` (number allocated) → `cancelled` (number kept) / `credited`.

**Draft invoices must not consume a number.** Allocate only on issue, in the same DB transaction as the insert of the issued row.

### 6.3 POS `INV-*` → statutory invoice

```
POS complete
  → internal INV-* printed (unchanged)
  → GL pos_sale journal (unchanged Day-1)
  → (later) commerce order projection
  → statutory invoice when eligible
        inventory_sales.internal_invoice_number = INV-…
        inventory_sales.statutory_invoice_id    = GST invoice
```

Rules:

- Never rename or recycle `INV-*`.
- Never print `INV-*` as a GST tax invoice (page already says it is not).
- Statutory PDF shows only the statutory number.
- If a POS sale is cancelled before statutory issue: reverse GL (already), do not mint a GST invoice, do not mint a credit note.
- If cancelled after statutory issue: GST credit note + IRN credit/cancel per adapter; keep both numbers; reverse or offset GL.

---

## 7. Invoice numbering

### 7.1 Current mechanisms

| System | Mechanism | FY? | Concurrency | Cancel | Gaps / dup risk | Class |
|--------|-----------|-----|-------------|--------|-----------------|-------|
| Desk POS | `INV-{branchCode}-{calendarYear}-{5-digit seq}`; seq on `inventory_branches` row locked in the sale txn | Calendar year in string; seq **does not reset** on 1 Jan (**VERIFIED** code) | Branch `lockForUpdate` serializes completes at one branch | Number **kept** | Unique index prevents dups; gaps if a txn rolls back after increment — increment is inside the same txn as the sale, so rollback restores seq (**VERIFIED** InnoDB sale txn). | VERIFIED |
| Admin RD | `rd_slug` + `rd_no+1` (e.g. `INV67` + n → `INV6796998`) | `67` meaning **UNKNOWN** | **UNKNOWN** | **UNKNOWN** (credit note path exists) | Copying into Desk would desync live Admin | VERIFIED prefix; UNKNOWN safety |
| Admin goods | `invoice_slug` / `invoice_no` (`IND67`, `INM67`, …) | UNKNOWN | UNKNOWN | UNKNOWN | Same | VERIFIED columns |
| rdservice.in my-account | `INS{n}` intended; production path broken | UNKNOWN | N/A | N/A | Not usable | VERIFIED |
| Support Desk | Copies Admin `invoicecode` | N/A | N/A | N/A | Not an issuer | VERIFIED |

**Do not** copy Admin `rd_no` / `invoice_no` onto Desk branches. Already documented as a live-commercial desync risk.

### 7.2 Target numbering service (format withheld)

One Desk service: `StatutoryInvoiceNumberingService`.

Conceptual table `invoice_sequences`:

| Key | Purpose |
|-----|---------|
| `legal_entity_id` | Always the single entity |
| `gstin` | Supplying GSTIN (CA must confirm whether series is per GSTIN) |
| `document_type` | `tax_invoice` \| `credit_note` \| `debit_note` |
| `series_code` | CA-approved prefix **UNKNOWN** |
| `financial_year` | India FY `YYYY-YYYY` if required — **UNKNOWN** whether reset is mandatory for the chosen series |

`current_value` incremented only inside `SELECT … FOR UPDATE` on that **existing** sequence row (create the row at series setup, never gap-lock a missing unique key — lesson from POS P-01-09-09).

Companion append-only `invoice_sequence_allocations`:

| Column | Purpose |
|--------|---------|
| `sequence_id` | FK |
| `allocated_number` | Full statutory number string |
| `seq_int` | Integer issued |
| `invoice_id` | FK once issued |
| `idempotency_key` | Unique; retries return the same allocation |
| `allocated_at`, `allocated_by` | Audit |
| **no updates/deletes** | Immutable history |

**Rules**

- Unique `(series_code, allocated_number)` globally (and/or per GSTIN if CA requires).
- Unique `idempotency_key` so retries do not consume a second number.
- Cancelled invoices keep `allocated_number`; status = cancelled.
- Channels never call increment.
- POS internal `INV-*` is a **different namespace** and must never collide with statutory numbers (different prefix by design; CA must not approve `INV-{branch}-…` as the GST series if it would clash with Admin `INV67…` or POS internals).
- Issue and allocate in one transaction. If IRN submission fails after issue, **do not** allocate another GST number; retry IRN against the same invoice.

### 7.3 What is not chosen

The following are **UNKNOWN** and are **business decisions** (see §18). This investigation **stops short of a legal series format** as required:

- Continue Admin prefixes (`INV67`, `IND67`, `INS…`) vs start a new FY series in Desk.
- Per-GSTIN series vs one pan-India series.
- Financial-year reset vs continuous.
- Credit-note prefix.
- Whether POS retail and online RD may share one series (GST often allows one series per GSTIN; operational preference is still a CA call).
- Legal name, registered address, invoice signature block.

Until those are confirmed, **do not implement** the numbering service against production and **do not** change existing production invoice numbering.

---

## 8. GST architecture

### 8.1 Compare

| Topic | Admin (verified) | Desk POS (verified) | Target |
|-------|------------------|---------------------|--------|
| Inclusive/exclusive | Exclusive + GST% | Exclusive + GST% | Keep exclusive unless CA says otherwise |
| Line tax | `qty × price × gst% / 100` | Same; header discount does **not** reduce tax | Keep; add CGST/SGST/IGST split |
| Line discount | Not the Admin POS core | Optional, **before** tax | Allow; tax on (qty×price − line discount) |
| Header discount | After line nets (incl. tax) | After line tax | Keep Admin-matching behaviour; statutory print must show taxable vs discount per CA |
| Shipping | 18% reverse GST | None | **UNKNOWN** — do not clone blindly |
| CGST/SGST/IGST | Branch state vs supply state (inferred from invoice JSON) | Single `tax` | Split using seller GSTIN state vs place of supply |
| Place of supply | `orders.state` | Missing | Mandatory on statutory invoice |
| HSN/SAC | Line HSN | Product HSN, **not printed** | Mandatory on lines; SAC for services |
| Seller GSTIN | `radium_branch.gst_no` | Branch `gstin` optional | Mandatory; Bihar copy of Delhi is a data defect |
| Buyer GSTIN | Order/address | Column unused on counter | B2B required; B2C empty |
| Rounding | UNKNOWN | `round(…, 2)` | **UNKNOWN** — CA (invoice rounding vs tax rounding) |
| B2B | GSTIN present → e-invoice flag | Not classified | `supply_type` B2B/B2C |
| B2C | No GSTIN; IRN usually skipped | Default POS | B2C invoice still statutory; IRN per threshold **UNKNOWN** |
| TCS | Admin extra | None | Do not invent |
| Cess | Not in Desk | None | UNKNOWN |

Regression already in Desk tests: ₹100 + 18% − ₹10 header = **₹108**, tax still **₹18**. Do not “fix” that without CA + statutory print rules.

### 8.2 Place of supply (target)

- Goods: typically delivery state (**UNKNOWN** confirm).
- RD service: typically recipient location / recorded state (**UNKNOWN** confirm).
- POS walk-in: typically branch state unless customer GSTIN/state collected.

IGST if seller GSTIN state ≠ place of supply; else CGST+SGST. **INFERRED** from standard GST; CA must confirm for inter-state services and OIDAR-like digital RD. Marked **UNKNOWN** for digital services.

---

## 9. IRN / e-invoice architecture

### 9.1 What is known about Admin

| Topic | Finding | Class |
|-------|---------|-------|
| Trigger | GSTIN present and `envoice=1` | VERIFIED |
| Transport | `GET https://media.radiumbox.com/api/einvoice/{id}/{branch}/einvoice` | VERIFIED |
| Storage | `orders.einvoice_respose` (typo in column name) | VERIFIED |
| NIC JSON | Admin helper builds NIC 1.1; does not POST to NIC itself | VERIFIED |
| QR | Implied by IRN payload; Desk does not store QR | INFERRED |
| E-way | Admin `POST ewaybill/{id}`; 0 e-way on rdservice.net Paid rows | VERIFIED |
| GSP / provider | Behind media.radiumbox.com | UNKNOWN |
| Auth to GSP | UNKNOWN (not in Desk) | UNKNOWN |
| Retries / idempotency | UNKNOWN | UNKNOWN |
| IRN cancel | UNKNOWN | UNKNOWN |
| WhiteBooks | Git only; not production | VERIFIED |

`media.radiumbox.com` is **not** automatically the Desk target. It is one existing adapter implementation.

### 9.2 Desk adapter (target)

```
StatutoryInvoiceIssued
    → EInvoiceGateway::submit(InvoicePayload)
         implementations: NicGspAdapter, MediaRadiumboxLegacyAdapter, Fake/SandboxAdapter
    → e_invoice_records (irn, ack_no, ack_date, signed_qr, raw_request, raw_response, status)
```

| Concern | Design |
|---------|--------|
| Payload | Built from Desk invoice (NIC schema version in adapter, not in domain) |
| Idempotency | One `e_invoice_records` row per invoice; unique `invoice_id`; retry sends same IRN-request hash |
| Failure | Invoice remains `issued` without IRN; ops queue; **do not** re-number |
| Cancel | Adapter `cancel(irn, reason)` then invoice status cancelled/credited |
| QR | Store signed QR from GSP; render on PDF |
| E-way | Separate adapter method; only when goods movement requires it (**UNKNOWN** which SKUs) |
| Secrets | GSP credentials in Desk env; never in invoices; accountant role cannot read them |

Domain tables do not contain provider-specific columns except on `e_invoice_records.provider`.

---

## 10. Finance architecture

Preserve existing GL. Do not double-count revenue.

### 10.1 Today

| Event | Journal | Idempotency | Class |
|-------|---------|-------------|-------|
| Cashfree paid support order | Dr 1100 / Cr 4000 `order_payment:{order_id}` | Unique key | VERIFIED |
| POS sale | Dr 1000 or 1100 / Cr 4000 `pos_sale:{sale_id}` | Unique key; fail-closed | VERIFIED |
| POS cancel/return | Reverse of original `pos_sale:reverse:{sale}:{journal}` | Unique key; fail-closed | VERIFIED |
| Refund complete | Dr 5100 / Cr 1100 `refund:{id}` (always bank clearing) | Unique key; **soft-fail** listener historically | VERIFIED |
| Cash Book | Dr/Cr cash vs income/expense; reverse on edit/delete | `cashbook:{id}` / `cashbook:reverse:{id}:{journal}` | VERIFIED |

No GST payable accounts. Listener for order payment can skip if accounts missing (unlike POS fail-closed). Cutover gate can skip journals.

### 10.2 Target effects

| Event | Revenue | Tax liability | Cash/bank/UPI | AR | Class of rule |
|-------|---------|---------------|---------------|----|----------------|
| Paid POS / channel order | Recognize on **invoice issue** *or* keep on collection until CA chooses | On invoice (CGST/SGST/IGST payable) | Dr cash / bank clearing / UPI clearing on collection | Only if unpaid B2B | **UNKNOWN** recognition timing — see §18 |
| Day-1 (now) | On collection/sale (gross, tax in 4000) | None | Cash vs bank clearing by method | None | VERIFIED |
| Refund | Offset revenue or Dr refunds | Credit note reduces tax | Cr cash/bank/UPI as actually paid | Reduce AR | Preserve method-aware clearing (today ignored) |
| Cancel before invoice | Reverse sale/payment journal (existing pattern) | None | Reverse collection | n/a | VERIFIED pattern |
| Cancel after invoice | Credit note + reverse/offset invoice journal | Reverse tax | Refund journal if money returned | Reverse AR | Target |
| UPI | Distinct clearing account (new) | — | Not “cash” | — | INFERRED from POS method string |

**Critical:** when statutory invoices start posting tax, **stop crediting 4000 for the gross including GST** on the same sale, or tax will be income. Migration of existing `order_payment` / `pos_sale` journals is a cutover design, not a rewrite of history (journals stay append-only).

Suggested target invoice journal (illustrative, not implemented):

- Dr Cash/Bank/UPI/AR (invoice value)
- Cr Revenue (taxable)
- Cr CGST/SGST/IGST payable (tax)
- Rounding to a rounding account if CA requires

Credit note: reverse of that, Cash Book-style (original kept).

### 10.3 Payment methods

Map POS/Cashfree methods to GL:

| Method | Account |
|--------|---------|
| Cash | 1000 Cash on Hand |
| UPI | New UPI clearing (**not in COA today**) |
| Card / Cashfree / Net banking | 1100 Bank clearing |
| Bank transfer | 1100 or specific bank GL |
| Wallet | External RadiumBox wallet — **not** Desk (**VERIFIED**). Do not post as cash. |

---

## 11. Cancellation / refund / credit-note architecture

| Situation | Stock | Internal POS INV | Statutory invoice | GL | Customer money |
|-----------|-------|------------------|-------------------|----|----------------|
| POS cancel before statutory issue | Restore | Keep number; status cancelled | Do not issue | Reverse `pos_sale` | Cash refund ops; not GST CN |
| POS return after statutory issue | Restore | Keep | Credit note (full or partial) | Reverse invoice journal + refund if paid out | Per method |
| Channel cancel before invoice | n/a | n/a | Do not issue | Reverse `order_payment` if posted | Gateway refund |
| Channel cancel after invoice | per SKU | n/a | Credit note; IRN credit/cancel | As above | Refund workflow (`refund_requests`) |
| Partial return | Future POS gap | Keep original | Credit note for returned lines only | Partial reverse | Partial refund |

Credit note document:

- Own number from numbering service (`document_type=credit_note`)
- References original invoice id + number
- Lines subset or full
- Idempotency `credit_note:{invoice_id}:{reason_hash}` or explicit request key
- Not the same as `finance_journals` reverse (both exist: statutory CN + GL reverse)

Debit note: same engine, separate series, rare (price undercharge). **UNKNOWN** whether the business uses debit notes today (Admin table not inspected this ticket).

Do **not** treat Cash Book / POS reversing journals as GST credit notes. That distinction is already documented and must stay.

---

## 12. Accountant / CA role

New Spatie role `accountant` (name **INFERRED**; owner may prefer `ca` / `finance_readonly`).

### 12.1 Must receive

| Permission (proposed) | Purpose |
|-----------------------|---------|
| `finance.accountant.access` | Module gate |
| `finance.invoices.view` | Statutory invoices, CN/DN, cancelled |
| `finance.gst.reports` | GST / HSN / B2B / B2C summaries |
| `finance.reports.export` | CSV / XLSX / PDF |
| `finance.journals.view` | Read GL (already close to `finance.settings.journals`) |
| `finance.cash.view` / `finance.bank.view` | Read ledgers |
| `finance.payments.view` | Read collections |
| `finance.reconcile.view` | Payment vs invoice vs gateway (read) |

Login: normal Desk user, MFA **UNKNOWN** / recommended. No POS, no inventory, no user admin.

### 12.2 Must not receive

| Capability | Existing perm to withhold |
|------------|---------------------------|
| POS operational controls | `pos.view`, `pos.sell`, `pos.cancel` |
| Inventory mutation | `inventory.*` except none |
| Branch configuration | `inventory.branches.manage`, `operate-all` |
| User administration | `users.manage` |
| Payment credentials / GSP secrets | env + `system-settings.manage` |
| Infrastructure | `backups.view` optional withhold; no SSH |
| Cash Book create/manage | `cashbook.create`, `cashbook.manage` |
| Refund execute | `refunds.execute` |
| Finance settings write | today’s `finance.settings.view` currently authorizes mutations (**VERIFIED** gap) — accountant must be read-only |

Admin-team users who already have `finance.view` keep ops finance; they are **not** the CA portal. Optionally hide POS/inventory from a CA-only home (navigation filter by role).

No dedicated accountant role exists today (**VERIFIED** `RolePermissionSeeder`).

---

## 13. Month-end reporting

### 13.1 Flow (example: September 2026)

```
1  All channels send/accept September commerce orders into Desk
2  Desk issues statutory invoices (and credit notes) in September tax period
3  Payments allocated to invoices; Cashfree recon for gateway ids
4  GST tax lines on issued invoices = tax records
5  Period lock (optional later): refuse new backdated September invoices
6  Accountant logs into Desk (restricted)
7  Runs period = 2026-09-01..2026-09-30 IST (timezone **UNKNOWN** confirm)
8  Exports: invoice register, B2B, B2C, HSN, GST summary, CN/DN, cancelled,
   payments, cash, bank, UPI, sales by branch/channel/SKU, refunds,
   outstanding (unpaid/uninvoiced), month-end summary
9  Files GST in government portal from those exports (Desk does not file)
```

The accountant should **not** query rdservice.in, radiumbox.com, Admin, or `rdservice_net_prod` for September 2026 if cutover is complete for that period. Pre-cutover history remains in Admin/ERP until imported as **read-only historical invoices** (optional phase).

### 13.2 Report list

| Report | Source of truth |
|--------|-----------------|
| Invoice register | `statutory_invoices` issued + cancelled |
| B2B | Buyer GSTIN present |
| B2C | Buyer GSTIN empty |
| GST summary | Sum CGST/SGST/IGST by rate and GSTIN |
| HSN/SAC summary | Invoice lines |
| Credit notes / debit notes | Those documents |
| Cancelled invoices | Status cancelled (number kept) |
| Payment reconciliation | Payments vs allocations vs Cashfree integrity (existing service) |
| Cash / bank / UPI | Journal lines on those accounts |
| Sales by branch | Invoice seller location / POS branch |
| Sales by channel | `source_channel` |
| Sales by product/service | Lines (SKU / SAC) |
| Refunds | `refund_requests` + refund journals + CN |
| Outstanding orders | Commerce orders paid-uninvoiced or invoiced-unpaid |
| Month-end summary | Totals + counts + tax |
| Export | CSV + XLSX + PDF (**PDF layout UNKNOWN** / CA) |

Finance dashboard/P&L/TB remain useful internally but are **not** GST returns.

---

## 14. Audit trail

Every financial document must store this chain explicitly (no “look it up on another website”):

```
source_channel
  → source_order_id
    → desk commerce order_no / id
      → payment_id(s)
        → statutory invoice number + invoice id
          → IRN (if any)
            → credit_note number + id (if any)
              → refund_request id (if any)
                → finance_journal_id(s)
```

Implementation:

- Columns on commerce order, payment, invoice, CN, `e_invoice_records`.
- `audit_logs` morph events: `invoice.issued`, `invoice.cancelled`, `number.allocated`, `einvoice.submitted`, `einvoice.failed`.
- Journals already have `source_type` + `source_id` + `idempotency_key`.
- POS: `inventory_sales.id` + `invoice_number` (internal) + `statutory_invoice_id`.
- Support: `orders.id` + `orders.order_id` as correlation, not as invoice issuer.

Opaque relationships (Admin `invoicecode` copied onto Desk with no invoice row) are **forbidden** in the target.

---

## 15. Database / domain model

**No migrations in this ticket.** Conceptual only. Prefer linking existing tables over cloning them.

### 15.1 Existing tables to keep

| Table | Role in target | Do not |
|-------|----------------|--------|
| `orders` | Support/service case; add nullable FKs later | Use as GST invoice |
| `inventory_sales` / `_lines` | POS operational sale + internal invoice | Treat `invoice_number` as GST |
| `inventory_customers` | POS party; too thin for all channels | Sole party master |
| `inventory_branches` | Location + seller GSTIN | Use `invoice_sequence` for GST |
| `inventory_products` | HSN + GST% | |
| `finance_journals` / `_lines` / `_accounts` | GL | Mutate posted rows |
| `finance_payment_methods` | Method list | |
| `refund_requests` | Money-out workflow | GST CN |
| `cash_book_entries` | Ops cash | Statutory sales |
| `cashfree_webhook_logs` | Gateway audit | |
| `audit_logs` | Generic audit | Replace invoice allocation history |

### 15.2 Proposed new tables

#### `commerce_orders`

| Field | SoT | Notes |
|-------|-----|-------|
| `id`, `order_no` unique | Desk | Desk order ID |
| `channel` + `source_order_id` unique | Channel | Idempotency of upsert |
| `status` | Desk | pending / paid / invoiced / cancelled / fulfilled |
| `customer_*` snapshot | Channel payload | Immutable after invoice |
| `place_of_supply_state` | Channel / POS | Required before invoice |
| `seller_gstin`, `buyer_gstin` | Desk rules + payload | |
| `inventory_sale_id` nullable unique | POS | |
| `support_order_id` nullable | Support `orders.id` | |
| `idempotency_key` unique | Channel header | |
| amounts | Computed from lines | |

#### `commerce_order_items`

Lines: SKU/service code, description, HSN/SAC, qty, unit price exclusive, discount, gst_rate, tax, line_total. SoT = payload at accept. No silent rewrite after invoice.

#### `commerce_payments`

| Field | SoT |
|-------|-----|
| `provider`, `payment_id` unique where not null | Cashfree / UPI / cash ref |
| `amount`, `method`, `paid_at` | Provider or POS |
| `commerce_order_id` | Desk |
| Do not duplicate Cashfree columns on `orders` as the long-term SoT; support order may keep its copy for service ops. |

#### `payment_allocations`

`payment_id` + `invoice_id` + amount. Unique `(payment_id, invoice_id)`. Splits and partial invoices. Idempotency: unique allocation key.

#### `statutory_invoices`

| Field | SoT / constraint |
|-------|------------------|
| `id` | Desk |
| `invoice_number` unique | Numbering service |
| `sequence_allocation_id` unique | Immutable link |
| `document_type` | tax_invoice |
| `status` | draft / issued / cancelled |
| `commerce_order_id` | Required for channel/POS |
| `seller_legal_name`, `seller_gstin`, `seller_address` | Snapshot at issue |
| `buyer_*`, `billing_address`, `place_of_supply` | Snapshot |
| `taxable_value`, `cgst`, `sgst`, `igst`, `rounding`, `invoice_value` | From lines |
| `pdf_path` | Desk storage (private disk) |
| `issued_at`, `issued_by` | Audit |
| `idempotency_key` unique | Mint key |

Draft rows have `invoice_number` null. Unique number only when issued.

#### `statutory_invoice_items`

Mirrors commerce lines plus tax split per line. Frozen at issue.

#### `invoice_sequences` / `invoice_sequence_allocations`

See §7. Allocations are append-only. Unique `idempotency_key`. Unique `allocated_number`.

#### `credit_notes` / `credit_note_items`

Same pattern as invoices. `original_invoice_id` required. Own sequence.

#### `debit_notes` / `debit_note_items`

Same. Optional until CA confirms use.

#### `e_invoice_records`

`invoice_id` unique, `provider`, `irn` unique nullable, `ack_no`, `ack_date`, `signed_qr`, request/response JSON, `status`, `cancel_irn_at`. Idempotent submit.

#### Finance journals

No new table. New `source_type` values: `statutory_invoice`, `credit_note`, `debit_note` (string column already; enum update in code later). Idempotency `statutory_invoice:{id}`, `credit_note:{id}`.

#### `audit_logs`

Reuse. Do not create a parallel `audit_events` unless payload volume requires it.

### 15.3 Unique / idempotency map

| Key | Prevents |
|-----|----------|
| `(channel, source_order_id)` | Duplicate commerce orders |
| `commerce_payments.payment_id` (provider-scoped) | Duplicate payments |
| `statutory_invoices.idempotency_key` | Double mint / double number |
| `statutory_invoices.invoice_number` | Duplicate GST numbers |
| `invoice_sequence_allocations.idempotency_key` | Retry consuming seq |
| `e_invoice_records.invoice_id` | Double IRN submit row |
| `inventory_sales.invoice_number` | Duplicate **internal** POS numbers (already) |
| `orders.cashfree_payment_id` | Duplicate support payments (already) |

---

## 16. Website integration contracts

Do **not** implement these APIs in this ticket. Modes A–E as requested.

| Channel | A complete paid order → Desk | B pending order in Desk | C Desk generates statutory invoice | D channel receives invoice/IRN back | E channel keeps only a receipt | Notes | Class |
|---------|------------------------------|-------------------------|------------------------------------|-------------------------------------|--------------------------------|-------|-------|
| Desk POS | n/a (already in Desk) | Cart is POS UI only | **Yes**, when eligible | Print statutory PDF in Desk | Internal `INV-*` is the counter receipt | Keep INV-* | VERIFIED ops / target C |
| rdservice.in | **Yes** after payment (preferred) | Optional if unpaid B2B proforma **UNKNOWN** | **Yes** | **Yes** (PDF + number + IRN) | Channel order confirmation / email | **No** local GST engine | Target; live Admin still issues **VERIFIED** |
| radiumbox.com | **Yes** for paid ecom | Wishlist/cart stay on site | **Yes** | **Yes** | Storefront order page | Invoice-on-dispatch **UNKNOWN** | Target |
| rdservice.net | **Yes** (Cashfree already proves paid in Desk) | No second pending factory from RDService (**VERIFIED** current design) | **Yes** | **Yes** | RD “Processing” page | Option C from P-30-08-01 | VERIFIED ingress; invoice not built |
| radiumsign.com | Assume A once path known | UNKNOWN | **Yes** | **Yes** | Receipt on site | 24 historical Admin rows | UNKNOWN live path |
| Future | A default | B only if CA wants proforma | Always C | D if the channel displays tax invoices | E always | Same API | — |

Callback (D): signed webhook or poll `GET /api/integrations/v1/invoices/{source_order_id}`. Channel stores Desk invoice number as **display copy**, not as an issuer.

Proforma / unpaid invoices: **UNKNOWN** (CA). If needed, they use a non-GST series or remain drafts.

---

## 17. Migration phases

Documentation / design is Phase 0. **No phase is executed here.**

| Phase | Work | Production? |
|-------|------|-------------|
| 0 | This architecture; CA decisions | No |
| 1 | Additive schema (commerce + statutory + sequences) on Desk **non-prod**; no live numbering | No prod migrate until approved |
| 2 | Numbering service behind feature flag; CA-approved series **only** after §18 | No |
| 3 | POS: collect GSTIN/address; print HSN; link internal INV to statutory; do not change INV format | Flagged |
| 4 | GST split + place of supply + tax GL accounts | Flagged |
| 5 | Accountant role + reports + exports | Flagged |
| 6 | rdservice.net commerce payload (Cashfree path + tax snapshot); callback D | After RDService API exists; still no Admin change required for Desk-side receive |
| 7 | E-invoice adapter (sandbox); then one GSP | No media.radiumbox.com hard-wire |
| 8 | rdservice.in + radiumbox.com push contracts | Other repos — **separate tickets**; this repo only the Desk API |
| 9 | Cutover: Admin stops numbering **new** orders; Desk is issuer | Owner + CA go-live |
| 10 | Optional read-only import of historical Admin invoices for CA completeness | No rewrite of Admin numbers |

Inventory/POS Day-1 work on `feat/rd-fresh-01-inventory-pos` is **untouched** by this phase plan except future additive FKs.

---

## 18. Business decisions required

| # | Decision | Why it blocks | Suggested owner |
|---|---------|---------------|-----------------|
| 1 | Legal name, registered office, invoice signatory | PDF / e-invoice SellerDtls | Owner + CA |
| 2 | Confirm all GSTINs (especially Bihar vs Delhi clone; warehouse empty) | Seller GSTIN on every invoice | Owner + CA |
| 3 | **Legal invoice series format** (prefix, per-GSTIN, FY reset, whether to continue `INV67`/`IND67`/`INS`) | Cannot mint statutory numbers | **CA — STOP** |
| 4 | Credit note / debit note series | GST CN | CA |
| 5 | Invoice on payment vs on dispatch vs on service activation | Eligibility | Owner + CA |
| 6 | Revenue recognition: on collection (today) vs on invoice | Avoid double income when tax GL starts | CA |
| 7 | Shipping tax (clone Admin 18% reverse GST or not) | radiumbox.com lines | CA |
| 8 | TCS applicability | B2B goods | CA |
| 9 | B2C e-invoice / QR threshold | IRN adapter rules | CA |
| 10 | Inter-state RD service place of supply | IGST vs CGST | CA |
| 11 | Header discount presentation on GST invoice | Taxable value | CA |
| 12 | Rounding rules | Invoice value | CA |
| 13 | Whether POS walk-in B2C uses branch state as POS | Place of supply | CA |
| 14 | Cutover date and who stops Admin `genrate/invoice` | Dual issuers = duplicate legal numbers | Owner |
| 15 | Historical Admin invoice import into Desk (read-only) | September 2026 close if mixed period | CA + owner |
| 16 | UPI vs bank clearing GL | Cash/UPI reports | CA |
| 17 | Wallet refunds remain external? | Refund GL | Owner (already external) |
| 18 | Accountant identity (who logs in), MFA | Role | Owner |
| 19 | radiumsign.com live billing path | Channel contract | Owner |
| 20 | GSP provider for Desk (keep media.radiumbox.com, replace, or WhiteBooks) | Adapter | Owner + CA |
| 21 | E-way bill SKU/rules | Goods movement | CA |
| 22 | Period lock / month-end freeze | Backdated invoices | CA |

---

## 19. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Two issuers (Admin + Desk) during sloppy cutover | Critical | One flag: Desk issues XOR Admin issues for new orders |
| Choosing a series that collides with Admin `INV…` or POS `INV-{branch}-` | Critical | CA series ≠ POS internal format; do not implement until decided |
| Copying Admin `rd_no` into Desk | Critical | Already forbidden |
| POS `INV-*` handed to customers as GST invoice | High | Keep disclaimer; statutory PDF only after engine exists |
| Double revenue (payment journal + invoice journal) | High | CA recognition policy; cutover mapping |
| Bihar GSTIN = Delhi GSTIN | High | Data fix before Bihar statutory invoices |
| Channel invents numbers anyway | High | Contract + monitoring for non-Desk prefixes on new orders |
| IRN retry allocates a new GST number | High | Number allocate once; IRN retries same invoice |
| InnoDB gap-lock on missing sequence row | High | Pre-create sequence rows; no `FOR UPDATE` on miss |
| Soft-fail order payment journals vs fail-closed POS | Medium | Align fail-closed when statutory finance is on |
| `orders.invoice_number` remains an Admin copy and confuses agents | Medium | After cutover, write only Desk statutory number as display copy |
| Other-repo work required (rdservice.in, radiumbox.com) | Medium | Desk API first; channel tickets later |
| Inventory branch work disturbed | Medium | This ticket is docs only |
| Legal assumptions (IGST for digital RD, e-invoice thresholds) | High | Left **UNKNOWN**; not implemented |

---

## 20. UNKNOWN items

| Item | Why unknown |
|------|-------------|
| Final legal invoice series string | CA / GST practice / existing prefix meaning of `67` |
| Admin `rd_no` increment locking / historical duplicates | Admin code not re-read for locking this ticket |
| Which production DB Admin currently increments | `radiumbox_prod` vs `rdservice_net_prod` (P-31-08-14) |
| Legal entity registered name | Not in Desk |
| Correct Bihar GSTIN | Admin value copies Delhi |
| GSP provider, credentials, retry/cancel IRN semantics | Behind media.radiumbox.com |
| Whether WhiteBooks is intended | Git only |
| E-invoice applicability for B2C / services | Legal |
| Place of supply for RD digital service | Legal |
| Invoice-on-dispatch vs on-payment for goods | Business |
| radiumsign.com live checkout/invoice | Not inspected |
| Shipping / TCS on future Desk invoices | Do not clone undocumented extras |
| Rounding and header-discount print rules | CA |
| Debit note usage | Admin table not inspected this ticket |
| Accountant MFA / user identity | Owner |
| IST vs financial-settings timezone for tax period | Confirm |
| FY start (assume 1 Apr only after CA) | Legal/CA |
| Whether multiple GSTINs require multiple series | CA |
| Production Desk `inventory_*` go-live date | Separate inventory programme |

---

## Safety record (P-01-09-14 investigation)

| Action | Done? |
|--------|-------|
| Implement statutory invoice engine | No (investigation only) |
| Create migrations | No |
| Create invoices | No |
| Change production numbering | No |
| Deploy / `deskd` / tag | No |
| Modify production / `radiumbox_prod` / Admin / rdservice.in / other websites | No |
| Modify inventory/POS PHP behaviour | No |
| Query production databases | No |
| Commit secrets | No |

---

## 21. P-01-09-15 implementation foundation (shipped)

Desk-only foundation. This is **not** statutory go-live. `TEST-{seq}` is a test-only format used in PHPUnit. Production minting **fails closed** until the CA sets `STATUTORY_INVOICE_SERIES_CODE` and `STATUTORY_INVOICE_NUMBER_FORMAT`.

### 21.1 Implemented boundaries

| Boundary | What shipped | What did not |
|----------|--------------|--------------|
| Domain | `StatutoryInvoiceService::mint` / `issueFromPosSale` / `cancel`. One engine for POS + channel source keys. | HTTP ingest from rdservice.in / radiumbox.com / radiumsign.com |
| Numbering | `StatutoryInvoiceNumberingService`: atomic `lockForUpdate`, pre-create sequence row, append-only `invoice_sequence_allocations`, idempotent allocate, unique `invoice_number`. Format tokens `{series}` `{seq}` `{seq:N}` `{gstin}` `{fy}`. | Legal prefix, FY reset, per-GSTIN series, credit-note format |
| POS vs GST | POS still allocates `INV-{branch}-{year}-{seq}`. Print copy says internal receipt. POS complete never mints. `INV-[A-Z0-9]+-\d{4}-\d{5}` is rejected as a statutory number. | Invoice-on-payment vs dispatch policy |
| Idempotency | Unique `(channel, source_type, source_id)` and `statutory:{channel}:{source_type}:{source_id}` | Second numbering mechanism |
| Finance | Existing `pos_sale` / Cashfree journals unchanged. `post_finance_journals` is hard-false; mint throws if enabled. No tax/revenue journal from mint. | GST payable GL, recognition on invoice |
| E-invoice | `EInvoiceGateway` + `NullEInvoiceGateway` (`provider = none`). Not called on mint. | GSP, IRN, QR, e-way |
| Accountant | Role `accountant` with `finance.accountant.access`, `finance.invoices.view`, `finance.gst.reports`, `finance.reports.sales`, `finance.reports.export`. GET-only invoice/report/export routes. | Cash/bank mutation, settings, POS, inventory, users |
| Reports | Register + CSV from `statutory_invoices` only. Columns: number, date, channel, customer, GSTIN, branch/seller GSTIN, HSN/SAC, taxable, CGST/SGST/IGST (blank unless provided), total, payment ref/mode, status, source id. Unclassified tax is not fabricated into CGST/SGST/IGST. | Historical Admin import, GSTR-1 filing file |

### 21.2 Channel integration contract (service API now)

Channels must not allocate GST numbers. Call `StatutoryInvoiceService::mint` with:

- `channel`: `desk_pos` \| `rdservice_in` \| `radiumbox_com` \| `rdservice_net` \| `radiumsign_com` \| `future`
- `source_type` + `source_id` (stable business key)
- lines including HSN/SAC when known
- CGST/SGST/IGST only when the caller already knows the split; otherwise leave null

HTTP channel ingest, commerce-order tables, and other-repo changes are **out of this ticket**.

### 21.3 Invoice idempotency model

1. Lookup by `(channel, source_type, source_id)`.
2. If found, return that invoice (no new number).
3. Else allocate by the same idempotency key inside a DB transaction.
4. Unique constraints recover races; duplicate mint returns the winner.
5. Outer transaction rollback also rolls back the allocation (savepoints / InnoDB).

### 21.4 Accountant / CA permission model

| May | Must not |
|-----|----------|
| View statutory invoices | Inventory admin, POS sell/cancel, stock adjust |
| GST summary + sales-by-channel reports | User / permission / configuration admin |
| Export CSV of Desk invoice register | Delete invoices, rewrite numbers, mutate posted fields |
| Login (leave/workforce.self) | Cash Book create, expense post, finance settings |

`finance.view` is **not** granted to accountant, so settings/expense/cash tabs stay hidden. Admin / operations_admin / superadmin also receive the reporting permissions.

### 21.5 Current finance treatment (guard)

POS complete posts Dr cash `1000` / bank `1100`, Cr revenue `4000` via `PosSaleJournalService` (fail-closed). Cancel/return posts a reversing journal. Statutory mint sets `finance_journal_id = null` and asserts no `source_type = statutory_invoice` journal exists. Enabling `statutory_invoices.post_finance_journals` **refuses mint** rather than double-posting. CA recognition policy is still required before any invoice-side GL.

### 21.6 Schema (additive, Desk only)

Migration `2026_09_01_160000_create_statutory_invoice_foundation_tables`: `invoice_sequences`, `statutory_invoices`, `invoice_sequence_allocations`, `statutory_invoice_items`, `e_invoice_records`, plus nullable `inventory_sales.statutory_invoice_id`. No Admin / `radiumbox_prod` schema. No historical backfill.

### 21.7 Migration / cutover implications

- Applying this migration on a **non-production** Desk database is additive.
- Production migrate is **not** done in this ticket and should wait for owner/CA go-live.
- Existing POS `INV-*` rows stay internal receipts; they are not rewritten.
- Admin continues to issue live GST numbers until cutover. Desk must not copy `rd_no`.
- After CA sets series + format in env, minting can be used from services. POS still will not auto-issue until invoice-on-payment vs dispatch is decided.
- Optional read-only import of historical Admin invoices remains a later ticket.

### 21.8 Rollback

1. Do not mint in production (env series/format empty).
2. Code rollback: revert this commit on the feature branch.
3. Schema rollback: `php artisan migrate:rollback` for `2026_09_01_160000` on the **same non-prod** database only. That drops the new tables and the `inventory_sales.statutory_invoice_id` column. POS `invoice_number` and finance journals are untouched.
4. Permission rollback: re-run `RolePermissionSeeder` from the previous revision to remove `accountant` reporting grants.

### 21.9 CA decisions still blocking statutory go-live

Unchanged from §18. Especially: legal series format, FY reset, per-GSTIN series, credit-note numbering, invoice-on-payment vs dispatch, revenue recognition, GSP provider, GSTIN data (Bihar clone), cutover date.

### 21.10 Remaining risks / blockers

| Risk | Status |
|------|--------|
| Legal series unset | Intended; mint fails closed |
| Two issuers if Desk mints while Admin still numbers | Do not enable production series until cutover |
| MySQL concurrency harness skipped without disposable MariaDB | Same `INVENTORY_POS_MYSQL_*` gate as inventory POS tests |
| Channel websites not integrated | Separate tickets; this repo only the Desk service |
| CGST/SGST/IGST blank on POS-issued invoices | Place of supply is a CA decision; not fabricated |
| Accountant `/finance` now redirects to invoices | Dashboard remains admin-only |

---

## 22. P-01-09-16 channel order ingest foundation

User prompt labelled **P-01-09-15**; that ID was already used for the statutory engine. Ledger ID: **RadiumDesk-P-01-09-16**.

Desk-only HTTPS ingest. Other websites are **not** modified. Statutory minting remains fail-closed.

### 22.1 Endpoint / contract

`POST /api/v1/channel-orders`

JSON object. Channels never allocate GST numbers. Desk POS is **not** an HTTP ingest channel (in-process only).

### 22.2 Authentication

Per-channel HMAC secret in Desk env (never logged, never reuse `DESK_ORDER_API_TOKEN` / Cashfree / BonVoice):

| Channel | Env |
|---------|-----|
| rdservice.in | `CHANNEL_INGEST_SECRET_RDSERVICE_IN` |
| radiumbox.com | `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` |
| rdservice.net | `CHANNEL_INGEST_SECRET_RDSERVICE_NET` |
| radiumsign.com | `CHANNEL_INGEST_SECRET_RADIUMSIGN_COM` |
| future | `CHANNEL_INGEST_SECRET_FUTURE` |

Headers:

- `X-Desk-Channel` — must match payload `channel`
- `X-Desk-Timestamp` — unix seconds
- `X-Desk-Signature` — hex HMAC-SHA256 of `{timestamp}{rawBody}` (same concatenation pattern as Cashfree webhooks)
- Optional `Idempotency-Key` — if sent, **must** equal `statutory:{channel}:{source_type}:{source_id}`

Empty secret → channel disabled (401). Timestamp skew beyond `CHANNEL_INGEST_REPLAY_WINDOW_SECONDS` (default 300) → 401 `replay`. No database access for channels.

### 22.3 Request fields

Required: `channel`, `source_type` (`commerce_order` \| `support_order` \| `external`), `source_id`, `payment_status` (`paid` \| `pending` \| `failed` \| `refunded`), `currency` (`INR` only; others rejected, not converted), `customer.name` **or** `customer.phone`, `lines[]` with `description`, `qty` ≥ 1, `unit_price` ≥ 0.

Optional (stored when provided, never invented): `source_order_id` / `external_order_id`, payment provider/reference/method, GSTIN, billing/shipping address, seller GSTIN/name, `branch_code`, `place_of_supply_state`, line SKU/variant/HSN/SAC, taxable/tax/CGST/SGST/IGST, `metadata` (keys matching password/secret/token/authorization/api_key are dropped).

### 22.4 Status model

Stored on `commerce_orders.status`:

| Status | Meaning |
|--------|---------|
| `validated` | Accepted; not invoice-eligible (missing paid / seller GSTIN / place of supply / HSN) |
| `invoice_pending` | Eligible, **not minted** (series unset and/or auto-issue off) |
| `invoiced` | Reserved for a later mint step |
| `rejected` / `failed` | Attempt log; invalid bodies are not stored as orders |
| `received` / `eligible` | Enum exists for the prompt vocabulary; ingest writes `validated` or `invoice_pending` |

`channel_ingest_attempts` records every HTTP/service outcome: accepted, duplicate, rejected, unauthorized, replay, conflict, failed.

### 22.5 Idempotency and retry

Uses the **same** statutory key: `statutory:{channel}:{source_type}:{source_id}`. Unique on `commerce_orders`.

- Same source + same payload hash → 200 duplicate, same `order_no`
- Same source + different payload → 409 conflict
- New source → 201
- Client retries with a fresh timestamp (new HMAC) and the same body

### 22.6 Invoice eligibility and minting

Ingest **never** calls `StatutoryInvoiceService`. `channel_ingest.auto_issue_invoice` is hard-false; enabling it **fails closed** rather than minting. Legal series unset is not required to accept an order. Eligibility requires paid + seller GSTIN + place of supply + HSN/SAC on every line. Missing CGST/SGST/IGST is not fabricated.

### 22.7 Finance

No `JournalPostingService` call. No `source_type = commerce_order` journals. POS `INV-*` unchanged.

### 22.8 Failure handling

401 auth/replay, 400 invalid JSON, 422 validation, 409 payload conflict, 500 unexpected (logged without secrets). Outer DB transaction rollback also rolls back the commerce order.

### 22.9 Rollback

Env secrets empty → ingest disabled. Code: revert this commit. Schema: rollback `2026_09_01_180000` on the same non-prod database. Statutory tables and POS data are untouched.

---

## 23. P-01-09-17 accountant / month-end reporting foundation

Desk-only GET reporting. Statutory numbering stays unset/fail-closed. POS journals and production channel ingest are unchanged.

### 23.1 What shipped

| Report | Source | Date filter | CSV |
|--------|--------|-------------|-----|
| Invoice register | `statutory_invoices` | `issued_at` (`date_from` / `date_to`, app timezone) | Yes (`register` + existing `finance.invoices.export`) |
| Invoice lines | `statutory_invoice_items` | Same invoice period | Yes (`lines`) |
| Sales by date and channel | Issued + cancelled statutory invoices | Same | Yes (`sales`) |
| GST / tax totals | Invoice lines; cancelled excluded from GST | Same | Yes (`gst`) |
| Collections | Invoice payment fields + paid commerce-order payment fields **already stored** | Invoice `issued_at` / order `received_at` | Yes (`collections`) |
| Cancelled invoices | Status cancelled; number kept | Same | Yes (`cancelled`) |
| Channel orders | `commerce_orders` (not GST invoices) | `received_at` | Yes (`channel_orders`) |
| Period summary | Counts/totals from the above | Same filters | Yes (`summary`) |

Audit columns on the invoice register: invoice number, document type, date, channel, source type, source id, source order id, customer name/phone/GSTIN, place of supply, branch, seller GSTIN/name, HSN/SAC, amounts, CGST/SGST/IGST **blank unless supplied**, tax, total, payment mode/reference, status, cancelled at/reason, POS internal receipt (labelled, not a GST number).

CGST/SGST/IGST are not invented. Unclassified tax is a separate column.

### 23.2 Authorization

Unchanged accountant grants: `finance.accountant.access`, `finance.invoices.view`, `finance.gst.reports`, `finance.reports.sales`, `finance.reports.export`. Still **no** `finance.view` (settings, expenses, cash/bank mutation, customer-payments placeholder). Controller middleware + per-export permission checks. POS, inventory, users, and finance settings remain 403. Routes are GET-only.

### 23.3 Channel-order eligibility (not fabricated)

Ingest still requires the channel to send paid + seller GSTIN + place of supply + HSN/SAC on every line before `invoice_eligible`. Missing fields are stored as null and explained on `status_reason`. Desk does not fill seller GSTIN, HSN, tax rates, invoice series, IRN, or place of supply. Eligible orders stay `invoice_pending` because auto-issue is disabled and the legal series is unset.

### 23.4 Remaining concrete blocker for month-end use

**An accountant cannot file or close a live GST month from Desk today.** The reporting portal can export empty or test-only registers. Live source documents are not in Desk yet:

1. Legal series unset → statutory mint fails closed → invoice register has no production rows.
2. POS `INV-*` is an internal receipt and is **excluded** from the GST register (by design).
3. Channel ingest is **disabled** without per-channel secrets and cutover approval → no commerce orders in production.
4. Historical Admin invoices are **not imported**.
5. Place of supply / CGST-SGST-IGST / complete HSN remain UNKNOWN until CA rules and channels send them.
6. GST credit notes / debit notes are not issued; cancel keeps the tax-invoice number only.
7. Customer Payments UI is still a placeholder; collections CSV only repeats stored payment mode/reference.
8. Cash/bank/UPI ledgers stay hidden from accountant (P-01-09-15: no `finance.view`).

CA decisions in §18 still block statutory go-live. Reporting itself is no longer the missing layer.

### 23.5 Rollback

Revert the P-01-09-17 commit on this branch. No schema change. POS journals, statutory tables, and channel-ingest tables are untouched.

---

## References

- `docs/rd-fresh-01-inventory-pos-foundation.md`
- `docs/rd-fresh-01-pos-finance-gap-audit.md`
- `docs/rd-fresh-01-inventory-opening-field-matrix.md`
- `docs/rd-fresh-01-radiumbox-inventory-investigation.md`
- `docs/finance-architecture-audit.md`
- `docs/cash-book-phase1.md`
- `docs/rdservice-successful-orders-invoice-path-investigation.md`
- `docs/rdservice-desk-order-api-integration.md`
- `docs/desk-admin-order-independence.md`
- `docs/radium-desk-v2-master-architecture.md`
- `docs/br-04-commercial-state.md`
- Canvas: [`rd-central-finance-invoice-architecture.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-central-finance-invoice-architecture.canvas.tsx)
