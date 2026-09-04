# RD-FRESH-01 — Old Admin archive POS/inventory compatibility

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-06  
**Date:** 2026-09-04  
**Archive:** `/Users/ravi/Downloads/admin-radiumbox-com-files-2026-08-30.tar.gz`  
**Duplicate copy (same SHA-256):** `/Users/ravi/RadiumWebsites/Admin/admin-radiumbox-com-files-2026-08-30.tar.gz`  
**SHA-256:** `2bd2bad8abf6be762a5cf152bd7f93515366c5648591d714e1105ad976194f23`  
**Size:** 125 MB · dated 2026-08-31 on disk · contents dated as `admin.radiumbox.com` tree  
**Canvas:** [`rd-fresh-01-pos-production-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-production-readiness.canvas.tsx)

Read-only. The archive was listed and individual PHP/views were streamed to stdout (`tar -tzf` / `tarfile.extractfile`). The archive was **not** modified, extracted over the Admin tree, deleted, or renamed. `.env` inside the archive was **not** opened.

This archive is a **2026-08-30 application snapshot**, not current warehouse stock and not a live production database.

**Fresh production copy required?** **NO.** Physical-count Excel is the opening source of truth. This archive is enough to verify POS/inventory *rules*. A new AWS/Admin dump would only be needed if someone wanted to migrate historical `product_stock` rows — that is explicitly out of scope.

## What was inspected

| Path in archive | Role |
|-----------------|------|
| `app/Models/Ecom/Product/Stock.php` | `product_stock` fillable |
| `app/Models/Ecom/Product/Product.php` | Catalog fillable / `sku_code` / GST |
| `app/Http/Controllers/Ecom/Product/StockController.php` | Paste stock-in, Excel import, move, damage |
| `app/Imports/Ecom/ProductStock.php` | Excel `serial_no` + required `purchase_id` |
| `app/Http/Controllers/Ecom/Inventory/InventoryCron.php` | Stale `product_stock_report` |
| `app/Http/Controllers/Order/Pos/PosController.php` | Order-before-serials POS |
| `app/Http/Controllers/Order/Pos/SerialUpdateController.php` | Status / rollback |
| `resources/views/Order/pos/script.blade.php` | `CalculateFinal` GST |

No inventory schema migrations exist in the archive (Laravel default tables only). Branch data is `DB::table('radium_branch')`.

## Compatibility matrix

| Topic | Archive (VERIFIED) | Desk today | Compatibility | Action |
|-------|--------------------|------------|---------------|--------|
| Stock identity | One `product_stock` row per serial; qty = row count | Serial row or quantity balance | Intentional Desk split for accessories | Keep. Do not import Admin row-count as qty for serialized SKUs |
| SKU identity | `products.id` + `sku_code`; POS `modelid` = child `attribute_id=5` | Unique `inventory_products.sku` | Opening keys by SKU, not Admin numeric id | Keep. Numeric id `946` in the SKU column fail-closes |
| Serial uniqueness | `label` + `product_id` only | Global unique `serial_number` | Desk is stricter; Admin can collide across SKUs | Keep. Import rejects the same serial on two SKUs |
| Transfer | Clone: old `is_sold=Yes`, new row `status=New` | Relocate same row | Do not clone | Keep Desk |
| POS sale timing | Order saved first; serials later; partial failure possible | One transaction | Desk is safer | Keep Desk |
| Sale status | Sets `is_sold=Yes`; `status` often left `New` | `InventorySerialStatus::Sold` | Desk status is explicit | Keep Desk |
| Damage | Forces `is_sold=Yes` | Damaged serial, available qty unchanged | Compatible | Keep |
| Non-serialized warehouse qty | **Absent.** Every warehouse unit is a `label` | Template allows blank serial + qty | Accessories-only Desk path | Damaged qty still rejected — Admin had no such row |
| Condition | Overloaded `product_stock.status` (New/Old/Return/Replaced/Renew/Damage) | `new` / `used` / `refurbished` + stock status | Template mapping already defined | No new enum values |
| Branch | `radium_branch.slug` string on stock/order | `inventory_branches.code` | Suggested codes already on template | Do not auto-create; do not copy GSTIN |
| GST | Exclusive `selling_price` × `gst_percentage`; header discount after tax; shipping 18% reverse GST | Same line formula; no shipping/TCS | Match when shipping=0 | Do not clone shipping |
| Excel import | `serial_no`; unique = label+product; **requires existing PO** | Opening count; no PO required; fail-closed | Desk is the agreed Day-1 path | Do not copy PO gate |
| Report cache | `product_stock_report` (stale) | Live balances | Do not import report | Keep |
| RD/AMC/OTG | Extra POS order lines, not warehouse serials | Not implemented | Documented GAP | Do not invent |
| Rollback | Updates **all** `product_stock` rows with those labels to New | Sale-scoped restore | Admin can restock clones | Keep Desk |
| Legacy `products.id` | Required in Admin | Not stored on Desk SKU | Not needed for current POS | **No column added** |

## Legacy identifiers to remember later (do not migrate now)

| Legacy | Store on Desk now? | Why |
|--------|--------------------|-----|
| `products.id` | No | Opening and POS use SKU. Optional later if a SKU↔Admin id map is needed for RadiumBox Read |
| `products.sku_code` | Yes, as Desk SKU | Already the opening key |
| `product_stock.label` | Yes, as serial | Global unique; colliding Admin labels cannot be imported as-is |
| `product_stock.id` / log ids | No | Empty Desk ledger |
| `radium_branch.slug` | No | New codes (`DELHI-WH`, …) |
| `radium_branch.mycafe_password` | **Never** | Secret |
| Invoice / RD sequences | No | Would desync live Admin invoicing |

## Code changes this ticket (compatibility-driven)

- Preview/apply now emit a **reconciliation** breakdown (available serials / damaged serials / quantity units, by branch and SKU) so the later workbook apply can be checked without guessing.
- Tests: same serial on two SKUs fails (Admin would allow); Admin numeric product id is not a SKU; reconciliation counts valid rows only.

No new stock rules. No `damaged_qty` column. No `legacy_product_id` column.

## Isolation

AWS not accessed. Archive not altered. `radiumbox_prod` not queried. Completed Excel not imported. Statutory/shipping WIP not discarded.
