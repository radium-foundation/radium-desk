# RD-FRESH-01 — Inventory and POS foundation

**Project:** Radium Desk  
**Ticket:** RD-FRESH-01  
**Ledger:** RadiumDesk-P-01-09-03 (continuation of the foundation in `006e5bf3`; the user prompt was labelled P-01-09-02, which is already used on the BonVoice branch)  
**Date:** 2026-09-01  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Canvas:** [`rd-fresh-01-inventory-pos-foundation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-inventory-pos-foundation.canvas.tsx)

This is an implementation continuation, **not** a production deployment. Admin remains a **read-only reference**. No Admin, RadiumBox, rdservice.in, or production inventory was modified or imported.

## Verdict

Desk has **one inventory engine** used by Inventory and POS, inside the existing Desk login. The foundation (`006e5bf3`) added the schema and services. This continuation makes the workflow **operator-usable** and enforces branch isolation, atomic sales, finance fail-closed posting, sale idempotency, and a real counter UI.

POS does **not** create a second stock table or a separate application. Support `orders` are unchanged.

## Admin parity matrix

Admin architecture and tables were **not** copied. Desk keeps its own `inventory_*` engine.

| Feature | Old Admin behavior | Current Desk implementation | Gap | Required action |
|---|---|---|---|---|
| One login | Separate Admin session | Same Desk session for inventory, POS, finance, branches | None for this gate | Re-seed permissions on each environment |
| Branch / location | `radium_branch`; operators not hard-isolated in Desk | `inventory_branches` + `inventory_user_branches`; `inventory.branches.operate-all` for admin team | Hardware must be assigned or they see no stock | Assign operators on branch edit |
| SKU / product / variant | Admin catalog + RD/AMC/OTG add-ons | `inventory_products` + optional variants | Admin add-on SKU model not cloned | Create Desk SKUs; do not import Admin catalog yet |
| Serial inventory | `product_stock`; serial can be cloned on move | One `inventory_serials` row, global unique `serial_number`, one branch | Stricter than Admin | Do not import colliding serials |
| Stock-in | Paste serials / qty; PO Excel | Stock-in UI (serials or qty) | No PO / supplier import | Use stock-in; PO is a later phase |
| Transfer | Clones serial at destination | Relocates the same serial; ledger has from + to | No two-step in-transit | Accept immediate complete, or add in-transit later |
| Adjustment | Per-serial status + log | Adjustment records + movement ledger | None for internal ops | Use Inventory → Adjust |
| Reservation | Not in Admin | Reserve / release; sale can consume matching reservation | Counter UI does not pick an existing hold in one click | Reserve then complete with reservation in service; UI follow-on |
| Available / reserved / sold | Mixed with order assignment | Balance columns + serial status | None | Use stock and serial screens |
| Movement ledger | `product_stock_log` | `inventory_movements` append-only | None | Use Movements / serial history |
| POS counter | Form creates `ordertype=POS` then serials later | Search, cart, totals, customer, payment, complete | No hold-on-add / split tender | Use Retail counter |
| Serial on sale | Assign after order | Must be available at the selling branch; reserved only if this sale consumes that reservation | Stricter than Admin | Train operators: scan available serials |
| Quantity products | Qty lines | Qty lines deduct available | None | — |
| Price / discount / tax | Line GST + header discount + shipping reverse-GST 18% + TCS | Line GST from product; line + header discount; header discount **after** line tax | No shipping, TCS, coupons, wallet | Do not fake those rules |
| Multi-item sale | Yes | Yes | None | — |
| Invoice | GST invoice + e-invoice / IRN / e-way | Internal `INV-{branch}-{year}-{seq}` printable only | **Not GST e-invoice compliant** | Document boundary; do not issue as IRN |
| Sale complete | Order first, stock later | One DB transaction: stock, sale, invoice number, finance post | None vs requirement | — |
| Cancel / return | Credit note; restock often manual | Restores serial/qty at selling branch | No credit note / GL reverse | Stock restore only until finance defines reverse journals |
| Finance posting | Admin accounting | `PosSaleJournalService` `pos_sale` inside the sale transaction; fail-closed if ledger posting is on and accounts missing | Cancel does not reverse GL | Do not invent reverse rules |
| Permissions | Admin roles | Desk permissions; hardware cannot adjust/cancel/manage products | None vs requirement | Re-seed roles |
| Branch isolation | Weak / application | Scoped lists + `requireBranchId` on mutations | Users with operate-all see every branch | Assign hardware; do not grant operate-all to counters |
| Concurrency | Application checks | `lockForUpdate` on serials (sorted) + unique serial | sqlite tests cannot prove MySQL interleaving | Rely on row locks in production MySQL |
| Support `orders` | POS wrote order rows | POS does **not** write `orders.serial_number`; `support_order_id` reserved | No C360 link from POS serial yet | Future linking ticket |
| Production catalog migration | N/A | Not imported | Entire Admin stock still in Admin | Later migration phase only |

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
- `tests/Unit/Inventory/InventorySerialNumberTest.php`
- navigation coverage in `NavigationContextResolverTest`
