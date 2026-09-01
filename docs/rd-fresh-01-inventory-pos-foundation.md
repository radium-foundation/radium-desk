# RD-FRESH-01 — Inventory and POS foundation

**Project:** Radium Desk  
**Ticket:** RD-FRESH-01  
**Ledger:** RadiumDesk-P-01-09-12 (POS cancel/return finance reverse on `feat/rd-fresh-01-inventory-pos`; builds on P-01-09-09)  
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
| Finance post | Admin accounting | Cash/bank + revenue 2-line `pos_sale` journal; fail-closed | VERIFIED | No GST payable account | Do not invent GST accounts |
| Cancel / return | Credit note; restock often manual | Restores stock; invoice number kept; reversing `pos_sale` journal (Cash Book pattern); not a GST credit note | VERIFIED | No IRN / GST credit note | Do not invent e-invoice credit notes |
| Permissions | Admin roles | Admin: full inventory/POS/finance. Hardware: view/in/transfer/reserve/sell. Agent: none | VERIFIED | No dedicated serial.manage or invoice.view | Keep current map |
| Concurrency | App checks | `lockForUpdate` on existing rows + unique serial/idempotency; two-process MariaDB worker test | VERIFIED (sqlite sequential + five InnoDB two-connection cases on MariaDB 11.8.8) | Branch row still serializes invoice numbers per counter | Accept for 1–2 cashiers; do not gap-lock missing unique keys |
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
- Cancel/return restores stock and posts a reversing finance journal when the sale was posted (original journal kept; not a GST credit note)
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

1. Idempotency lookup (no `FOR UPDATE` on a missing unique key)
2. Lock branch (invoice sequence)
3. Create sale + lines
4. Lock serials / deduct quantity
5. Assign invoice number `INV-{branch}-{year}-{seq}`
6. Consume reservation if provided
7. `PosSaleJournalService::postForSale(..., failClosed: true)`
8. Dispatch `InventorySaleCompleted` (listener no-ops if already Posted/Skipped/Reversed)

If finance posting is **enabled** and cash/bank or revenue accounts are missing, the service throws and **the whole sale rolls back** (serials stay available). If posting is **disabled**, handoff is `skipped` and the sale still completes.

Unique `idempotency_key` collisions after commit return the existing sale.

Cancel/return is a separate transaction: restore stock, then `PosSaleJournalService::reverseForSale(..., failClosed: true)` using the Cash Book reversing-entry pattern. Original `finance_journal_id` is kept. Handoff becomes `reversed`. Missing original journal fails closed (stock not restored). Skipped sales are a finance no-op. **Not** a GST credit note / IRN.

## Invoice / finance boundary

- Printable internal invoice only. **Not** GST e-invoice, IRN, or e-way.
- Do not generate real production invoices or POS sales during development against production data.
- Journal source `pos_sale:{sale_id}` is idempotent in Finance. Cancel/return posts `pos_sale:reverse:{sale_id}:{journal_id}` and does not rewrite the original journal.
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

Support agents do **not** receive inventory/POS.

Later production permission sync (do **not** run against production from this ticket):

```bash
php artisan db:seed --class=RolePermissionSeeder --no-interaction
```

The seeder is idempotent (`Permission::findOrCreate`, `Role::findOrCreate`, `syncPermissions`). After seeding, assign hardware users on Inventory → Branches → edit. Hardware without an assignment and without `inventory.branches.operate-all` sees empty lists plus the warning. POS/inventory mutation is branch-scoped. No production seed of products or stock.

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

Cancel/return restore serial or quantity and write movement rows. `invoice_number` and original `finance_journal_id` stay. A reversing `pos_sale` journal is posted (Cash Book debit/credit swap). Handoff becomes `reversed`. Not a GST credit note.

### Idempotency / failure safety (VERIFIED)

Same `idempotency_key` returns the existing sale and does not resell the serial. Concurrent duplicate keys on two MariaDB connections also return one sale (P-01-09-09). Finance throw inside `completeSale` rolls inventory back (`PosSaleServiceTest`). Retry after rollback is a new attempt because the rolled-back key does not exist. Do **not** `lockForUpdate` a missing unique idempotency key — that gap-locks InnoDB and deadlocks the other counter.

### Concurrency result (P-01-09-04)

sqlite sequential double-sell is covered. InnoDB two-counter interleaving was **UNKNOWN** here; **VERIFIED** in P-01-09-09.

### Browser QA result (P-01-09-04)

HTTP feature tests only. Real click/JS layout QA was outstanding.

### Production-readiness blockers (superseded in part by P-01-09-05)

See P-01-09-05 below.

## Verification gate (P-01-09-05)

Not a production deployment. Production data/DB were not touched. BonVoice branch was not modified. `radium_desk_local` was not migrated.

### MySQL environment

| Check | Result |
|---|---|
| Host | `127.0.0.1:3306` — nothing listening |
| brew `mysql` / `mariadb@11.8` | status `none` (not started) |
| PDO | `SQLSTATE[HY000] [2002] Connection refused` |
| `.env` `DB_DATABASE` | `radium_desk_local` — **not used** |
| Created `radium_desk_inventory_pos_test` | No |
| Dedicated test user | No |
| Production MySQL | Not contacted |

Local mysqld was **not** started or installed to satisfy this ticket.

**MySQL test environment unavailable → UNKNOWN/BLOCKER**

When a genuine local server exists later:

1. Create only database `radium_desk_inventory_pos_test` on `127.0.0.1` / `localhost`
2. Create a dedicated user granted only on that database
3. Run:

```bash
INVENTORY_POS_MYSQL_HOST=127.0.0.1 \
INVENTORY_POS_MYSQL_DATABASE=radium_desk_inventory_pos_test \
INVENTORY_POS_MYSQL_USERNAME=… \
INVENTORY_POS_MYSQL_PASSWORD=… \
php artisan test --filter=InventoryPosMysqlConcurrencyTest
```

The harness refuses any other host or database name, uses two PHP processes with independent connections, and `migrate:fresh` only after `select database()` returns the allowlisted name. Credentials are not recorded here.

### InnoDB concurrency result

**UNKNOWN** at P-01-09-05 (no local InnoDB server). **Superseded by P-01-09-09: VERIFIED.**

The test is no longer a skip-only gate. When the throwaway DB is reachable it:

- seeds one contended serial and two independent serials
- starts two workers, waits for a ready barrier, then releases both
- same serial: exactly one sale, one sold serial, one sale movement, one invoice, one `pos_sale` journal, no negative stock; the loser fails closed
- different serials: both complete on distinct connection IDs

sqlite sequential coverage remains **VERIFIED**.

### Browser QA result

**VERIFIED** on a disposable local sqlite file `database/inventory-pos-browser-qa.sqlite` (gitignored). PHP built-in server bound to `127.0.0.1:8765` only. Google Chrome (headless, system binary) drove the operator path. `.env` MySQL was not used (`artisan serve` children dropped env overrides; a local router forced sqlite).

Workflow exercised:

LOGIN → product list → serialized stock-in → variant quantity stock-in → serial list → transfer QAA→QAB → reserve → release → POS branch banner → product/serial search → multi-item cart → live totals → payment Cash → complete → invoice (internal, not e-invoice) → sale history → cancel/return restock

Serialized + non-serialized (variant `OTG-BROWSER-1M`). Live totals matched the verified Desk formula: subtotal 2580.00, tax 464.40, header discount 10, total **3034.40**. After cancel: invoice `INV-QAA-2026-00001` kept, finance handoff **posted**, serial `QA-BR-001` restored to Available. No page JavaScript exceptions. Root `/favicon.ico` 404 on the standalone invoice page is unrelated chrome, not an inventory defect.

Hardware assignment was not click-tested in Chrome (admin `operate-all` was used so cancel was visible). Hardware-without-assignment remains **VERIFIED** in `InventoryBranchIsolationTest`.

### Defects found / fixed

Stock-in UI accepted quantity on a parent SKU that has variants, while the POS counter only adds those child SKUs. Completing a cart with `OTG-BROWSER-1M` then failed: “Insufficient stock for OTG-BROWSER at QAA.”

Fix: stock-in shows a variant select; when the product has active variants, `variant_id` is required and must belong to the product. Regression: `test_stock_in_form_exposes_variant_select_and_requires_it_when_product_has_variants`.

### Permission readiness

Ready to seed later; not seeded on production.

| Role | Inventory / POS |
|---|---|
| admin / operations_admin / superadmin | Full inventory + POS + `inventory.branches.operate-all` + finance.view |
| hardware_team | view, stock-in, transfer, reserve, pos.view, pos.sell. No catalog, adjust, cancel, operate-all, finance |
| agent | none |

Command for a future environment: `php artisan db:seed --class=RolePermissionSeeder --no-interaction`. Then assign hardware on branch edit. Branch assignment (or operate-all) is required for POS/inventory access.

### Finance readiness

**VERIFIED** (sqlite tests + browser QA sale). Existing accounts only: cash `1000`, bank clearing `1100`, revenue `4000`. Ledger posting enabled in `FinanceMasterDataSeeder`. Missing cash/bank or revenue fails closed and rolls the sale back (`PosSaleServiceTest`). Browser QA sale posted one `pos_sale` journal at 3034.40. P-01-09-12: cancel/return posts a reversing journal; original journal kept; cash/revenue net to the pre-sale balance.

### Production-readiness blockers

1. Re-seed permissions; assign hardware to branches
2. Empty catalog until SKUs are created in Desk (no Admin import)
3. Ledger cash/bank + revenue must exist before live POS (fail-closed)
4. Invoice is not GST e-invoice (internal `INV-` only; cancel is not a GST credit note)
5. InnoDB concurrent serial sale **VERIFIED** on disposable MariaDB 11.8.8 (P-01-09-09); production still not cut over
6. No production inventory migration
7. Stock-in of a parent-with-variants now requires selecting the child SKU (operators must use the new field)

## Verification gate (P-01-09-12)

Not a production deployment. Opening-inventory Excel was not opened or modified. Admin, radiumbox_prod, Desk production, and inventory import were not touched.

Highest remaining **non-inventory** Day-1 blocker after P-01-09-09: a completed POS sale posted cash/bank + revenue, but cancel/return only restored stock. Cash-in-hand and revenue stayed overstated.

Admin parity used: Admin issues a credit note and restock is often manual (**VERIFIED** in the matrix). Desk does **not** invent GST e-invoice / IRN credit notes. Desk posts a reversing ledger entry using the already-shipped Cash Book pattern (`CashBookEntryService::reverseCurrentJournal`): append-only, original journal kept, debit/credit swapped, unique idempotency key.

| Check | Result |
|---|---|
| Prompt ID | RadiumDesk-P-01-09-12 |
| Original `pos_sale` journal | Kept on `inventory_sales.finance_journal_id` |
| Reverse journal | `pos_sale:reverse:{sale_id}:{journal_id}`; source_type still `pos_sale` |
| Handoff | `reversed` when original was posted; `skipped` stays skipped |
| Fail-closed | Missing original journal aborts cancel; serial stays Sold |
| GST credit note / IRN / TCS / wallet | Not implemented (not invented) |
| Inventory import / workbook / radiumbox_prod | Untouched |

## Verification gate (P-01-09-09)

Not a production deployment. Opening-inventory Excel was not opened or modified. Admin, radiumbox_prod, `radium_desk_local`, and Desk production were not contacted.

### Environment

| Check | Result |
|---|---|
| Prompt ID | RadiumDesk-P-01-09-09 (unused before this gate) |
| Branch / HEAD | `feat/rd-fresh-01-inventory-pos` @ `7d69a528` plus this gate’s uncommitted work |
| brew services | `mysql` and `mariadb@11.8` left `none` (not started) |
| Existing Homebrew datadir `/opt/homebrew/var/mysql` | **Not started.** Contains `radium_desk_local`, `radiumbox`, and other local schemas — never opened |
| Listener 3306 | None for the whole gate |
| Disposable engine | Homebrew `mariadb@11.8` binary **11.8.8-MariaDB**, already installed; `--no-defaults` so a MySQL `my.cnf` could not leak `mysqlx-bind-address` |
| Bind | `127.0.0.1:33118` only; datadir `/tmp/radium_desk_inventory_pos_test_mariadb` |
| Database created | **Only** `radium_desk_inventory_pos_test` |
| Test user | `rd_inv_pos_test@127.0.0.1` granted **only** on that database |
| Production MariaDB | 11.8.8 on KVM — **not contacted**. Version match is **VERIFIED** from prior docs, not from this connection |
| Harness | Two PHP processes, ready/go barrier, independent `connection_id()` values, Repeatable Read |

The disposable instance was dropped and the datadir removed after the tests.

### Two-connection cases

| # | Case | Result | Status |
|---|---|---|---|
| 1 | Same serialized SKU + same serial | Exactly one sale, one sold serial, one sale movement, one invoice, one `pos_sale` journal, no negative stock; loser fail-closed | VERIFIED |
| 2 | Same SKU, two different serials | Both complete; distinct sale ids, invoices, connection ids; both serials sold | VERIFIED |
| 3 | Same quantity SKU oversell (6+6 against 10) | Exactly one sale of 6, remainder 4, no negative balance | VERIFIED |
| 4 | Duplicate idempotency key | Both workers return success with the same `sale_id` / invoice; one sale row; serial sold once | VERIFIED |
| 5 | Independent SKUs (serial + quantity) | Both complete; serial sold; quantity 10→8; two invoices | VERIFIED |

PHPUnit: `InventoryPosMysqlConcurrencyTest` **OK (6 tests, 122 assertions)** including the host/database gate. sqlite inventory suite **43 tests** (PosSale 13 + other Feature 30) plus serial unit test **VERIFIED** after the deadlock fix.

### Defect found and fixed

First InnoDB run after the harness could actually race: independent sales and duplicate-key retries died with **SQLSTATE 40001 / 1213 Deadlock** on `SELECT * FROM inventory_branches … FOR UPDATE`.

Cause: `completeSale` did `lockForUpdate()` on a **missing** unique `idempotency_key`. On an empty unique index InnoDB gap-locks the supremum. The other connection then INSERT-waits on that gap while holding (or waiting for) the branch row → deadlock. `findOrCreateCustomer` had the same pattern on a missing unique phone.

Fix (does **not** weaken uniqueness, serial locks, or branch invoice locking):

- Look up an existing idempotency row **without** `FOR UPDATE` on a miss; uniqueness + `UniqueConstraintViolationException` still collapse duplicate keys to one sale.
- Create a new customer without gap-locking a missing phone; lock by primary key only after the row exists.
- Retry the completion transaction up to 5 times on InnoDB deadlock / lock-wait (`DB::transaction(..., 5)`).

Regression: the five two-connection cases above, plus existing sqlite `PosSaleServiceTest` idempotency and double-sell tests.

### High-volume counter POS (design review)

| Mechanism | Verdict | Status |
|---|---|---|
| Global unique `serial_number` + sorted `lockForUpdate` | Prevents double ownership of one serial | VERIFIED |
| Balance `lockForUpdate` + unsigned qty | Prevents quantity oversell | VERIFIED |
| Unique `idempotency_key` + insert race catch | Counter retry does not double-sell | VERIFIED |
| `inventory_branches` `lockForUpdate` for invoice sequence | Serializes all completes at one branch; safe; throughput ceiling of about one in-flight complete per branch | VERIFIED (behavior) / INFERRED (enough for 1–2 cashiers) |
| Deadlock retry | Required for InnoDB; cashiers must not see 1213 | VERIFIED |
| Sale↔cancel or sale↔transfer of the same serial at once | Not raced in this gate | UNKNOWN |
| Production `innodb_lock_wait_timeout` / live cashier latency | Not measured on KVM | UNKNOWN |

**Sufficient for intended high-volume hardware-counter POS (one or two cashiers per branch).** Not a warehouse-scale parallel checkout: invoice numbers stay on the branch row, so completes at the same branch queue. Do not remove that lock to “go faster.”

### Isolation (this gate)

| Surface | Touched |
|---|---|
| Disposable `radium_desk_inventory_pos_test` on 33118 | Yes — created, migrated, dropped |
| `radium_desk_local` / `radiumbox` / `radiumbox_prod` / production | No |
| Admin / other projects | No |
| Opening-inventory workbook | No |
| Deploy / `deskd` / git tag | No |

## Remaining gaps / next migration phase

- Admin production catalog/stock/POS data migration (not this ticket)
- Purchase orders and supplier serial import
- GST e-invoice, IRN, e-way bill, TCS, split payments, wallet, coupons, shipping
- Two-step in-transit transfer
- Counter UI: consume an existing reservation in one click
- Auto-create / link support `orders` from POS serials for Customer 360
- Bulk B2B sales workflow
- Full Reports module
- sqlite PHPUnit cannot prove true MySQL concurrent interleaving; P-01-09-09 ran the two-process harness against disposable `radium_desk_inventory_pos_test` on MariaDB 11.8.8

## Risks / rollback

| Risk | Mitigation |
|---|---|
| Serial uniqueness is stricter than Admin (global, not per product) | Intentional; do not import colliding Admin serials without cleanup |
| Mixing support order serials with warehouse serials | Separate tables; documented |
| Permission seeder not run | Module 403s until seeded |
| Hardware unassigned | Empty branch lists + warning; no silent all-branch access |
| Ledger posting on without accounts | Sale rolls back; stock not taken |
| Cancel reverse fails (missing original journal) | Fail-closed; stock stays sold |
| Cancel reverse is not a GST credit note | Internal GL only; do not issue as IRN |
| InnoDB gap lock on missing unique key | Do not `SELECT … FOR UPDATE` a missing idempotency/phone; unique insert + retry |

**Rollback:** `php artisan migrate:rollback` for `2026_09_01_140000` then `2026_09_01_120000` (drops only the new inventory/POS tables and assignment/idempotency additions) and revert the application commits. Existing `orders` / finance data are untouched.

## Tests

PHPUnit (sqlite `:memory:`, `RefreshDatabase` — not production MySQL):

- `tests/Feature/Inventory/InventoryStockServiceTest.php`
- `tests/Feature/Inventory/PosSaleServiceTest.php`
- `tests/Feature/Inventory/InventoryPosAccessTest.php`
- `tests/Feature/Inventory/InventoryBranchIsolationTest.php`
- `tests/Feature/Inventory/InventoryPosAuthorizationTest.php`
- `tests/Feature/Inventory/InventoryPosOperationalWorkflowTest.php`
- `tests/Feature/Finance/PosSaleJournalReversalTest.php`
- `tests/Feature/Inventory/InventoryPosMysqlConcurrencyTest.php` (gate always runs; five two-process InnoDB cases **VERIFIED** on disposable `radium_desk_inventory_pos_test`, MariaDB 11.8.8)
- `tests/Feature/Inventory/Support/mysql_pos_prepare.php` / `mysql_pos_sale_worker.php`
- `tests/Feature/Inventory/Support/inventory_pos_browser_qa.mjs` (local Chrome operator path; sqlite file only)
- `tests/Unit/Inventory/InventorySerialNumberTest.php`
- navigation coverage in `NavigationContextResolverTest`

## Verification gate (P-01-09-10)

POS / Finance final gap audit against verified Admin/POS behaviour only. Physical-count workbook, radiumbox_prod, and production were not touched. GST e-invoice, TCS, wallet, shipping, and coupons were not implemented.

**Defects fixed:** parent SKU with variants cannot complete without `variant_id`; serial lock matches variant including null; sale show + invoice print the child SKU via `catalogLabel()`. P-01-09-09 InnoDB gap-lock fix is included in this close-out.

Admin `CalculateFinal` (shipping=0) remains the Desk tax formula: header discount after line GST. Regression: ₹100 + 18% − ₹10 header = ₹108, tax still ₹18.

Full 20-point matrix: [`rd-fresh-01-pos-finance-gap-audit.md`](rd-fresh-01-pos-finance-gap-audit.md). Canvas: [`rd-fresh-01-pos-finance-gap-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-finance-gap-audit.canvas.tsx).
