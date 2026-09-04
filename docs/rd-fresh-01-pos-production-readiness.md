# RD-FRESH-01 — POS production readiness (P-04-09-16)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-16  
**Date:** 2026-09-04  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**HEAD before this ticket:** `8a256f86dd143da4f3a3bf6bba70b2eb86740ca3`  
**Canvas:** [`rd-fresh-01-pos-production-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-production-readiness.canvas.tsx)

Companions: [`rd-fresh-01-opening-inventory-import.md`](rd-fresh-01-opening-inventory-import.md) · [`rd-fresh-01-opening-inventory-preview.md`](rd-fresh-01-opening-inventory-preview.md) · [`rd-fresh-01-opening-inventory-desk-existence.md`](rd-fresh-01-opening-inventory-desk-existence.md).

## Verdict

**BLOCKED** — not LIVE, not ready for deployment.

Owner-supplied `DELHI-RETAIL` and `MUMBAI` now exist as active rows on production `radium_desk.inventory_branches`. The POS application itself is **not deployed**. Opening stock was **not imported**. A `deskd` of this worktree would ship uncommitted statutory/shipping/channel-ingest WIP onto live Desk.

## Prompt ID

**RadiumDesk-P-04-09-16**

## Current state (re-verified this ticket)

| Item | Value | Class |
|------|--------|-------|
| Local path | `/Users/ravi/RadiumWebsites/radium-desk` | VERIFIED |
| Branch | `feat/rd-fresh-01-inventory-pos` (ahead of origin by 5) | VERIFIED |
| Local HEAD | `8a256f86` | VERIFIED |
| Worktree | Dirty: statutory, shipping, channel-ingest, and related views/tests | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Workbook | `/Users/ravi/Downloads/rd-fresh-01-opening-inventory-template.xlsx` SHA-256 `6048543e167f5ce7fb11265a4fa7f5c723dc26e6a37c89ca647280ebb81733f4` | VERIFIED |
| Production host | `srv1910783` / `187.127.129.16` · `/var/www/radium-desk` | VERIFIED |
| Production app | `APP_ENV=production` · `APP_DEBUG=false` · `APP_URL=https://desk.radiumbox.com` | VERIFIED |
| Production DB | `radium_desk` @ `127.0.0.1:3306` | VERIFIED |
| Production git | **Absent** (rsync/`deskd` tree, not a checkout) | VERIFIED |
| Production release | Changelog head **4.0.65** · no `release.json` | VERIFIED |
| Login health | `https://desk.radiumbox.com/login` HTTP 200 | VERIFIED |
| Queue | `radium-desk-queue-worker` RUNNING | VERIFIED |
| Cache / session / queue | redis / file / redis | VERIFIED |
| AWS / rdservice.in / radiumbox_prod | Not contacted | VERIFIED |

### Production POS surface

| Surface | Production fact | Class |
|---------|-----------------|-------|
| Inventory foundation migrations | `2026_09_01_120000` and `2026_09_01_140000` are **on disk and applied** | VERIFIED |
| Opening-import migration | **Absent** (`inventory_opening_import_*` tables do not exist) | VERIFIED |
| POS PHP | No `app/Services/Inventory`, no `Pos` controllers, no inventory models, no `/pos` routes | VERIFIED |
| Opening-import service | **Absent** | VERIFIED |
| `inventory.*` / `pos.*` permissions | **0 rows** | VERIFIED |
| Finance CoA / settings | Cash `1000`, bank `1100`, revenue `4000`, posting enabled | VERIFIED |
| Payment methods | Cash, UPI, Bank Transfer, Cashfree, Other | VERIFIED |
| Cash book | 1 active drawer | VERIFIED |
| Bank book (`finance_bank_accounts`) | **0 rows** | VERIFIED |
| POS journals | Use CoA `1000`/`1100`, not the bank-book table | VERIFIED |

`deskd` / `tools/desk deploy` rsyncs the **local** tree, requires a **clean** worktree on **`main`**, then `migrate --force` + `RolePermissionSeeder`. That path is **not** safe from this branch.

## POS application audit (committed `8a256f86`)

**VERIFIED** = committed POS at that SHA. Dirty worktree files were not treated as POS.

| Area | Class | Result |
|------|-------|--------|
| Products / SKUs | VERIFIED | Desk-native `inventory_products`. Apply creates missing SKUs from SKU Master only |
| Variants | VERIFIED | Created from Variant SKU; foreign parent is blocking |
| Serialized / qty stock | VERIFIED | Unique serial; qty replay identity; damaged qty rejected |
| Branch isolation | VERIFIED | Hardware cannot operate another location |
| Opening import | VERIFIED | Never creates branches or copies workbook GSTIN |
| Sales | VERIFIED | Atomic complete; internal `INV-{branch}-{year}-{seq}` |
| Payments | VERIFIED + fix this ticket | Fail-closed without cash/bank + revenue. **Cashfree no longer matches cash via substring** |
| Cancel / return | VERIFIED | Restock + reversing journal; not a GST credit note; no partial return |
| Customer | VERIFIED | Name/phone required. Buyer GSTIN exists on model; committed counter does not require it |
| Receipt | VERIFIED | Internal only. Auto GST/IRN fail-closed off |
| Permissions | VERIFIED seeder | admin / operations_admin / superadmin get full inventory+POS; hardware gets sell/view/stock-in only |
| Concurrency | VERIFIED sqlite / UNKNOWN live InnoDB this host | Unique serial + sale idempotency key |
| Hardware / print / scanner | UNKNOWN | No production POS UI to verify |
| Reporting | VERIFIED gap | Internal POS only; GST reports are statutory (out of scope) |

### Verified application blockers still present

1. Payment method HTTP is an unbound string; empty `finance_payment_methods` falls back to labels including Card.
2. Branch schema has **no address / state / city / pincode**. Owner addresses cannot be stored without a new column (not added; POS sale path does not read them).
3. Complete never issues a GST tax invoice / IRN (intentional; statutory is a separate stream).
4. No CGST/SGST/IGST split on the internal receipt.
5. Post-commit journal listener is fail-open; in-transaction post is fail-closed.

### INFERRED / UNKNOWN

| Item | Class |
|------|-------|
| Live cashier latency on production MariaDB | UNKNOWN |
| Hardware users assigned to the new branches | UNKNOWN (no POS UI deployed) |
| Whether 4.0.65 operators expect POS in the nav | INFERRED no — routes absent |

## What this ticket changed

### Application (local only — not deployed)

`PosSaleJournalService::settlementAccount` now treats only the exact method `cash` as cash GL. Seeded `Cashfree` posts to bank clearing `1100` (that account’s seeder comment already says Cashfree/bank receipts). Covered by `PosSaleJournalReversalTest::test_cashfree_posts_to_bank_clearing_not_cash`.

### Production DB

Backup first: `/var/backups/radium-desk-pos/pre-branch-insert-20260904T124511Z.sql`  
SHA-256 `84c548a3813434369d0b869e8017dc0db25fa585989e9632886994586d9d1bbf`

Then two INSERTs into `inventory_branches` (table was empty; unique on `code`):

| id | code | name | active | GSTIN (owner-supplied) |
|----|------|------|--------|------------------------|
| 1 | DELHI-RETAIL | Delhi Retail | yes | `07AAICP1128M1Z9` |
| 2 | MUMBAI | Mumbai | yes | `27AAICP1128M1Z7` |

Addresses were **not stored**. Schema columns are only `code`, `name`, `gstin`, `is_active`, `invoice_sequence`. Addresses remain owner-held for a later schema that can store them. BIHAR / DELHI-WH / UTTAR PRADESH were **not** created. GSTINs were **not** invented.

Rollback: restore the dump above, or `DELETE FROM inventory_branches WHERE code IN ('DELHI-RETAIL','MUMBAI')` while those ids have no child stock (none exist).

## Opening inventory — STOPPED

Gates that failed:

| Gate | Result |
|------|--------|
| Branches exist with owner GSTIN | **PASS** after this ticket’s insert |
| 86 SKUs on Desk | Still **0** — apply would create them, but apply cannot run |
| Serial conflicts | Still **0** serials on Desk |
| Opening-import tables | **ABSENT** |
| Opening-import / POS code on production | **ABSENT** |
| Actor `inventory.opening.import` | Permission **does not exist** on production |
| Production backup for stock apply | Branch backup only; full apply backup not taken |
| persistPreview / apply | **Not run** |

Do not apply from this laptop against production. Do not invent SKUs outside the import.

## Permissions

Committed seeder already defines `inventory.*` and `pos.*`. Production has **none** of those permission rows. Seeding now would attach permissions the deployed 4.0.65 tree does not enforce or expose. **Not seeded.**

## Finance / payment

Production CoA and POS settings are present. `finance_bank_accounts` is empty; POS settlement uses `finance_accounts` `1100`, so that is not a journal blocker. The Cashfree→cash bug is fixed in local source only until POS is deployed.

No accounts were invented.

## Deployment — STOPPED

`deskd` requires clean `main`. This worktree is dirty and on `feat/rd-fresh-01-inventory-pos`. Deploying would also `migrate --force` uncommitted statutory/shipping migrations. No changelog entry for a POS release. No tag. No push.

## Tests this ticket

- Pint + `php -l` on the journal service and new test
- Focused POS/opening/finance: **82 passed**, 408 assertions (`PosSaleJournalReversalTest`, `PosSaleServiceTest`, opening-import service/auth, inventory auth/stock/isolation/access/operational)
- PHPStan: **NO** — not configured
- Frontend build: **NO** — no POS frontend change
- Production functional POS sale: **NO** — POS UI not deployed

## What must happen before LIVE

1. Isolate a **POS-only** deployable tree (no statutory/shipping/channel-ingest WIP).
2. Changelog + owner-approved release (next tag after `4.0.65`).
3. Clean `main` (or an owner-approved POS deploy path) → `deskd`.
4. Confirm opening-import migrations applied and `inventory.*` / `pos.*` permissions seeded.
5. Assign hardware/operators to `DELHI-RETAIL` / `MUMBAI`.
6. Separate authorized APPLY of the SHA-256 `6048543e…` workbook (4,342 Delhi / 40 Mumbai).
7. Production functional verification: login, branch select, SKU/serial visibility, one cash sale, one Cashfree/UPI sale to `1100`, cancel/return, audit movements.

Until those complete, classify POS as **BLOCKED**.

## Isolation

AWS, rdservice.in/net, radiumsign.com, Old Admin, Stocky, and `radiumbox_prod` were not modified. Statutory/shipping WIP was not committed. Opening apply was not performed. Auto GST/IRN remains off.
