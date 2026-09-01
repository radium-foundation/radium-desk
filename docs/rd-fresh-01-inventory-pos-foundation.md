# RD-FRESH-01 — Inventory and POS foundation

**Project:** Radium Desk  
**Ticket:** RD-FRESH-01  
**Ledger:** RadiumDesk-P-01-09-04 (controlled operational test gate on `feat/rd-fresh-01-inventory-pos`; builds on P-01-09-03)  
**Date:** 2026-09-01  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Canvas:** [`rd-fresh-01-inventory-pos-foundation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-inventory-pos-foundation.canvas.tsx)

This is an implementation continuation, **not** a production deployment. Admin remains a **read-only reference**. No Admin, RadiumBox, rdservice.in, or production inventory was modified or imported.

## Verdict

Desk has **one inventory engine** used by Inventory and POS, inside the existing Desk login. The foundation (`006e5bf3`) added the schema and services. This continuation makes the workflow **operator-usable** and enforces branch isolation, atomic sales, finance fail-closed posting, sale idempotency, and a real counter UI.

POS does **not** create a second stock table or a separate application. Support `orders` are unchanged.

## Admin parity matrix

Admin architecture and tables were **not** copied. Desk keeps its own `inventory_*` engine.

Status: **VERIFIED** = exercised in this repo’s tests or read from Desk/Admin code. **INFERRED** = consistent with code but not re-opened in Admin this gate. **UNKNOWN** = not inspected here.

| Feature | Old Admin | Desk | Status | Gap | Required next action |
|---|---|---|---|---|---|
| One login | Separate Admin session | Same Desk session | VERIFIED | None | Re-seed permissions per environment |
| Branch / assignment | `radium_branch` | `inventory_branches` + `inventory_user_branches`; `operate-all` for admin team | VERIFIED | Hardware must be assigned | Assign operators on branch edit |
| SKU / variant | Admin catalog + RD/AMC/OTG add-ons | `inventory_products` + optional variants; GST % and unit price on product | VERIFIED | Admin add-on model not cloned | Create Desk SKUs; no Admin import yet |
| Serial inventory | `product_stock`; clone on move | One row, global unique serial, relocate on transfer | VERIFIED | Stricter than Admin | Do not import colliding serials |
| Stock-in | Paste serials / qty; PO Excel | Stock-in UI | VERIFIED | No PO import | Use stock-in |
| Transfer | Clones serial | Relocates same serial | VERIFIED | No in-transit hop | Accept immediate complete or add later |
| Reservation | Not in Admin | Reserve / release / consume-in-service | VERIFIED | Counter UI cannot pick a hold | UI follow-on |
| POS counter | `ordertype=POS` then serials later | Search, cart, branch banner, complete | VERIFIED | No split tender / hold-on-add | Use Retail counter |
| Price / tax | Line GST + header discount + shipping 18% reverse GST + TCS | Line GST from `gst_percentage`; header discount after line tax; no shipping/TCS | VERIFIED (Desk formula) | Shipping/TCS/wallet/coupons missing | Do not fake Admin extras |
| Invoice | GST + e-invoice / IRN | Internal `INV-{branch}-{year}-{seq}` printable; labelled not e-invoice | VERIFIED | Not GST-compliant | Do not issue as IRN |
| Finance post | Admin accounting | Cash/bank + revenue 2-line `pos_sale` journal; fail-closed | VERIFIED | No GST payable account; no cancel reverse | Do not invent accounts |
| Cancel / return | Credit note; restock often manual | Restores stock; invoice number kept; journal **not** reversed | VERIFIED | No GL reverse | Finance follow-on |
| Permissions | Admin roles | Admin: full inventory/POS/finance. Hardware: view/in/transfer/reserve/sell. Agent: none | VERIFIED | No dedicated serial.manage or invoice.view | Keep current map |
| Concurrency | App checks | `lockForUpdate` + unique serial | VERIFIED (sqlite sequential); UNKNOWN (InnoDB interleaving on this machine) | mysqld not listening locally | Provide `radium_desk_inventory_pos_test` MySQL |
| Support orders | POS wrote orders | POS does not write `orders.serial_number` | VERIFIED | No C360 link | Future ticket |
| Admin stock migration | N/A | Not imported | VERIFIED | Entire Admin stock still in Admin | Later phase |

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
- Permissions for view, products, branches, stock-in, transfer, adjust, reserve, **operate-all-branches**
- User-to-branch assignment (`inventory_user_branches`) so hardware cannot see or mutate other locations

## Implemented POS capabilities

- Same login; Inventory + POS sidebar modules
- Operator counter: visible branch context, product/SKU search, serial search, cart, live totals, customer lookup, payment, complete
- Customer name/phone find-or-create
- Retail sale completion in one transaction (stock, invoice number, finance post)
- Idempotent complete via `inventory_sales.idempotency_key`
- Payment method + optional reference
- Discount and GST using product `gst_percentage` (header discount after line tax)
- Invoice number + printable **internal** invoice (not GST e-invoice)
- Stock deduction and serial assignment on complete
- Sale history scoped to allowed branches
- Cancel/return restores stock (no GL reverse)
- Finance handoff **inside** the sale transaction when ledger posting is enabled

## Schema changes

Foundation tables (migration `2026_09_01_120000_create_inventory_and_pos_foundation_tables`):

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

Continuation (migration `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency`):

| Change | Role |
|---|---|
| `inventory_user_branches` | `user_id` + `branch_id` unique; FK to `users` and `inventory_branches`, cascade delete |
| `inventory_sales.idempotency_key` | Nullable unique string (80); concurrent retries return the same sale |

Existing tables **not** altered: `orders`, `device_models`, finance ledger tables. `finance_journals.source_type` accepts `pos_sale` (string column; no finance migration).

**Down():** 140000 drops `idempotency_key` and `inventory_user_branches`; 120000 drops the foundation inventory/POS tables only.

## Branch model

- Admin team receives `inventory.branches.operate-all` and can operate every branch without an assignment row.
- Hardware (and any user without operate-all) only sees and mutates assigned branches.
- Transfers require access to **both** from and to.
- Unassigned operators see a clear warning; stock/POS lists are empty rather than leaking other locations.
- Assign operators on Inventory → Branches → edit.

## Serial model

- One serial = one physical unit = one row.
- Global unique `serial_number`. Cannot exist at two branches. Transfer relocates `branch_id`.
- Sale requires status Available at the selling branch, or Reserved **only** when this sale consumes that reservation.
- Cannot sell the same serial twice (`lockForUpdate`, sorted lock order).
- Cancel/return restores Available at the selling branch.
- Movements remain append-only.

## Transaction boundaries

A POS complete is one DB transaction:

1. Idempotency lookup (`lockForUpdate` on existing key)
2. Lock branch (invoice sequence)
3. Create sale + lines
4. Lock serials / deduct quantity
5. Assign invoice number `INV-{branch}-{year}-{seq}`
6. Consume reservation if provided
7. `PosSaleJournalService::postForSale(..., failClosed: true)`
8. Dispatch `InventorySaleCompleted` (listener no-ops if already Posted/Skipped)

If finance posting is **enabled** and cash/bank or revenue accounts are missing, the service throws and **the whole sale rolls back** (serials stay available). If posting is **disabled**, handoff is `skipped` and the sale still completes.

Unique `idempotency_key` collisions after commit return the existing sale.

Cancel/return is a separate transaction that restores stock only. **No journal reverse** (not invented).

## Invoice / finance boundary

- Printable internal invoice only. **Not** GST e-invoice, IRN, or e-way.
- Do not generate real production invoices or POS sales during development against production data.
- Journal source `pos_sale:{sale_id}` is idempotent in Finance.
- Existing finance history is not rewritten.

## Permissions

| Permission | Purpose | Roles |
|---|---|---|
| `inventory.view` | Module gate | admin, operations_admin, superadmin, hardware_team |
| `inventory.products.manage` | SKUs | admin team |
| `inventory.branches.manage` | Branches + operator assignment | admin team |
| `inventory.branches.operate-all` | Skip per-branch assignment | admin team |
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
├── POS        (counter with search/cart, sale history, invoice)
├── Orders     (existing support orders — unchanged)
├── Customers  (existing C360 / order identity — unchanged)
├── Finance    (existing GL + pos_sale journal inside the sale transaction)
└── Reports    (not built; stock/movement screens cover ops lists)
```

Support `orders.serial_number` remains **device identity for service**. Warehouse serials live in `inventory_serials`. A later ticket may link a POS sale to a support order (`inventory_sales.support_order_id` is reserved).

## Migration considerations

- Safe additive schema. No existing Desk rows rewritten.
- Do **not** import Admin `product_stock` in this ticket.
- After deploy, create branches, assign hardware users, create SKUs, then stock-in. Empty inventory is expected.
- Re-seed roles/permissions so hardware/admin receive operate-all vs assignment grants.
- When ledger posting is enabled, configure default cash/bank + revenue **before** taking live POS sales, or completes will fail closed.

## Controlled operational test (P-01-09-04)

This gate used **sqlite `:memory:` PHPUnit** as the controlled environment. It did **not** write to `radium_desk_local`, Admin, RadiumBox, or production. `.env` points at `DB_DATABASE=radium_desk_local` on `127.0.0.1:3306`, but **mysqld was not listening** (PDO 2002). No local app server was running for a real browser session.

### Test setup

- `RolePermissionSeeder` (idempotent `findOrCreate` + `syncPermissions`)
- `FinanceMasterDataSeeder` / chart of accounts: cash `1000`, bank clearing `1100`, revenue `4000`, `ledger_posting_enabled=1`
- Controlled branches `TSTA` / `TSTB`, hardware assigned only to `TSTA`
- Catalog: serialized `MFS110-QA` (₹2500, GST 18%), non-serialized `OTG-QA` with variant `OTG-QA-1M` (₹40)

POS does **not** require `finance.view`. Hardware must not be given finance rights. Journal posting is an internal hook.

### Serial lifecycle (VERIFIED)

stock-in → Available → reserve → Reserved → release → Available → sell → Sold. Transfer relocates the same row. Duplicate serial rejected. Sold serial cannot be sold again. Branch A cannot sell a serial that lives on Branch B.

### POS lifecycle and tax (VERIFIED)

Desk formula, not Admin shipping/TCS: line tax = `(unit_price * qty - line_discount) * gst% / 100`; header discount applied after line tax.

QA sale: scanner 2500 + variant 40×2 = subtotal 2580; header discount 10; tax 464.40; total **3034.40**; cash journal balanced; invoice `INV-TSTA-…`; serial `QA-SN-001` sold.

### Invoice behavior (VERIFIED)

Printable internal invoice remains after cancel. Page states it is **not** a GST e-invoice / IRN. Hardware can open invoices for assigned-branch sales; cancel button is hidden without `pos.cancel`.

### Cancellation / return / finance (VERIFIED)

Cancel/return restore serial or quantity and write movement rows. `invoice_number` and `finance_journal_id` stay. **No reverse journal** is created. Do not invent GL reversal.

### Idempotency / failure safety (VERIFIED)

Same `idempotency_key` returns the existing sale and does not resell the serial. Finance throw inside `completeSale` rolls inventory back (`PosSaleServiceTest`). Retry after rollback is a new attempt because the rolled-back key does not exist.

### Concurrency result (BLOCKER on this machine)

`InventoryPosMysqlConcurrencyTest` is skipped unless `INVENTORY_POS_MYSQL_TEST=1` and database name is exactly `radium_desk_inventory_pos_test`. Starting or migrating production MySQL was refused. sqlite sequential double-sell is covered; InnoDB two-counter interleaving is **UNKNOWN** until that throwaway schema exists.

### Browser QA result

No IDE browser tools and no `artisan serve` / port 80/8000 listener. Operator UI was exercised as HTTP feature tests (counter labels, search JSON, invoice HTML, permission-hidden cancel). Real click/JS layout QA is still outstanding.

### Production-readiness blockers

1. Re-seed permissions; assign hardware to branches
2. Empty catalog until SKUs are created in Desk (no Admin import)
3. Ledger cash/bank + revenue must exist before live POS (fail-closed)
4. Invoice is not GST e-invoice
5. No GL reverse on cancel/return
6. InnoDB concurrent serial sale not proven here
7. Live browser QA not run
8. No production inventory migration

## Remaining gaps / next migration phase

- Admin production catalog/stock/POS data migration (not this ticket)
- Purchase orders and supplier serial import
- GST e-invoice, IRN, e-way bill, TCS, split payments, wallet, coupons, shipping
- Two-step in-transit transfer
- Counter UI: consume an existing reservation in one click
- Auto-create / link support `orders` from POS serials for Customer 360
- Finance journal reversal on cancel/return (do not invent)
- Bulk B2B sales workflow
- Full Reports module
- sqlite PHPUnit cannot prove true MySQL concurrent interleaving; production relies on `lockForUpdate`

## Risks / rollback

| Risk | Mitigation |
|---|---|
| Serial uniqueness is stricter than Admin (global, not per product) | Intentional; do not import colliding Admin serials without cleanup |
| Mixing support order serials with warehouse serials | Separate tables; documented |
| Permission seeder not run | Module 403s until seeded |
| Hardware unassigned | Empty branch lists + warning; no silent all-branch access |
| Ledger posting on without accounts | Sale rolls back; stock not taken |
| Cancel does not reverse GL | Documented; stock is restored |
| Empty production inventory after deploy | Expected; no silent stock move |

**Rollback:** `php artisan migrate:rollback` for `2026_09_01_140000` then `2026_09_01_120000` (drops only the new inventory/POS tables and assignment/idempotency additions) and revert the application commits. Existing `orders` / finance data are untouched.

## Tests

PHPUnit (sqlite `:memory:`, `RefreshDatabase` — not production MySQL):

- `tests/Feature/Inventory/InventoryStockServiceTest.php`
- `tests/Feature/Inventory/PosSaleServiceTest.php`
- `tests/Feature/Inventory/InventoryPosAccessTest.php`
- `tests/Feature/Inventory/InventoryBranchIsolationTest.php`
- `tests/Feature/Inventory/InventoryPosAuthorizationTest.php`
- `tests/Feature/Inventory/InventoryPosOperationalWorkflowTest.php`
- `tests/Feature/Inventory/InventoryPosMysqlConcurrencyTest.php` (skipped without safe MySQL)
- `tests/Unit/Inventory/InventorySerialNumberTest.php`
- navigation coverage in `NavigationContextResolverTest`
