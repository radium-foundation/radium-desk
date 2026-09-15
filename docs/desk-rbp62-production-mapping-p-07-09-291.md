# RBP62 production mapping resolution — RadiumDesk-P-07-09-291

**Date:** 2026-09-15  
**Prompt ID:** `RadiumDesk-P-07-09-291`  
**Mode:** SELECT-only production investigation. Stop before INSERT. No classifier change. No deploy.

Companion: `docs/desk-rbp62-product-mapping-p-07-09-290.md` (classifier path; live `model_id` was then unknown).

## Verdict

Live RBP62 `model_id` is **1402**, channel **`radiumbox_com`**. There is **no** `channel_sku_maps` row for `(radiumbox_com, 1402)`. `findProduct` therefore returns null. That is why the dashboard shows **Product mapping required**.

The purchased Box identity is **PB1000**, not PB510:

- Box `order_details.productid` = **1003** (main PB1000 listing)
- Box `order_details.modelid` = **1402** (model of 1003, `sku_code=PPPB1000L1`)
- Box **1004** (PB 510, `PPRPB510QZ`, catalog `status=0`) was **not** the line’s `modelid`

Proposed Owner map (not inserted):

```
channel = radiumbox_com
model_id = 1402
Desk product = inventory_products.id 34
SKU = RBPB1000L1
```

## Prompt ID

Next unused ledger ID after `RadiumDesk-P-07-09-290`.

## Repo / production gate

| Item | Value |
|------|--------|
| Repo | `/agent/repos/radium-desk` · `github.com/radium-foundation/radium-desk` |
| Branch | `cursor/rbp62-product-mapping-b507` |
| Production host | `srv1910783` / `187.127.129.16` |
| Queried at | 2026-09-15 22:23 IST (`NOW()` 2026-09-15 22:23:43) |
| Method | SSH `ravi@187.127.129.16` → `sudo mysql` **SELECT / SHOW / DESCRIBE only** |
| Schemas | `radium_desk`, `radiumbox_prod` |
| Production write | **NO** |
| Deploy | **NO** |
| Customer PII | not selected |

## Production SELECT result

### Support order (`radium_desk.orders`)

| Field | Value |
|-------|--------|
| `id` | 53166 |
| `order_id` | RBP62 |
| `product_name` | NULL |
| `device_model` / `device_model_id` | NULL |
| paid (`cashfree_payment_id` present) | 1 |
| serial / transaction locked | 0 / 0 |
| `created_at` | 2026-09-11 17:29:23 |

### Commerce order

| Field | Value |
|-------|--------|
| `id` | 1867 |
| `order_no` | CO-001867 |
| `channel` | `radiumbox_com` |
| `source_id` / `source_order_id` | RBP62 |
| `status` | validated |
| `payment_status` | paid |
| `invoice_eligible` | 0 |
| `support_order_id` | 53166 |
| `wallet_tender_amount` | NULL |
| `ordered_at` / `paid_at` | 2026-09-11 17:16:37 / 17:30:57 |

### Commerce line (identity)

| Field | Value |
|-------|--------|
| `line_no` | 1 |
| `sku` | 1402 |
| `model_id` | **1402** |
| `product_id` | NULL (Desk column; Box parent is on the spoke) |
| `shipping_line_kind` | `physical_merchandise` |
| `requires_shipping` | 1 |
| `description` | Precision Biometrics PB 510 / PB1000  L1 Fingerprint Reader for Aadhaar |
| `qty` | 1 |
| `rdserviceid` / `amcid` / `otgid` | 1682 / 1683 / 1685 |
| `hsn_sac` | 84716090 |

Dashboard name comes from this `description`. Mapping does not.

### Hardware fulfilment

| Field | Value |
|-------|--------|
| `id` | 65 |
| `channel` | `radiumbox_com` |
| `state` | `ready_for_fulfilment` |
| `serials_allocated_at` | NULL |
| allocated serial rows | **0** |
| `ingested_at` / `ready_at` | 2026-09-11 17:31:02 |

Classifier condition matches P-07-09-290: state ≠ ingested, zero serials, `findProduct(radiumbox_com, 1402)` null → **Product mapping required** / **View**.

### `channel_sku_maps`

No row for `model_id` 1402, 1003, or 1004.

Eight P0-M1 Box maps plus later Owner maps (406, 970, 1693) and four RIN maps are present. None cover 1402.

### Desk products and available serials

| `inventory_products.id` | SKU | Name | active | serialized | Available DELHI-RETAIL | MUMBAI | prefix |
|------------------------:|-----|------|--------|------------|------------------------:|-------:|--------|
| 34 | `RBPB1000L1` | Precision Biometrics PB1000 L1 Fingerprint Reader for Aadhaar | 1 | 1 | **60** | 0 | `LN` |
| 10 | `RBTOUCH510` | Digital Persona Eikon Touch 510 Fingerprint Reader | 1 | 1 | 2 | 0 | `7C`/`9B` |

`RBTOUCH510` is **not** Precision PB 510. Stock on that SKU is not a candidate map for RBP62.

### Box catalog and order line (`radiumbox_prod`)

Box order `318660` `ordercode=RBP62`, `ordertype=radiumecom`, `payment_status=Paid`, `status=Processing`.

`order_details`: `productid=1003`, `modelid=1402`, same listing description, qty 1, no serial label.

| `products.id` | parent | `attribute_id` | Name | `sku_code` | catalog status | live unsold (this query) |
|--------------:|--------|-----------------|------|------------|----------------|--------------------------|
| 1003 | — | NULL | Precision Biometrics PB1000 L1… | `PPPB1000L1` | 1 (`is_main=1`) | 0 on this id |
| 1004 | 1003 | 5 | Precision Biometrics PB 510… | `PPRPB510QZ` | **0** | 1 at `radium_mumbai` |
| 1402 | 1003 | 5 | Precision Biometrics PB 510 / PB1000 L1… | **`PPPB1000L1`** | 1 | **209** at `radium_delhi` |

P-01-09-06 already recorded Admin stock for **1402** as **PB 1000 - L1** / `PPPB1000L1` / parent 1003. The combined storefront name is listing copy on that same model row.

Recent Box lines for this listing (including RBP62, RBP135, RDE312682, RDE309181) all use `productid=1003` and `modelid=1402`. None used `modelid=1004`.

## Known-good compare

| | RBP62 | RBP174 (mapped) |
|--|-------|-----------------|
| channel | `radiumbox_com` | `radiumbox_com` |
| HF state | `ready_for_fulfilment` | `ready_for_fulfilment` |
| listing | combined PB 510 / PB1000 | combined MFS 100 / 110 |
| `model_id` | **1402** | **946** |
| `channel_sku_maps` | **missing** | 946 → product 28 `RBMFS110L1` |
| dashboard | Product mapping required | Allocate Serial path (map present) |

Same listing-style description pattern. Mapping follows `model_id`, not the slash in the name.

Other Desk 1402 hardware fulfilments: 8 `ingested`, 1 `ready_for_fulfilment` (RBP62). Ingested rows skip the ready-state mapping-required gate.

## Whether PB510 or PB1000

**PB1000.** Not inferred from dashboard text or from “stock available” on a similar SKU.

Evidence, in order:

1. Line `model_id` is 1402, not 1004.
2. Box 1402 `sku_code` is `PPPB1000L1` (same as parent 1003 PB1000).
3. Box 1004 (PB 510) is a different model, inactive, leftover qty 1 in Mumbai, unused on this order.
4. Desk serialized SKU for Precision PB1000 L1 is `RBPB1000L1` (id 34).
5. `RBTOUCH510` is Digital Persona, different serial prefixes, must not be used.

## Decision gate

Evidence identifies **one** correct serialized Desk SKU: **`RBPB1000L1` / id 34** for `(radiumbox_com, 1402)`.

**STOP before INSERT.** This prompt does not establish the Owner-approved production data write gate. P-07-09-27-style maps were an explicit Owner production-data ticket. This ticket only proposes the row.

Classifier bug: **none**. No application code change.

## Proposed map (not written)

```sql
-- NOT EXECUTED
-- channel = radiumbox_com
-- model_id = 1402
-- inventory_product_id = 34
-- catalog_sku = RBPB1000L1
-- channel_sku = 1402
```

**Impact if inserted later (Owner only):** RBP62 and any other `ready_for_fulfilment` 1402 line can pass `findProduct`; serial allocation can count the 60 `LN` serials at `DELHI-RETAIL`. Purchasing/history/C360/B2B/date unchanged.

**Rollback of a later insert:** delete that `channel_sku_maps` row (`channel=radiumbox_com`, `model_id=1402`).

## Explicit non-actions

- No `channel_sku_maps` INSERT
- No RBP62 / commerce / fulfilment UPDATE
- No classifier change
- No deskd / named-file overlay
- No rsync
