# RD-FRESH-01 — RadiumBox inventory migration assessment

**Project:** Radium Desk  
**Ticket:** RadiumDesk-P-01-09-06  
**Date:** 2026-09-01  
**Type:** Read-only investigation. No INSERT/UPDATE/DELETE. No import. No deploy.  
**Canvas:** [`rd-fresh-01-radiumbox-inventory-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-radiumbox-inventory-investigation.canvas.tsx)  
**Desk target schema:** [`docs/rd-fresh-01-inventory-pos-foundation.md`](rd-fresh-01-inventory-pos-foundation.md)

**Follow-on (P-01-09-08, no migration):** opening-inventory **field** decisions and empty Excel template are in [`rd-fresh-01-inventory-opening-field-matrix.md`](rd-fresh-01-inventory-opening-field-matrix.md). That ticket does **not** import `product_stock`. This assessment remains the SQL snapshot of live Admin stock.

**Classification:** **VERIFIED** = observed this ticket (SQL, DESCRIBE, or source). **INFERRED** = consistent with Admin/storefront code plus data, not independently proven. **UNKNOWN** = not inspected or not present.

---

## Verdict

`radiumbox_prod` **is reachable read-only** on the Desk KVM MariaDB. Current on-hand serial stock is concentrated on **53 model SKUs** across **4 branches**. Desk **cannot** import Admin `product_stock` as-is: Admin clones a serial row on transfer, so **8,972 live labels appear more than once**. Desk requires a **globally unique** `inventory_serials.serial_number`.

A controlled current-stock import is possible only after owner confirmation of the items in [Owner confirmation](#owner-confirmation-required-before-import). Do not invent winners for dual-SKU serials.

**Do not import `product_stock_report`.** It is a stale cron snapshot (max `last_updated` 2026-08-29; warehouse balances lag live unsold by 1,823 units across 11 product/branch pairs).

---

## Access (how this was queried)

| Item | Value | Class |
|------|--------|-------|
| Host | Desk KVM `srv1910783` / `187.127.129.16` (documented `tools/config.sh` `deskvps`) | VERIFIED |
| Engine | MariaDB **11.8.8-ubu2404** | VERIFIED |
| Schema | **`radiumbox_prod`** | VERIFIED |
| Query method | `sudo mysql` **SELECT / SHOW / DESCRIBE only** | VERIFIED |
| Queried at | **2026-09-01 13:41:08** server local | VERIFIED |
| App `.env` on this host pointing at `radiumbox_prod` | **None** under `/var/www` or `/home` | VERIFIED |
| MariaDB grant user (name only) | `radiumbox_prod`@`localhost` / `127.0.0.1` | VERIFIED |
| Desk production schema | `radium_desk` — **no `inventory_%` tables** (feature branch not deployed) | VERIFIED |
| `radium_desk` MySQL user can see `radiumbox_prod` | **No** (only `information_schema`, `radium_desk`) | VERIFIED |
| Admin production PHP tree on this KVM | **Absent** (only `/var/www/beta-admin` → `beta_admin`, `APP_URL=https://ba.radiumbox.com`) | VERIFIED |
| `https://admin.radiumbox.com/login` | HTTP 200 via Cloudflare + LiteSpeed | VERIFIED |
| Production Admin app filesystem / origin IP | **UNKNOWN** (not in `/var/www`; not in documented shared-hosting `u215544208` domain list) | UNKNOWN |
| Local Admin `.env` | Missing (`/Users/ravi/RadiumWebsites/Admin`) | VERIFIED |
| Local radiumbox.com `.env` | Missing | VERIFIED |
| Local mysqld | Not listening | VERIFIED |

Reference code (read-only): `/Users/ravi/RadiumWebsites/Admin` (inventory/POS) and `/Users/ravi/RadiumWebsites/radiumbox.com` (storefront `product_stock.credit` sum). Neither tree was modified.

Staging on the same KVM (`beta_admin`, `beta_radiumbox`) was **not** used for stock totals. Production counts below are **`radiumbox_prod` only**.

---

## Source tables (inventory-relevant)

All in database **`radiumbox_prod`**. Row counts **VERIFIED** 2026-09-01.

| Table | Rows | Role |
|-------|------|------|
| `products` | 1,685 | Catalog (main + models + RD/AMC/OTG/add-ons). Soft deletes. |
| `product_stock` | 103,495 | One row per serial **instance** (clone on move). Soft deletes. |
| `product_stock_log` | 94,141 | Serial movement/status log (not append-only unique). |
| `product_stock_report` | 504 | Cached qty by product+branch. **Stale.** |
| `radium_branch` | 6 | Locations. |
| `categories` | 17 | Catalog category. |
| `subcategories` | 47 | Catalog subcategory. |
| `attributes` | 12 | Variant/add-on type (`1` RD Service, `2` Warranty, `4` OTG, `5` Model, …). |
| `brands` | 105 | Brand names. |
| `product_category` | 2 | Extra category link (almost unused). |
| `product_images` | (not counted for migration) | Media. |
| `orders` | 305,474 | Commercial orders including POS. |
| `order_details` | 722,766 | Lines; serial JSON in `label`. |
| `orders_payment` | 5,008 | Payments. |
| `order_history` | 896,801 | Order status history — **not** stock ledger. |
| `invoice` | 259,056 | Invoices. |
| `credit_note` | 31 | Credit notes (order-linked; not a stock table). |
| `supplier_purchase` | 135 | Stock-in / PO header. |
| `supplier_purchase_product` | 159 | PO lines. |
| `users` | 512,538 | Customers (email unique; phone not unique). |
| `users_address` | (not counted) | Address/GST for POS. |
| `model_list` | 33 | Unrelated to `product_stock` models — **do not assume mapping**. |

**No reservation / hold table.** **No unit-of-measure column** on `products`. **No per-serial cost column** on `product_stock`. All VERIFIED (table list + DESCRIBE).

---

## 1. Products / catalog

### Shape (VERIFIED DESCRIBE)

Identity: `products.id`. Parent for children: `products.product_id` (varchar, stores parent numeric id). Main listing flag: `is_main`. Add-on/model type: `attribute_id`. Website: `website`. Active: `status=1`. Soft delete: `deleted_at`.

SKU: `sku_code`. Name: `product_name` / `short_name`. Tax: `hsn_code`, `gst_percentage` (varchar). Prices (varchar): `selling_price`, `publish_price`, `regular_price`, `express_price`, `purchase_price`, `purchase_cost`, `purchase_price_actual`, plus packing/PG/margin fields. `liveprice` is float (storefront computed bundle).

**Unit:** no column. Class: VERIFIED absent.

### Live catalog counts (VERIFIED)

| Metric | Count |
|--------|------:|
| Total product rows | 1,685 |
| Live (`deleted_at` NULL) | 1,406 |
| Soft-deleted | 279 |
| Live `status=1` | 1,112 |
| Live not active | 294 |
| Live `is_main=1` | 294 |
| Live not main (models/add-ons) | 1,112 |

Live `website` mix includes `radium`, `radiumrdservice`, `rdservicein`, `rdservicenet`, and **710 live rows with `website` NULL**.

Live `product_type`: 1,149 NULL, 185 Digital, 72 Physical.

Live `attribute_id`: Model `5` = 702; NULL = 294; Warranty `2` = 197; RD Service `1` = 142; OTG `4` = 25; Installation `8` = 23; Other `9` = 19; plus tiny Connectivity/Year/Antenna.

### SKU / tax / price completeness (live products, VERIFIED)

| Field | Present | Empty/null |
|-------|--------:|-----------:|
| `sku_code` | 526 | 880 |
| `hsn_code` | 813 | 593 |
| `gst_percentage` | 882 | 524 |
| `selling_price` | 868 | 538 |
| `purchase_price` | 364 | 1,042 |
| `purchase_cost` | 382 | 1,024 |
| `publish_price` | 1,343 | 63 |

`gst_percentage` values: **18** (867), null (524), **0** (9), **20** (3), plus one each of 1, 10, `00`.

**Duplicate live `sku_code`:** 28 codes covering 64 rows. Desk `inventory_products.sku` is unique — these cannot import without a winner. Samples: `PSTFM220L1` (ids 1053,323,**970** — 970 holds stock), `PIDMORE3L1` (321,**951**), `PMTMFS100Z` (313,**945**), `test` (1711,1713,1760), GPU/token duplicates with no current stock. Full 28 groups are in the query output from this ticket; stocked SKUs that collide are **owner confirmation**.

Max live `sku_code` length 11 (fits Desk 64). Max `hsn_code` 11 (fits Desk 16). Max `product_name` 243 (fits default 255).

### Stocked catalog (what actually has serials)

| Metric | Count | Class |
|--------|------:|-------|
| Distinct `product_id` with any live stock | 102 | VERIFIED |
| Distinct `product_id` with live available (`is_sold='No'`) | 53 | VERIFIED |
| All 53 available SKUs are `attribute_id=5` (Model) | yes | VERIFIED |
| Available on `status<>1` products | 969 (4 qty), 1004 (1 qty) | VERIFIED |

Stock lives on the **model row**, not the `is_main` parent. Example: available Mantra MFS 110 is `products.id=946`, parent `product_id=944`.

---

## 2. Inventory balances

Admin does **not** store a quantity column. Quantity = **count of `product_stock` rows**. Cron (`InventoryCron`) sets:

- `total_credit` = all live rows at branch  
- `total_debit` = `is_sold='Yes'`  
- `balance` = credit − debit (= unsold rows)  
- `new` = unsold + `status='New'`  
- `refurbuised` = unsold + status in Replaced/Renew/Old/Return  
- `damage` = sold + `status='Damage'`

**Reserved quantity:** not represented. Class: VERIFIED absent → Desk `reserved_qty` must be 0 unless a new process is designed.

### Live `product_stock` (deleted_at NULL) — VERIFIED

| Branch slug | Live rows | Available (`is_sold='No'`) | Sold (`is_sold='Yes'`) |
|-------------|----------:|---------------------------:|-----------------------:|
| `radium_delhi` | 91,790 | 2,545 | 89,245 |
| `delhi_wharehouse` | 8,206 | 6,143 | 2,063 |
| `radium_bihar` | 315 | 314 | 1 |
| `radium_mumbai` | 523 | 25 | 498 |
| `radium_up` | 0 | 0 | 0 |
| `radium_chennai` | 0 | 0 | 0 |
| **Total** | **100,834** | **9,027** | **91,807** |

Soft-deleted stock rows: **2,661** (do not import).

`credit` matches `is_sold` on all live rows (`No`→`1`, `Yes`→`0`). VERIFIED.

### Distinct serials vs row counts (VERIFIED)

| Metric | Count |
|--------|------:|
| Live rows | 100,834 |
| Distinct `label` | 91,860 |
| Available rows | 9,027 |
| Distinct available `label` | 8,974 |
| Extra available rows (same serial twice) | 53 |

Global available 9,027 vs sum of branch available 9,027 — **branch totals equal global totals** for row counts. Distinct serials are lower because of clones.

### `product_stock_report` vs live unsold (VERIFIED — do not import)

| Metric | Value |
|--------|-------|
| Report rows | 504 |
| Report `SUM(balance)` | 7,254 |
| Live unsold rows | 9,027 |
| Report rows with no live stock pair | 374 |
| Product+branch pairs where `balance` ≠ live unsold | 11 |
| Abs qty gap on those 11 | 1,823 |
| `last_updated` range | 2024-07-24 … **2026-08-29 22:46:06** |

Largest stale gaps: `946`/`delhi_wharehouse` live 4416 vs report 3391; several Bihar rows report 0 while live unsold is 5–239.

---

## 3. Serial inventory

### Row meaning (VERIFIED from Admin `StockController` / `PosController`)

- `label` = serial number. **Non-unique index.**  
- `branch` = `radium_branch.slug` (not numeric id).  
- `product_id` = model `products.id`.  
- `is_sold` / `credit` = available vs not.  
- `status` = New / Sold / Return / Replaced / Damage / Renew — **often left as `New` after sale.**  
- `purchase_id` = PO id when Excel stock-in used.  
- `order_id` = rarely filled (120 live rows).  
- Transfer: old row set sold, **new row inserted** at destination (`Move` log).  

Desk contrast: one serial, one row, relocate `branch_id`.

### Current status mix (live rows, VERIFIED)

| is_sold | credit | status | Rows |
|---------|--------|--------|-----:|
| Yes | 0 | New | 89,315 |
| No | 1 | New | 9,007 |
| Yes | 0 | Sold | 1,642 |
| Yes | 0 | Return | 826 |
| No | 1 | Return | 19 |
| Yes | 0 | Replaced | 18 |
| Yes | 0 | Damage | 4 |
| Yes | 0 | Renew | 2 |
| No | 1 | Sold | **1** |

`status='New'` on 89,315 sold rows is **not** a data error relative to Admin POS code (sale updates `is_sold`, not always `status`). Class: VERIFIED code + counts. **Do not map Desk status from `product_stock.status` alone.** Use `is_sold` for on-hand.

Damage: 4 rows, all `is_sold='Yes'` (blocked, not sellable). Matches cron.

### Duplicates (VERIFIED)

| Metric | Count |
|--------|------:|
| Duplicate live label groups | **8,972** |
| Rows in those groups | 17,946 |
| Extra rows vs unique labels | 8,974 |
| Groups with 2 copies | 8,970 |
| Groups with 3 copies | 2 (`9324946`, `9397414`) |
| Groups spanning **two branches** | 8,807 |
| Groups spanning **two products** | **168** |
| Groups with **>1 available** copy | **53** |
| Groups available **and** sold | 6,509 |

8,807 multi-branch groups ≈ 8,764 `product_stock_log.status='Move'` rows. Class: **INFERRED** that most duplicates are clone-on-move, not two physical devices.

**53 double-available serials** are **all** the same pair: product **1723** (`PRUGR89GPS` UGR89) and **926** (`PBBUGR86QZ` UGR86), both `is_sold='No'` at `radium_delhi`. Labels look like `g19061-mpn25gr14002179-09/25`. **Cannot import both** into Desk. Full list: [Appendix A](#appendix-a--53-dual-available-serials-ugr86--ugr89).

Other multi-product pairs (mostly sold clones, still unsafe if importing history):

| Product pair | Serial groups | Notes |
|--------------|--------------:|-------|
| 1723, 926 | 100 | Includes the 53 available duals |
| 1097, 951 | 45 | Morpho RD Services vs MSO1300e3-L1 device |
| 1751, 1757 | 11 | Raivens GPS / sibling |
| 950, 951 | 4 | Morpho L0 vs L1 |
| 945, 946 | 4 | MFS 100 vs MFS 110 |
| 1699,899 / 1747,334 / 969,970 / 1419,1420 | 1 each | |

Garbage serial **`1`**: two sold rows, products 1699 and 899.

### Orphans (VERIFIED)

| Check | Count |
|-------|------:|
| Live stock `product_id` missing from `products` | **0** |
| Live stock pointing at soft-deleted product | **0** |
| `product_stock_log.serial_id` missing `product_stock.id` | **0** |
| Log `serial_no` with no live stock `label` | **3** serials (6 log rows) |

Orphan log serials (sold then returned; no live stock row — likely later deleted): `2524I036514`, `9837237`, `9839168`.

### Whitespace / encoding (VERIFIED)

- Empty labels: 0  
- 14 sold Mantra serials prefixed with UTF-8 NBSP (`C2A0`) e.g. ` 8255749`  
- 1 available GPS serial with a leading space: `[ G17593-MPN25EO0E008590-07/25]`  
- Desk `InventorySerialNumber::normalize` trims + uppercases — NBSP may **not** trim with PHP `trim()` depending on locale. **Owner confirmation** before normalize.

Live max `label` length **36** (fits Desk 128).

### Purchase / order linkage (VERIFIED)

| Field | Live rows with value | Empty |
|-------|---------------------:|------:|
| `purchase_id` | 21,829 | 79,005 |
| `order_id` | 120 | 100,714 |
| Available **and** `order_id` set | 2 | — |

### Sale/return history

See [Stock history](#4-stock-history). 72 **distinct available** labels also have a `Sold` log (restock/return or clone leftover). **INFERRED**; list not dumped here — hold if importing those serials as never-sold.

---

## 4. Stock history

| Source | What it records | Class |
|--------|-----------------|-------|
| `product_stock` insert | Stock-in (paste serials or Excel `ProductStock` import) | VERIFIED code |
| `product_stock_log` `status='Sold'` | POS/order serial assign | VERIFIED |
| `product_stock_log` `status='Move'` | Branch transfer (clone) | VERIFIED |
| `product_stock_log` `status='Rollback'` | POS rollback restock | VERIFIED |
| `product_stock_log` `status='Return'/'Replaced'/'Damage'/'Renew'` | Manual serial status | VERIFIED |
| `supplier_purchase` + `supplier_purchase_product` | PO header/lines; serials optional via `purchase_id` | VERIFIED |
| `orders` + `order_details.label` | Commercial sale lines + JSON serials | VERIFIED |
| `credit_note` | 31 credit notes; **no serial column** | VERIFIED |
| `order_history` | Order workflow, not warehouse | VERIFIED |
| Desk-style reservation table | **Absent** | VERIFIED |
| In-transit hop | **Absent** (move is immediate clone) | VERIFIED |

### `product_stock_log.status` (VERIFIED)

| status | Rows |
|--------|-----:|
| Sold | 83,530 |
| Move | 8,764 |
| Rollback | 1,304 |
| Return | 513 |
| Replaced | 22 |
| Damage | 4 |
| Renew | 4 |

6,459 live `is_sold='Yes'` rows have **no** `Sold` log — expected for transfer clones (Move only). INFERRED.

### Orders vs serials (VERIFIED)

`orders.ordertype`: rdservice 252,584; radiumecom 49,703; **POS 3,163**; radiumsign 24.

POS live statuses: Completed 2,956; Cancelled 63; Shipped 53; Pending 52; reject 33; Delivered 3; Return Pickup 2; Awaiting Processing 1.

POS `order_details`: 3,974 lines; 3,745 have `label` JSON; **229 missing serials**.

Distinct orders with a Sold log: 13,954 (not only POS — ecom/other also consume serials).

---

## 5. Reconciliation

| Check | Result | Class |
|-------|--------|-------|
| Product qty vs serial count | Qty **is** serial row count. No separate qty ledger. | VERIFIED |
| Branch available vs global available | 6,143+2,545+314+25 = **9,027** | VERIFIED |
| Distinct available serials vs available rows | 8,974 vs 9,027 (**53** extras) | VERIFIED |
| Report balance vs live unsold | **Mismatch** (7,254 vs 9,027) | VERIFIED |
| Serial vs Sold log (available with Sold history) | 72 labels | VERIFIED count |
| Sold rows with no Sold log | 6,459 | VERIFIED |
| Orphan stock product_id | 0 | VERIFIED |
| `is_sold` vs `credit` | Consistent | VERIFIED |
| `status` vs `is_sold` | **Conflict by design** (89,315 sold+New; 1 available+Sold) | VERIFIED |

### Records that cannot safely map to Desk without owner rules

Desk unique serial + unique SKU + unique customer phone.

1. **8,972 duplicate labels** — cannot insert all Admin rows.  
2. **53 dual-available UGR86/UGR89 serials** — two on-hand SKUs.  
3. **168 multi-product labels** — SKU identity ambiguous.  
4. **28 duplicate `sku_code` groups** — unique SKU violation.  
5. **880 live products with empty SKU** — Desk SKU required.  
6. **Serial `1` and NBSP/space serials** — normalize/collision risk.  
7. **`users.phone`:** 428,414 non-empty; **17,149 duplicate phone groups**. Desk `inventory_customers.phone` unique.  
8. **POS users without phone:** 165 of 1,008.  
9. **Inactive products with available stock** (969, 1004).  
10. **Bihar branch GSTIN equals Delhi GSTIN.**  
11. **Historical sold clones** if anyone tries to import sold-as-sold at two branches.  
12. **`product_stock_report`** — stale, derived.  
13. **RD/AMC/OTG catalog rows** — not serial stock; Desk has no add-on bundle model (foundation gap, already documented).

---

## 6. Mapping proposal (Desk `inventory_*`)

Proposed **only where the source is unambiguous**. Ambiguous rows stay in [Owner confirmation](#owner-confirmation-required-before-import).

### Branches → `inventory_branches`

| Admin `radium_branch` | Desk | Notes | Class |
|-----------------------|------|-------|-------|
| `slug` | `code` (unique, 32) | Keep slug; `delhi_wharehouse` spelling is source | VERIFIED |
| `branch_name` | `name` | | VERIFIED |
| `gst_no` | `gstin` | Bihar copies Delhi — confirm | VERIFIED value / UNKNOWN correctness |
| `status` | `is_active` | All 6 are status=1 | VERIFIED |
| Invoice/RD sequences | **Do not copy** | Desk has its own `invoice_sequence`; Admin sequences are live commercial | VERIFIED / risk |
| `radium_up`, `radium_chennai` | Optional empty branches | Zero stock | VERIFIED |

`mycafe_password` exists on `radium_branch`. **Do not import secrets.**

### Products / variants

**Recommended (not executed):** import **stocked models** as `inventory_products` (the `product_stock.product_id`), not the `is_main` parent. Serials and balances already key off the model id.

| Admin | Desk | Class |
|-------|------|-------|
| Model `products.id` with stock | new `inventory_products` row | Proposed |
| `sku_code` if unique and non-empty | `sku` | Proposed |
| `product_name` | `name` | Proposed |
| `hsn_code` | `hsn_code` | Proposed |
| `gst_percentage` | `gst_percentage` | Cast varchar→decimal; skip invalid |
| `selling_price` | `unit_price` | Varchar; confirm tax-inclusive vs exclusive (Admin POS uses selling + GST%) — **INFERRED** exclusive |
| `status=1` | `is_active` | Proposed |
| `attribute_id=5` + serials | `is_serialized=true` | VERIFIED these 102 are serialised |
| Parent `is_main` product | Optional grouping only; **or** Desk variant parent — **not chosen** | UNKNOWN / owner |
| RD/AMC/OTG (`attribute_id` 1/2/4) | **Do not auto-create** as stock SKUs | VERIFIED no/odd stock |

`inventory_product_variants`: **not required** if each model is its own product. Using variants would still need unique variant SKUs.

`device_model_id`: Desk `device_models` is support identity, not Admin catalog. **Do not auto-link.** UNKNOWN overlap.

### Inventory balances → `inventory_stock_balances`

Rebuild from imported serials / qty, do **not** copy `product_stock_report`.

- `available_qty` = count of imported available serials (serialized)  
- `reserved_qty` = 0  
- `balance_key` = Desk generator  

### Serials → `inventory_serials`

| Condition | Proposed Desk status | Import? |
|-----------|----------------------|---------|
| Exactly one live `is_sold='No'` row for `label` | `available` at that `branch` + `product_id` | Yes, after SKU mapped |
| `is_sold='No'` and `status='Return'` | `returned` vs `available` | **Owner** — 19 rows |
| `status='Damage'` | `damaged` | 4 rows, currently sold-flagged |
| Multiple `is_sold='No'` for same `label` | — | **No** until owner picks SKU |
| Only `is_sold='Yes'` rows | `sold` optional | **Owner** — not needed for on-hand; uniqueness still requires one survivor |
| Soft-deleted stock | — | No |
| `purchase_id` | `batch_code` | Optional; 21,829 rows |

Collapse rule for clone-on-move (needs approval, **INFERRED** not truth): keep the `is_sold='No'` row if present; else keep latest `created_at` sold row. **Not applied this ticket.**

### Movements → `inventory_movements`

Optional later. `product_stock_log` can seed types: Sold→`sale`, Move→`transfer_out`/`transfer_in` (two Desk events, one Admin clone), Rollback→`sale_cancel`, Return→`return`, Damage→`adjustment`. `serial_id` in the log points at the **pre-clone** stock id. Reconstructing a correct Desk ledger from clones is **high risk**. Prefer **on-hand snapshot + empty movement history** for go-live, then operate in Desk. Class: INFERRED recommendation.

### Reservations

No source. Leave empty.

### Customers → `inventory_customers`

Do **not** import 512,538 `users`. Desk unique phone.

Possible later subset: POS `orders.userid` with a **unique, non-empty** phone, name from `users.name`, GST from `users.gst_no` or address. 1,008 POS users; 165 lack phone. Duplicate phones among remaining POS users **not fully enumerated** this ticket (global duplicate phone groups = 17,149). Class: VERIFIED totals; POS-only phone dups UNKNOWN.

Support Desk `orders` stay separate (`support_order_id` unused).

---

## 7. Migration readiness

| Finding | Class | Ready to import? |
|---------|-------|------------------|
| `radiumbox_prod` located and counted | VERIFIED | Access yes |
| Branch list (6) | VERIFIED | After GSTIN confirm |
| On-hand available row counts by branch | VERIFIED | Yes as counts |
| On-hand **distinct** serials (8,974) | VERIFIED | After dual-SKU rule |
| Dual-available 53 UGR serials | VERIFIED | **No** |
| Clone-on-move duplicates | VERIFIED | Not as raw rows |
| Catalog 1,406 live products | VERIFIED | **No** (empty/dup SKU, add-ons) |
| 53 available model SKUs + prices/HSN/GST | VERIFIED | After SKU-dup winners |
| `product_stock_report` | VERIFIED stale | **No** |
| Reservations | VERIFIED absent | N/A (zeros) |
| Unit of measure | VERIFIED absent | N/A |
| Per-serial valuation | VERIFIED absent | Product price only / UNKNOWN cost |
| POS history as Desk sales | INFERRED incomplete | **No** this phase |
| Full customer import | VERIFIED phone collisions | **No** |
| Admin PHP production path | UNKNOWN | Not required for SQL snapshot |
| Whether production radiumbox.com uses this same schema | INFERRED (storefront code) / UNKNOWN (no prod `.env`) | Confirm before freeze |
| Desk production `inventory_*` | VERIFIED absent | Destination empty after deploy+migrate |

**Readiness: BLOCKED for automated unique-serial import. CONDITIONAL for a current-on-hand snapshot after owner decisions.**

---

## Owner confirmation required before import

Exact decisions. Do not invent defaults.

1. **Scope:** current on-hand only vs also sold history vs also full catalog.  
2. **UGR duals:** for each of the 53 serials in Appendix A, keep product **926** (UGR86 / `PBBUGR86QZ`) or **1723** (UGR89 / `PRUGR89GPS`) or neither.  
3. **Other 115 multi-product labels** (pairs 1097/951, 1751/1757, 950/951, 945/946, …): historical only or also block?  
4. **Duplicate SKUs** that include stocked ids 970, 951, 945, 1402, 1622, etc.: which `products.id` is the surviving Desk SKU.  
5. **Empty `sku_code`:** skip vs generate (generation rule must be written by owner).  
6. **Inactive with stock:** import 969 (4 avail) and 1004 (1 avail)?  
7. **19 Return-available** serials: Desk `returned` vs `available`.  
8. **1 available + `status='Sold'`** row: treat as available or hold.  
9. **72 available labels with Sold logs:** hold vs import as available.  
10. **NBSP/space serials:** strip to ASCII vs keep vs skip. Serial `1`: skip.  
11. **Branches:** create UP/Chennai empty? Keep slug `delhi_wharehouse`? Correct Bihar GSTIN (`07AAICP1128M1Z9` currently). `radium_up.city` is Patna — confirm.  
12. **Customers:** POS-only vs none this phase; how to handle blank/duplicate phones.  
13. **Sold serials:** omit (recommended) vs import one `sold` row per label.  
14. **Movements:** omit (recommended) vs rebuild from `product_stock_log`.  
15. **Freeze window:** Admin continues writing `product_stock`; counts drift after 2026-09-01 13:41:08.  
16. **Storefront:** confirm production radiumbox.com database is `radiumbox_prod` before any cutover (this ticket did not see a production storefront `.env`).

---

## Migration risks

- Importing all `product_stock` rows **will fail** unique serial.  
- Importing report balances **will understate** warehouse (especially `delhi_wharehouse`).  
- Normalizing case/trim can collide labels Desk-side.  
- Copying Admin invoice sequences onto Desk branches would **desync live GST invoicing** still running in Admin.  
- POS complete in Desk after a partial import could sell a serial still sellable in Admin.  
- `liveprice` / bundle GST on storefront is not Desk POS tax (Desk uses product `gst_percentage` only).  
- Secrets on `radium_branch` (`mycafe_password`) must never be copied.

---

## What was not exported

No CSV/JSON dump of serials was written. Analysis used SQL aggregations over every live `product_stock` row. Appendix A lists only the 53 dual-available blockers.

---

## Appendix A — 53 dual-available serials (UGR86 + UGR89)

All: `radium_delhi`, both rows `status=New`, `is_sold=No`, `credit=1`. Product 1723 row id then 926 row id.

`g19061-mpn25gr14002179-09/25` (73558 / 73658), `g19063-mpn25gr14002108-09/25` (73554 / 73654), `g19074-mpn25gr14002224-09/25` (73631 / 73731), `g19078-mpn25gr14002194-09/25` (73619 / 73719), `g19085-mpn25gr14002100-09/25` (73563 / 73663), `g19096-mpn25gr14002266-09/25` (73589 / 73689), `g19107-mpn25gr14002034-09/25` (73630 / 73730), `g19108-mpn25gr14002264-09/25` (73611 / 73711), `g19133-mpn25gr14002567-09/25` (73600 / 73700), `g19136-mpn25gr14002141-09/25` (73626 / 73726), `g19138-mpn25gr14002285-09/25` (73592 / 73692), `g19141-mpn25gr14002396-09/25` (73586 / 73686), `g19148-mpn25gr14001697-09/25` (73585 / 73685), `g19149-mpn25gr14001696-09/25` (73557 / 73657), `g19150-mpn25gr14002085-09/25` (73606 / 73706), `g19152-mpn25gr14003175-09/25` (73608 / 73708), `g19153-mpn25gr14003268-09/25` (73581 / 73681), `g19154-mpn25gr14002579-09/25` (73570 / 73670), `g19155-mpn25gr14002561-09/25` (73628 / 73728), `g19156-mpn25gr14002560-09/25` (73632 / 73732), `g19157-mpn25gr14002551-09/25` (73588 / 73688), `g19159-mpn25gr14002166-09/25` (73576 / 73676), `g19164-mpn25gr14002134-09/25` (73584 / 73684), `g19166-mpn25gr14002283-09/25` (73559 / 73659), `g19167-mpn25gr14001571-09/25` (73549 / 73649), `g19168-mpn25gr14002482-09/25` (73562 / 73662), `g19169-mpn25gr14002553-09/25` (73555 / 73655), `g19171-mpn25gr14002205-09/25` (73590 / 73690), `g19173-mpn25gr14002314-09/25` (73547 / 73647), `g19174-mpn25gr14002022-09/25` (73607 / 73707), `g19176-mpn25gr14002089-09/25` (73561 / 73661), `g19177-mpn25gr14002072-09/25` (73599 / 73699), `g19178-mpn25gr14002079-09/25` (73645 / 73745), `g19180-mpn25gr14002084-09/25` (73587 / 73687), `g19181-mpn25gr14002123-09/25` (73613 / 73713), `g19183-mpn25gr14002288-09/25` (73638 / 73738), `g19184-mpn25gr14003270-09/25` (73601 / 73701), `g19185-mpn25gr14002279-09/25` (73556 / 73656), `g19188-mpn25gr14002291-09/25` (73577 / 73677), `g19191-mpn25gr14002190-09/25` (73553 / 73653), `g19192-mpn25gr14001454-09/25` (73580 / 73680), `g19193-mpn25gr14002983-09/25` (73642 / 73742), `g19194-mpn25gr14002119-09/25` (73591 / 73691), `g19196-mpn25gr14003215-09/25` (73546 / 73646), `g19199-mpn25gr14002295-09/25` (73644 / 73744), `g19200-mpn25gr14003246-09/25` (73640 / 73740), `g19204-mpn25gr14002389-09/25` (73593 / 73693), `g19207-mpn25gr14003193-09/25` (73624 / 73724), `g19208-mpn25gr14003160-09/25` (73622 / 73722), `g19209-mpn25gr14003242-09/25` (73639 / 73739), `g19210-mpn25gr14002275-09/25` (73636 / 73736), `g19213-mpn25gr14002144-09/25` (73602 / 73702), `g19216-mpn25gr14002276-09/25` (73616 / 73716).

---

## Appendix B — Available quantity by product and branch

Live `is_sold='No'`, queried 2026-09-01. Selling prices as stored (varchar). Parent = `products.product_id`.

| product_id | sku | short_name | parent | branch | avail | sell | gst | hsn | status |
|-----------:|-----|------------|-------:|--------|------:|------|-----|-----|-------:|
| 946 | PMTMFS110Z | MFS 110 | 944 | delhi_wharehouse | 4416 | 2117.80 | 18 | 84716050 | 1 |
| 951 | PIDMORE3L1 | MSO1300e3-L1 | 949 | delhi_wharehouse | 1228 | 2541.53 | 18 | 84716050 | 1 |
| 946 | PMTMFS110Z | MFS 110 | 944 | radium_delhi | 623 | 2117.80 | 18 | 84716050 | 1 |
| 1693 | RBPMARC11Z | MARC11 | 1692 | radium_delhi | 535 | 2372.03 | 18 | 84716050 | 1 |
| 970 | PSTFM220L1 | L1 | 968 | delhi_wharehouse | 282 | 2329.66 | 18 | 84716050 | 1 |
| 1006 | PMTMIS100Z | MIS 100 V2 | 1005 | radium_delhi | 263 | 2541.53 | 18 | 84716050 | 1 |
| 946 | PMTMFS110Z | MFS 110 | 944 | radium_bihar | 239 | 2117.80 | 18 | 84716050 | 1 |
| 1006 | PMTMIS100Z | MIS 100 V2 | 1005 | delhi_wharehouse | 204 | 2541.53 | 18 | 84716050 | 1 |
| 1412 | PAAST300L1 | AST300 L1 | 1411 | radium_delhi | 114 | 2372.03 | 18 | 84716050 | 1 |
| 929 | PGSBU353QZ | BU - 353N | 35 | radium_delhi | 102 | 2838.14 | 18 | 85269190 | 1 |
| 1751 | RBRAIVESBI | SBI | 1750 | radium_delhi | 90 | 3897.46 | 18 | 85269190 | 1 |
| 926 | PBBUGR86QZ | UGR 86 | 32 | radium_delhi | 89 | 1694.07 | 18 | 85269190 | 1 |
| 1723 | PRUGR89GPS | UGR89 NaviC | 32 | radium_delhi | 86 | 1694.07 | 18 | 85269190 | 1 |
| 1410 | PMTMFSUCBL | MFS110 USB cable | 1408 | radium_delhi | 69 | 168.64 | 18 | 85444292 | 1 |
| 1409 | PMTMFSCCBL | MFS110 Type-C cable | 1408 | radium_delhi | 68 | 168.64 | 18 | 85444292 | 1 |
| 1402 | PPPB1000L1 | PB 1000 - L1 | 1003 | radium_delhi | 65 | 3727.97 | 18 | 84716090 | 1 |
| 1622 | RBPELIMGPS | EGPS-A1 | 1621 | radium_delhi | 49 | 1863.56 | 18 | 85269190 | 1 |
| 1097 | *(empty)* | MSO1300 E3-L1 RD | 1018 | radium_delhi | 45 | 422.88 | 18 | 998313 | 1 |
| 951 | PIDMORE3L1 | MSO1300e3-L1 | 949 | radium_delhi | 41 | 2541.53 | 18 | 84716050 | 1 |
| 970 | PSTFM220L1 | L1 | 968 | radium_bihar | 40 | 2329.66 | 18 | 84716050 | 1 |
| 1420 | PIDMORUCBL | Morpho USB cable | 1408 | radium_delhi | 32 | 168.64 | 18 | 85444292 | 1 |
| 1419 | PIDMORCCBL | Morpho Type-C cable | 1408 | radium_delhi | 30 | 168.64 | 18 | 85444292 | 1 |
| 1002 | PTKTMF20QZ | TMF 20 | 1001 | radium_delhi | 25 | 1694.07 | 18 | 84716050 | 1 |
| 406 | PPKTOKENQZ | WD USB | 309 | radium_delhi | 22 | 478.8 | 18 | 84733099 | 1 |
| 607 | PMTMATIRIZ | MATISX | 606 | radium_delhi | 21 | 21185.59 | 18 | 84716050 | 1 |
| 615 | PMTMORPH60 | MORPHS-60 | 614 | radium_delhi | 21 | 25422.88 | 18 | 84716050 | 1 |
| 625 | PMTMFS500Z | LX | 624 | radium_delhi | 21 | 3050.00 | 18 | 84716050 | 1 |
| 970 | PSTFM220L1 | L1 | 968 | radium_delhi | 21 | 2329.66 | 18 | 84716050 | 1 |
| 951 | PIDMORE3L1 | MSO1300e3-L1 | 949 | radium_bihar | 20 | 2541.53 | 18 | 84716050 | 1 |
| 945 | PMTMFS100Z | MFS 100 | 944 | radium_delhi | 19 | 2795.76 | 18 | 84716050 | 1 |
| 950 | PIDMORE3QZ | MSO1300e3-L0 | 949 | radium_delhi | 16 | 3219.49 | 18 | 84716050 | 1 |
| 1006 | PMTMIS100Z | MIS 100 V2 | 1005 | radium_bihar | 10 | 2541.53 | 18 | 84716050 | 1 |
| 929 | PGSBU353QZ | BU - 353N | 35 | delhi_wharehouse | 9 | 2838.14 | 18 | 85269190 | 1 |
| 930 | PFUFS80HQZ | FS80 | 36 | radium_delhi | 9 | 4151.69 | 18 | 84716050 | 1 |
| 1620 | RBPFOXSATG | FX100 | 1619 | radium_delhi | 7 | 3388.98 | 18 | 85269190 | 1 |
| 1636 | RBPIRIUNIZ | IriUniverse-Two | 1635 | radium_mumbai | 7 | 16948.31 | 18 | 84716050 | 1 |
| 1422 | PACFMUCBLE | Access FM220 USB cable | 1408 | radium_delhi | 6 | 168.64 | 18 | 85444292 | 1 |
| 1758 | *(empty)* | MBAS30 | 1117 | radium_delhi | 6 | 21185.59 | 18 | 84716090 | 1 |
| 406 | PPKTOKENQZ | WD USB | 309 | radium_mumbai | 5 | 478.8 | 18 | 84733099 | 1 |
| 929 | PGSBU353QZ | BU - 353N | 35 | radium_bihar | 5 | 2838.14 | 18 | 85269190 | 1 |
| Remaining SKUs | — | qty 1–4 | — | mixed | 48 | — | — | — | mixed |

Lower-qty remainder (VERIFIED in query, abbreviated): 1616 Vriddhi L1 4 Delhi; 1749 BioEnable C600 4 Delhi; 342 Evolis kit 4; 345 Primacy ribbon 4; 350 Transline GPS 4; 597 Logitech mouse 4; 931 FS88H 4; **969 FM220 L0 status=0** 4 Delhi; 625 MFS500 3 warehouse; 909 NEXT NB-3023 3; 916 Evolis 300 ribbon 3; 946 Mumbai 3; 1006 Mumbai 2; 591 Logitech C270 2; 911 DP Touch 510 2; 948 Secugen Mumbai 2; then 1-offs including **1004 PB 510 status=0** Mumbai, 1013 ThumbScan T1 warehouse, 1506 BluPrints, 1691 VIVO T3X, 1762 MBAS50, 332 BMT20 Delhi+Mumbai, 339 Dell KM3322W, 343 Primacy 2 ribbon, 347 Feitian token, 348 Gemalto CT30, 404 Green bit Mumbai, 945 Mumbai, 951 Mumbai, 970 Mumbai.

**Note:** 926 available 89 + 1723 available 86 **double-count 53 serials**. Distinct GPS serials for that pair = 89+86−53 = **122**, not 175.

---

## Appendix C — Branches (VERIFIED)

| id | slug | name | GSTIN | state | city | invoice_slug | rd_slug |
|----|------|------|-------|-------|------|--------------|---------|
| 1 | radium_delhi | Radium - Delhi | 07AAICP1128M1Z9 | Delhi | New Delhi | IND67 | INV67 |
| 2 | radium_mumbai | Radium - Mumbai | 27AAICP1128M1Z7 | Maharashtra | Mumbai | INM67 | NULL |
| 3 | radium_up | Radium - UP | 09AAICP1128M1Z5 | Uttar Pradesh | Patna | INU67 | NULL |
| 4 | radium_bihar | Radium - Bihar | 07AAICP1128M1Z9 | Bihar | Patna | NULL | NULL |
| 5 | radium_chennai | Radium -Chennai | 33AAICP1128M1ZE | Tamil Nadu | Chennai | INT67 | NULL |
| 6 | delhi_wharehouse | Radium-Delhi-Govindpuri | (empty) | Delhi | New Delhi | NULL | NULL |

Invoice/RD sequence numbers were read and are **not repeated here** to avoid implying they should be copied into Desk.

---

## Safety record

| Action | Done? |
|--------|-------|
| INSERT/UPDATE/DELETE on `radiumbox_prod` | No |
| Admin / radiumbox.com code changes | No |
| Desk production schema/data changes | No (`SHOW TABLES` only) |
| DNS / Cloudflare / deploy | No |
| Serial numbers altered | No |
| Full serial file export | No |
