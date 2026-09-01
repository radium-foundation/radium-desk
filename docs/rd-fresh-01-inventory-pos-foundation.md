# RD-FRESH-01 — Inventory and POS foundation

**Project:** Radium Desk  
**Ticket:** RD-FRESH-01  
**Date:** 2026-09-01  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Canvas:** [`rd-fresh-01-inventory-pos-foundation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-inventory-pos-foundation.canvas.tsx)

This is the first implementation gate before migrating RadiumBox.com and rdservice.in operational inventory/POS into Desk. Admin remains a **read-only reference**. No Admin, RadiumBox, or production database was modified.

## Verdict

Desk previously had **no warehouse inventory engine and no POS**. Support `orders` remain customer/device identity records. This gate adds **one inventory engine** used by both Inventory and POS, inside the existing Desk login, behind new permissions.

POS does **not** create a second stock table or a separate application.

## Old Admin → Desk feature matrix

| Admin feature | Desk before | Desk after RD-FRESH-01 | Classification |
|---|---|---|---|
| Product catalog (SKU, HSN, GST %, price) | Support product names + device models only | `inventory_products` (+ optional link to `device_models`) | **VERIFIED MISSING → implemented (foundation)** |
| Variants / child SKUs | None | Optional `inventory_product_variants` | **PARTIALLY MOVED** (Admin parent/child + RD/AMC/OTG add-ons not cloned) |
| Brands / attributes / website split lists | None | Not implemented | **OBSOLETE for this gate** / remaining |
| Company branches (`radium_branch`) | None (dashboard “warehouse” was a queue alias) | `inventory_branches` | **VERIFIED MISSING → implemented** |
| Serial-per-unit stock (`product_stock`) | Order serial = device identity | `inventory_serials` — one row, one branch, unique serial | **VERIFIED MISSING → implemented (safer than Admin)** |
| Stock by branch report | None | Stock balances + serial list | **VERIFIED MISSING → implemented** |
| Batch / lot | None in Admin | Optional `batch_code` when product `tracks_batch` | **VERIFIED MISSING → implemented (minimal)** |
| Stock-in (paste serials / qty) | None | Inventory → Stock in | **VERIFIED MISSING → implemented** |
| Purchase-order-linked serial import | Admin Excel + `purchase_id` | Not implemented | **VERIFIED MISSING (remaining)** |
| Transfer / move stock | Admin clones serial row at destination | Desk **relocates the same serial**; ledger has source + destination | **VERIFIED MISSING → implemented (improved)** |
| Reservation | None in Admin | Serial reservation + release | **VERIFIED MISSING → implemented** |
| Adjustment with audit | Per-serial status + `product_stock_log` | Adjustment records + movement ledger | **VERIFIED MISSING → implemented** |
| POS counter sale | Admin form creates `ordertype=POS` | Desk POS counter; `inventory_sales` | **VERIFIED MISSING → implemented (foundation)** |
| Stock-out on sale | Admin: after order, assign serials later | Desk: complete sale assigns serials **and** deducts in one transaction | **VERIFIED MISSING → implemented (stricter)** |
| Serial assignment uniqueness | Application-only; duplicates after move | DB unique `serial_number` + `lockForUpdate` | **VERIFIED MISSING → implemented (stricter)** |
| Serial history / movement ledger | `product_stock_log` | `inventory_movements` append-only | **VERIFIED MISSING → implemented** |
| Customer select/create | Existing Admin user required | `inventory_customers` find-or-create by phone | **VERIFIED MISSING → implemented (POS customers)** |
| Payment method | Admin split payments / wallet | Single method; prefers `finance_payment_methods` | **PARTIALLY MOVED** |
| Discount / tax | Line GST + header discount + TCS | Line GST from product + line/header discount | **PARTIALLY MOVED** (no TCS) |
| Invoice generation | Per-branch GST invoice, e-invoice, e-way | `INV-{branch}-{year}-{seq}` printable invoice | **PARTIALLY MOVED** |
| Cancellation / return | Credit note; restock often manual | Cancel/return restores stock; no credit note / journal reverse | **PARTIALLY MOVED (foundation)** |
| GST e-invoice / IRN / e-way | Admin | Not implemented | **VERIFIED MISSING (remaining)** |
| Wallet / coupons / shipping | Admin POS | Not implemented | **VERIFIED MISSING (remaining)** |
| Support order identity (`orders`) | Already in Desk | Unchanged. POS does not write `orders.serial_number` | **VERIFIED MOVED** (support only) |
| Cashfree order payments | Already in Desk | Unchanged | **VERIFIED MOVED** |
| Finance GL hook | Order payment journals | `InventorySaleCompleted` → `PosSaleJournalService` (`pos_sale`) | **Implemented interface** |

## Implemented inventory capabilities

- Products/SKUs with GST, price, optional device-model link
- Optional variants
- Branches/locations
- Stock by branch (available + reserved)
- Serial-number tracking with **global uniqueness**
- Optional batch/lot on serialised stock-in
- Stock-in (serials or quantity)
- Branch-to-branch transfer with source and destination on the ledger; serial is **moved**, not cloned
- Serial reservation and release
- Adjustment with reason, actor, and movement rows
- Append-only movement ledger
- Serial history page
- Available / reserved / sold / damaged / returned statuses
- Permissions for view, products, branches, stock-in, transfer, adjust, reserve

## Implemented POS capabilities

- Same login; Inventory + POS sidebar modules
- Select product, quantity, and available serials
- Customer name/phone find-or-create
- Retail sale completion in one transaction
- Payment method + optional reference
- Discount and GST using product `gst_percentage`
- Invoice number + printable invoice
- Stock deduction and serial assignment on complete
- Sale history
- Cancel/return foundation (restores stock)
- Finance handoff event + journal post when ledger defaults exist

## Schema changes

New tables (migration `2026_09_01_120000_create_inventory_and_pos_foundation_tables`):

| Table | Role |
|---|---|
| `inventory_branches` | Locations; invoice sequence |
| `inventory_products` | SKUs |
| `inventory_product_variants` | Optional child SKUs |
| `inventory_serials` | One serial, one branch, unique `serial_number` |
| `inventory_stock_balances` | Available/reserved qty (keyed, unique `balance_key`) |
| `inventory_customers` | POS customers (unique phone) |
| `inventory_sales` / `inventory_sale_lines` / `inventory_sale_serials` | POS orders |
| `inventory_transfers` / `inventory_transfer_lines` | Audited moves |
| `inventory_reservations` / `inventory_reservation_lines` | Holds |
| `inventory_adjustments` / `inventory_adjustment_lines` | Audited corrections |
| `inventory_movements` | Append-only ledger |

Existing tables **not** altered: `orders`, `device_models`, finance ledger tables. `finance_journals.source_type` accepts new enum value `pos_sale` (string column; no finance migration).

**Down():** drops the new inventory/POS tables only.

## Permissions

| Permission | Purpose | Roles |
|---|---|---|
| `inventory.view` | Module gate | admin, operations_admin, superadmin, hardware_team |
| `inventory.products.manage` | SKUs | admin team |
| `inventory.branches.manage` | Branches | admin team |
| `inventory.stock.in` | Receive stock | admin team, hardware_team |
| `inventory.stock.transfer` | Transfer | admin team, hardware_team |
| `inventory.stock.adjust` | Adjust | admin team |
| `inventory.stock.reserve` | Reserve | admin team, hardware_team |
| `pos.view` | POS module | admin team, hardware_team |
| `pos.sell` | Complete a sale | admin team, hardware_team |
| `pos.cancel` | Cancel/return | admin team |

Support agents do **not** receive inventory/POS. Re-run `RolePermissionSeeder` (or equivalent permission sync) on each environment after deploy. No production seed of products or stock.

## Architecture notes

```
Desk login
├── Inventory  (stock, serials, transfers, movements, products, branches)
├── POS        (counter, sale history, invoice)
├── Orders     (existing support orders — unchanged)
├── Customers  (existing C360 / order identity — unchanged)
├── Finance    (existing GL + new pos_sale journal source)
└── Reports    (not built in this gate; stock/movement screens cover ops lists)
```

Support `orders.serial_number` remains **device identity for service**. Warehouse serials live in `inventory_serials`. A later ticket may link a POS sale to a support order (`inventory_sales.support_order_id` is reserved).

## Migration considerations

- Safe additive schema. No existing Desk rows rewritten.
- Do **not** import Admin `product_stock` in this ticket.
- After deploy, operators must create branches and SKUs, then stock-in. Empty inventory is expected.
- Re-seed roles/permissions so hardware/admin receive the new grants.
- Finance posting uses existing default cash/bank + revenue accounts; if those settings are missing, the sale still completes and handoff status is `skipped`.

## Remaining gaps

- Admin production catalog/stock/POS data migration
- Purchase orders and supplier serial import
- GST e-invoice, IRN, e-way bill, TCS, split payments, wallet, coupons, shipping
- Two-step in-transit transfer (current transfer completes immediately with two ledger lines)
- POS consuming an existing reservation in one click
- Auto-create / link support `orders` from POS serials for Customer 360 lookup
- Finance journal reversal on cancel/return
- Bulk B2B sales workflow (this gate is the foundation only)
- Full Reports module

## Risks / rollback

| Risk | Mitigation |
|---|---|
| Serial uniqueness is stricter than Admin (global, not per product) | Intentional; do not import colliding Admin serials without cleanup |
| Mixing support order serials with warehouse serials | Separate tables; documented |
| Permission seeder not run | Module 403s until seeded |
| Cancel does not reverse GL | Documented foundation; stock is restored |
| Empty production inventory after deploy | Expected; no silent stock move |

**Rollback:** `php artisan migrate:rollback` for this migration (drops only the new tables) and revert the application commit. Existing `orders` / finance data are untouched.

## Tests

PHPUnit (sqlite `:memory:`, `RefreshDatabase` — not production):

- `tests/Feature/Inventory/InventoryStockServiceTest.php`
- `tests/Feature/Inventory/PosSaleServiceTest.php`
- `tests/Feature/Inventory/InventoryPosAccessTest.php`
- `tests/Unit/Inventory/InventorySerialNumberTest.php`
- navigation coverage in `NavigationContextResolverTest`
