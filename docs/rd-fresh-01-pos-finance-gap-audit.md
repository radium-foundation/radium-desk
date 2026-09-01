# RD-FRESH-01 — POS / Finance final gap audit

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-01-09-10  
**Date:** 2026-09-01  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Model:** Grok 4.6 (20-point correctness audit with possible fixes)  
**Canvas:** [`rd-fresh-01-pos-finance-gap-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-finance-gap-audit.canvas.tsx)  
**Compared against:** verified Admin/POS behaviour in [`rd-fresh-01-inventory-opening-field-matrix.md`](rd-fresh-01-inventory-opening-field-matrix.md) and [`rd-fresh-01-inventory-pos-foundation.md`](rd-fresh-01-inventory-pos-foundation.md). Admin tree was read-only (`PosController`, `Order/pos/script.blade.php`).

**Inventory / serials imported:** **NO**  
**`radiumbox_prod` modified:** **NO**  
**Desk production modified:** **NO**  
**Deployed:** **NO**

Classification: **VERIFIED** = Desk code + tests match the documented Admin core path (or an already-accepted Desk formula). **FIXED** = a real correctness defect found and corrected in this ticket. **GAP** = documented intentional difference (do not clone Admin extras). **UNKNOWN** = not re-raced on InnoDB in this ticket.

---

## Verdict

Desk POS and Finance already cover the **verified Admin retail core**: SKU search, variant/child SKU, serialized vs quantity, serial pick, qty, walk-in customer, line GST on exclusive price, header discount after line tax, multi-item cart, payment method, internal invoice, atomic complete, idempotent retry, cancel/return restock, branch and permission isolation, fail-closed finance, duplicate-submit collapse.

Two **real correctness defects** were found and fixed:

1. A parent SKU with variants could be completed without `variant_id`. For serialized variants that would consume a child serial while the sale/invoice named only the parent. Complete now requires the child SKU (same rule as stock-in). Serial lock matches `variant_id` including null.
2. Sale show + printable invoice omitted the variant SKU/name, so a 1 m vs 2 m cable looked like two identical parent lines.

The uncommitted P-01-09-09 InnoDB **gap-lock** fix on missing unique idempotency/phone keys is part of this close-out (points 13, 19, 20).

GST e-invoice, TCS, wallet, shipping, coupons, and RD/AMC/OTG add-on lines were **not** implemented. P-01-09-12 posts an internal reversing `pos_sale` journal on cancel/return (Cash Book pattern). That is **not** a GST credit note / IRN.

---

## Git / safety

| Check | Result |
|-------|--------|
| Branch | `feat/rd-fresh-01-inventory-pos` tracking `origin/feat/rd-fresh-01-inventory-pos` |
| Worktree | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Admin / radiumbox_prod / Desk production | Not written |
| Opening-inventory workbook | Not imported or modified |

---

## 20-point matrix

Compared only to verified Admin/POS behaviour already documented in this repo. Admin extras that this project already rejected stay **GAP**.

| # | Check | Admin (verified) | Desk | Status | Gap / action |
|---|-------|------------------|------|--------|----------------|
| 1 | Product/SKU selection | Main listing dropdown, then model (`attribute_id=5`) | Counter search by SKU/name; stock qty for the selling branch | VERIFIED | No RD/AMC/OTG add-on picker (GAP, do not clone) |
| 2 | Variant selection | No variant table; model row **is** the SKU | Child SKUs on `inventory_product_variants`; counter lists each active variant | FIXED | Complete now requires `variant_id` when the product has active variants. Invoice prints `parent / child — name (variant)` |
| 3 | Serialized vs non-serialized | No flag; warehouse units are serial rows; qty>1 on add-on lines | `is_serialized` on the product; serial count must equal qty | VERIFIED | Desk is stricter; do not import colliding Admin serials |
| 4 | Serial selection | After order create; `is_sold='No'` + model + label | Available serials at the selling branch; reserved only if this sale consumes that hold | FIXED | Serial lock now rejects a variant serial sold as the parent (null `variant_id`) |
| 5 | Quantity handling | Line qty; serial count must match | Qty ≥ 1; serialized qty is locked to serials; insufficient stock fail-closed | VERIFIED | UI does not pre-check available qty; server rejects oversell |
| 6 | Customer selection/creation | Search email/phone; existing `users` required; address + GST | Phone lookup; find-or-create name+phone; email optional | VERIFIED | No address/GSTIN on the counter (GAP vs Admin form; not GST e-invoice) |
| 7 | GST calculation | Line tax = `qty × exclusive price × gst% / 100`; shipping 18% reverse GST | Same line formula from `inventory_products.gst_percentage`; header discount does **not** reduce tax | VERIFIED | No shipping reverse GST / TCS (GAP, do not clone) |
| 8 | Discount handling | Header `discount_cost` subtracted after line net (incl. tax) | Header after line tax; optional line discount before tax | VERIFIED | Line discount is extra vs Admin; shipping not cloned |
| 9 | Multi-item cart | `productid[]` / `modelid[]` lines | `lines[]` with mixed serial + quantity + variant | VERIFIED | None for the core path |
| 10 | Payment | Bank/cash/cheque/credit; optional wallet | Finance payment methods, else Cash/UPI/Card/Bank Transfer/Other + optional reference | VERIFIED | No wallet / split tender (GAP) |
| 11 | Finance posting | Admin accounting / invoice / TCS | 2-line `pos_sale` journal: cash or bank clearing Dr, revenue Cr; fail-closed inside the sale txn. Cancel/return posts a Cash Book-style reversing journal (`pos_sale:reverse:{sale}:{journal}`); original kept; handoff `reversed` | VERIFIED | No GST payable split. Reverse is **not** a GST credit note / IRN (GAP) |
| 12 | Invoice creation | GST invoice + e-invoice/IRN on `orders` | `INV-{branch}-{year}-{seq}` printable internal invoice | FIXED | Variant identity now printed. Still **not** a GST e-invoice; HSN not on the print (GAP) |
| 13 | Sale idempotency | Order saved first; serials later (double-submit can duplicate) | Unique `idempotency_key`; miss is **not** `FOR UPDATE` (InnoDB gap-lock); unique collision returns the same sale | VERIFIED | Branch row still serializes invoice numbers at one counter |
| 14 | Cancel | Rollback log; restock; credit note in Admin invoicing | Restores serial/qty; keeps invoice number; reversing journal fail-closed; `pos.cancel` only | VERIFIED | Internal GL reverse, not a GST credit note (GAP) |
| 15 | Return/restock | Manual stock status (Return / Replaced / …) | Sale-level return restores stock and reverses the posted journal; same permission as cancel | VERIFIED | No partial-line return (GAP) |
| 16 | Branch isolation | `radium_branch.slug` on the order | Hardware sees/mutates assigned branches only; `operate-all` for admin team | VERIFIED | Hardware must be assigned before go-live |
| 17 | Permission isolation | Admin roles | Admin: full inventory/POS/finance. Hardware: view/in/transfer/reserve/sell. Agent: none | VERIFIED | Re-seed permissions per environment before deploy |
| 18 | Hardware-user restrictions | Admin POS staff | No catalog, adjust, cancel, operate-all, or finance.view | VERIFIED | Cancel/return hidden without `pos.cancel` |
| 19 | Failure rollback | Order can exist without serials | One DB transaction: stock + invoice + finance; finance throw rolls inventory back | VERIFIED | Retry after rollback is a new attempt (key was never committed) |
| 20 | Duplicate-submit protection | Weak (order-before-serials) | Hidden UUID key + unique index + submit-lock on the counter button | VERIFIED | Backend still wins if JS is bypassed and the key is omitted |

Admin GST/discount source (`Order/pos/script.blade.php` `CalculateFinal`): line tax on exclusive amount, then `gtotal = line nets + shipping − discount_cost`. Desk with shipping=0 matches that: `total = subtotal − header_discount + tax`. Regression: `test_header_discount_does_not_reduce_line_gst` (₹100 + 18% GST − ₹10 header = **₹108**, tax still **₹18**).

---

## Defects fixed in this ticket

| Defect | Why it is wrong vs verified workflow | Fix |
|--------|--------------------------------------|-----|
| Parent SKU complete without child variant | Stock-in already requires a variant when the product has children. POS HTTP could omit it. A serialized variant serial could be sold as the parent; invoice would not name the child SKU. | `PosSaleService` requires `variant_id` when active variants exist. `lockAvailableSerialsForSale` matches variant including null. |
| Invoice/sale show hid the variant | Operator selects `OTG-QA-1M`; print showed only the parent name. Two variants of one parent were indistinguishable. | `InventorySaleLine::catalogLabel()` on sale show + invoice. |
| InnoDB gap-lock on missing unique key (P-01-09-09, uncommitted until this close-out) | `lockForUpdate` on a missing `idempotency_key` / phone deadlocked the other cashier (SQLSTATE 40001). | Lookup without `FOR UPDATE` on a miss; unique insert + catch; deadlock retry 5×. |

---

## Tests

sqlite `:memory:` PHPUnit (not production MySQL):

- `PosSaleServiceTest` — 17 tests including GST header-after-tax, variant required, serialized variant parent reject, serialized variant success + catalog label
- `InventoryStockServiceTest` — variant serial cannot be locked as the parent
- `InventoryPosOperationalWorkflowTest` — show + invoice assert `OTG-QA-1M`
- `InventoryPosAuthorizationTest`, `InventoryPosAccessTest`, `InventoryBranchIsolationTest`
- `InventoryPosMysqlConcurrencyTest` — host/database gate; five two-process cases skipped unless disposable `radium_desk_inventory_pos_test` is reachable (VERIFIED earlier on MariaDB 11.8.8)
- `tests/Feature/Finance/PosSaleJournalReversalTest.php` — cancel/return reverse, skipped posting, fail-closed missing journal, idempotent reverse
- `tests/Feature/Finance` — remaining ledger tests, including `JournalPostingServiceTest`

This gate: inventory/POS + finance reverse **59 passed, 5 skipped, 331 assertions** (`InventoryPosMysqlConcurrencyTest` InnoDB cases skip unless disposable `radium_desk_inventory_pos_test` is reachable; five cases VERIFIED earlier on MariaDB 11.8.8).

---

## Remaining gaps (do not implement here)

- GST e-invoice / IRN / e-way, TCS, wallet, shipping 18% reverse GST, coupons, RD/AMC/OTG add-on lines
- Customer address + GSTIN on the counter (column exists; UI does not collect it)
- HSN on the printable invoice
- GST credit note / IRN on cancel (Desk posts an internal reversing journal only)
- Partial return, split tender, consume-reservation from the counter
- Support `orders` / Customer 360 link from a POS serial
- Admin catalog/stock migration (physical-count workbook is a separate team)

---

## Risks / rollback

| Risk | Status |
|------|--------|
| Header discount after tax | Matches Admin `CalculateFinal` when shipping=0. Do not “fix” by taking GST after header discount. |
| Cancel reverse is not a GST credit note | Internal Cash Book reversing entry only. Do not issue as IRN. |
| Global unique serials | Stricter than Admin; colliding Admin labels cannot be imported as-is. |
| `phpunit.xml` sqlite `force="true"` | Stops local `.env` MySQL leaking into PHPUnit. Keep it. |

**Rollback:** revert the application commit on this branch. Schema rollback remains `php artisan migrate:rollback` for `2026_09_01_140000` then `2026_09_01_120000` (inventory/POS tables only). Existing `orders` and finance history are untouched. This ticket does not deploy.

---

## Close-out

| Item | Result |
|------|--------|
| Changed | Yes — variant required on complete; serial variant match; invoice/sale label; counter submit-lock; P-01-09-09 gap-lock; P-01-09-12 cancel/return GL reverse; tests; this report |
| Tested | Yes — inventory/POS + Finance reverse suites above |
| Committed | See chat close-out |
| Pushed | See chat close-out |
| Deployed | **NO** |
| Production verification | **NO** |
