# P1 hardware fulfilment persistence and idempotency foundation

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-15**  
**Date:** 2026-09-07  
**Type:** Implementation. Persistence / hash / fulfilment foundation only.  
**Prior:** P-07-09-12 Step 1, P-07-09-13 Step 2, P-07-09-14 P0.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` tracking `origin/main` | VERIFIED |
| Before SHA | `e4c3aec328a2a132d3763c115aeb259021bc2585` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Ledger | `docs/cursor-prompt-ledger.md` | VERIFIED |
| P0 contract | `docs/desk-hardware-fulfilment-p0-contract-p-07-09-14.md` | VERIFIED |
| Production ingest | `channel_ingest.auto_issue_invoice=false`; secrets empty in prod (**not re-opened this ticket**) | VERIFIED from P0 |

No path contradiction with P0. Implementation proceeded.

---

## 1. Owner-locked identity and sequence

**Canonical statutory identity (unchanged):**

`statutory:radiumbox_com:commerce_order:RDE*`

- One identity per hardware commerce order. No second statutory key.
- Cashfree `payment_id` / Desk `cashfree_payment_id` are **payment evidence only**.
- Support-order-only records without physical hardware lines must not mint and do not open a fulfilment row.
- Sole future mint path remains `StatutoryInvoiceService::mint()` via `issueFromCommerceOrder()`. P1 does not mint.

**Owner-updated state machine (supersedes P0 invoice-then-serial):**

```
PAID → INGESTED → READY_FOR_FULFILMENT → SERIALS_ALLOCATED
  → INVOICE_ISSUED → SHIPMENT_CREATED → AWB_ASSIGNED → SHIPPED → SYNCED
```

**Invariant:** `SERIALS_ALLOCATED` MUST precede `INVOICE_ISSUED`.

The invoice issuance layer (P3) must receive the final allocated serial list. The PDF layer (P3/P4) must render:

| Serial count | Rendering |
|--------------|-----------|
| 1–5 | Serials inline on the invoice/details |
| >5 | “Serial Numbers: See Annexure A” plus **Annexure A in the same invoice PDF** |

Annexure A is not a second invoice. It is part of the same document and is immutably linked to that statutory invoice. Persistence must support 100–200 device serials (POS scale). P1 creates the serial table only; it does not allocate or render.

Shipment/AWB is allowed only after invoice + serial allocation + required gates (P5).

Failure/retry states `failed` and `retry_pending` exist so later phases can retry without losing order, serial, invoice, shipment, or provider correlation identities (nullable columns reserved).

---

## 2. Canonical hardware payload / hash

Service channels (`rdservice_in`, `rdservice_net`, `radiumsign_com`, `future`) keep the **historical** hash so existing service retries stay 200. That hash still omits metadata, timestamps, `support_order_id`, and hardware line extras.

`radiumbox_com` uses `ChannelIngestPayloadHasher::hardwareCanonical()`.

### Included in the hardware hash

| Group | Fields | Why |
|-------|--------|-----|
| Identity | `channel`, `source_type`, `source_id`, `source_order_id` | Same logical hardware order |
| Payment (non-clock) | `payment_status`, `payment_provider`, `payment_reference`, `payment_method`, `currency` | Paid amount/reference identity |
| Buyer | name, phone, email, GSTIN | Party identity |
| Addresses | flattened bill/ship, `billing_state`, structured bill/ship parts, parcel | Business ship-to / tax geography |
| Seller | GSTIN, name, `branch_code`, `place_of_supply_state`, discount | Tax/seller snapshot |
| Lines | historical tax/qty/price fields **plus** `shipping_line_kind`, `requires_shipping`, `product_id`, `model_id`, `catalog_sku`, `rdserviceid`, `amcid`, `otgid` | Hardware vs support-only; bundled RD FKs |
| Metadata | allowlisted business keys only: `radiumbox_order_id`, `ordertype`, `order_type` | Meaningful Box identity, not retry junk |

### Excluded from the hardware hash (and why)

| Field | Why excluded |
|-------|----------------|
| `paid_at`, `ordered_at` | Box rebuilds `paid_at = now()` on every outbox retry. Hashing them would 409 legitimate retries. |
| HMAC / transport timestamps | Transport only. |
| `support_order_id` | Cashfree/Desk `orders` **correlation**, not statutory identity. Desk may attach the RDE* shell after first ingest; Box retries may omit it. **Persisted**, first value / Desk `orders.order_id = source_id` wins. |
| Volatile metadata | `retry*`, `attempt*`, `timestamp*`, `enqueued*`, `nonce`, `request_id`, secrets, `cashfree_payment_id` / `payment_id` | Retry and payment-id churn must not conflict. |

### Retry rules

- Same logical hardware payload (including transport-timestamp / volatile-metadata changes) → **200 duplicate**; one commerce row; one fulfilment row.
- Meaningful business change (price, GST%, model, kind, address, allowlisted metadata, …) → **409 conflict**.
- Unique DB constraints: `commerce_orders (channel, source_type, source_id)`, `commerce_orders.idempotency_key`, `hardware_fulfilments.commerce_order_id`, `hardware_fulfilments.idempotency_key`, `hardware_fulfilments (channel, source_type, source_id)`.

---

## 3. Persistence model

Additive only. No deletes, no invoice renumber, no production data mutation.

### `commerce_orders` additive

- `billing_address_structured` JSON
- `shipping_address_structured` JSON
- `parcel` JSON

Existing `support_order_id`, `payment_reference`, `metadata`, `billing_state` remain.

### `commerce_order_items` additive

- `shipping_line_kind`, `requires_shipping`
- `product_id`, `model_id`, `catalog_sku`
- `rdserviceid`, `amcid`, `otgid`

### `hardware_fulfilments`

Opened only when **all** are true:

1. channel = `radiumbox_com`
2. `source_type` = `commerce_order`
3. `source_id` starts with `RDE`
4. at least one physical line: `shipping_line_kind=physical_merchandise` **or** `model_id` present

Initial state: `ingested`. `idempotency_key` = the canonical statutory key (not a second identity).

Nullable later identities: `statutory_invoice_id`, `shipment_id`, `shipment_no`, `awb`, provider ids, `fulfilment_branch_id`, `issuer_location`, serial timestamps.

Cashfree link (safe, no mint): if Desk `orders.order_id = source_id`, store `orders.id` as `support_order_id` and `cashfree_payment_id` as evidence. Never `issueFromSupportOrder` for this channel.

### `hardware_fulfilment_events`

Append-only state history. P1 writes `null → ingested`.

### `hardware_fulfilment_serials`

One row per device serial. No `qty<=5` assumption. Unique `serial_number` / `inventory_serial_id` when set; unique `(fulfilment, line, position)`. P1 does **not** allocate. Tests prove 200 rows persist.

---

## 4. What P1 did not do

- Serial allocation, invoice mint, invoice numbers, PDF, annexure render
- Shiprocket / AWB / Box callbacks
- Enable `DESK_INGEST_ENABLED`, ingest secrets, `auto_issue_invoice`, `worker_may_mint`
- Process or mention-mutate the seven pending orders (RDE318360, RDE318367, RDE318378, RDE318379, RDE318382, RDE318388, RDE318391)
- Change POS `requireForProductBranch` or service issuance
- Deploy, production DB writes, `radiumbox_prod`

`channel_ingest.auto_issue_invoice` remains hardcoded `false`.

---

## 5. Remaining P2+ dependencies

| Phase | Depends on P1 | Still blocked by |
|-------|---------------|------------------|
| P2 | Fulfilment row exists; this ticket already opened the foundation | Operator branch / richer Cashfree mismatch policy if Owner wants 422 |
| P3 | Mint after serials; `HardwareIssuer`; annexure-capable PDF | P0-M5 POS lock; serials from P4 |
| P4 | Serial picker writes `hardware_fulfilment_serials` | P0-M1 SKU map |
| P5 | Gates: invoice + serials + pickup | P0-M2 nicknames |
| P6 | Callback after SYNCED path | Box receiver contract |
| P7 | Observe ingest only | Matching secrets + Box URL |
| P8 | New-order E2E, then seven orders one-by-one | P8 PASS |

---

## 6. Classification

**VERIFIED:** hash split (service historical / hardware canonical); fulfilment opened only for RDE* + physical marker; unique constraints; service ingest creates no fulfilment; POS issuer helper still `delhi` for `DELHI-RETAIL`.

**OWNER-LOCKED:** canonical identity; serial-first SM; annexure rule; no second writer; seven orders frozen; no prod ingest enable.

**INFERRED:** Desk `orders.order_id = RDE*` is the preferred Cashfree link when present; request `support_order_id` is used only when that row is absent.

**UNKNOWN:** live SKU map, pickup nicknames, IRN HTTP, production overlay SHA vs this HEAD.
