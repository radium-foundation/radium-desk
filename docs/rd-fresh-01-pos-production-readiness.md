# RD-FRESH-01 — POS production readiness (P-04-09-11)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-11 (finalization) · P-04-09-07 (POS-only checkpoint) · P-04-09-06 (archive) · P-04-09-05 (opening-import foundation)  
**Date:** 2026-09-04  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Checkpoint:** `11d14223e7cfec2ca15be00587d5558dd5eddd18`  
**Canvas:** [`rd-fresh-01-pos-production-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-production-readiness.canvas.tsx)

Companion: [`rd-fresh-01-opening-inventory-import.md`](rd-fresh-01-opening-inventory-import.md) · [`rd-fresh-01-admin-archive-pos-compatibility.md`](rd-fresh-01-admin-archive-pos-compatibility.md) · [`rd-fresh-01-inventory-pos-foundation.md`](rd-fresh-01-inventory-pos-foundation.md).

This ticket started from the committed POS checkpoint and took the implementation as far as it can go **without inventing owner/infra data and without mixing statutory/shipping/channel-ingest WIP**.

## Verdict

**NOT READY** for production POS.

Committed code is fail-closed and is the maximum technical state that can be completed now. Opening stock has not been imported. Production branches, GSTINs, permission seed, hardware assignment, and finance accounts remain owner/infra gates. Passing tests is not a cutover.

## Classification

**VERIFIED** = read from committed POS code/tests at `11d14223` plus this ticket’s POS-only edits.  
**INFERRED** = consistent with that evidence, not re-raced on production MariaDB this ticket.  
**UNKNOWN** = not inspected, or an owner/CA/infra decision.

Dirty worktree files (`PosSaleService`, POS controllers/views, seller profiles, statutory/shipping) were **not** treated as committed POS and were **not** edited.

## What was verified

| Area | Class | Result |
|------|-------|--------|
| Excel template mapping | VERIFIED | P-01-09-08 sheets/headers match the empty gitignored template |
| Preview writes no stock | VERIFIED | Preview persists batch/row metadata only |
| Apply transactional | VERIFIED | One `DB::transaction`; blocked workbooks throw before stock writes |
| SHA-256 replay | VERIFIED | Unique `source_checksum`; second apply is a no-op |
| Serial / qty identity replay | VERIFIED | Global unique serial + `applied_identity` |
| Duplicate serials | VERIFIED | Workbook + Desk; same serial on two SKUs rejected |
| Branch validation | VERIFIED | Lookup only; no auto-create; GSTIN not copied |
| SKU / GST validation | VERIFIED | Create missing SKUs only; serialized/GST mismatch blocks |
| Condition | VERIFIED | New / Used / Refurbished required and stored on serials |
| Damaged serial | VERIFIED | Row created; `available_qty` unchanged |
| Damaged quantity | VERIFIED | Rejected; no `damaged_qty` column |
| Opening movements | VERIFIED | `type=opening`, batch id, `occurred_at` = opening date |
| Atomic serialized sale | VERIFIED | One transaction; finance throw rolls stock back |
| No double-sell / no clone | VERIFIED | Unique serial; transfer relocates the same row |
| Branch isolation | VERIFIED | Hardware cannot sell or search another location |
| Desk-native SKU | VERIFIED | Admin numeric id `946` is `sku_unknown` |
| Internal receipt | VERIFIED | `INV-{branch}-{year}-{seq}`; labelled not GST/IRN |
| Cancel/return | VERIFIED | Restores stock; reversing journal fail-closed |
| Opening-import HTTP auth | VERIFIED | Admin team only; hardware/agent 403 |
| Finance fail-closed | VERIFIED | Missing cash/bank/revenue aborts the sale; no invented CoA |
| Completed workbook | VERIFIED absent | Only empty template + local HTTP preview hashes in `storage/app/private/inventory-opening/` |
| Production branches | UNKNOWN | Production DB not queried |
| Live cashier latency | UNKNOWN | Not measured |
| InnoDB two-process races | INFERRED | Harness skipped (no disposable MariaDB) |

## What changed this ticket

| Change | Why |
|--------|-----|
| `inventory:opening-import` requires an **active** actor with `inventory.opening.import` | CLI previously skipped the HTTP permission gate |
| Blank opening serial unit cost is stored as null | Catalog `unit_cost` was being copied; docs forbid inventing cost |
| Desk variant belonging to another parent is a blocking `variant_parent` issue | Apply used `firstOrFail` and could 500 after a “valid” preview |
| Operational workflow test asserts `Internal Desk POS receipt` | HEAD view no longer contains `Internal Desk invoice` |

Not changed (and why):

- `CounterController` hardcoded Cash/UPI/Card fallback when `finance_payment_methods` is empty — file still contains unrelated statutory WIP; mixing it would not be a clean POS commit. Documented as a remaining fail-open **label** if that table is empty. Production seeders normally populate methods.
- Statutory/shipping/channel-ingest WIP — left in the worktree.
- RadiumBox Read API, rdservice.in, deferred Support/WhatsApp — untouched.

## Production-readiness matrix

| Surface | Status | Class | Note |
|---------|--------|-------|------|
| Catalog | READY (code) | VERIFIED | Empty until SKU Master / product form creates Desk SKUs |
| Branches | OWNER INPUT REQUIRED | UNKNOWN prod | Do not invent codes or GSTINs |
| Opening stock | OWNER INPUT REQUIRED | VERIFIED absent | No owner-approved completed workbook |
| Serial stock | READY (code) | VERIFIED | Rules in place; no live serials imported |
| Quantity stock | READY (code) | VERIFIED | Replay-protected; damaged qty rejected |
| Sales | READY (code) | VERIFIED | Atomic complete; internal receipt |
| Payments | BLOCKED (data) | VERIFIED | Fail-closed without cash/bank + revenue |
| Permissions | INFRASTRUCTURE REQUIRED | VERIFIED seeder | `deskd` seeds; not run here |
| Auditability | READY (code) | VERIFIED | Movements + sale + opening batch |
| Concurrency | READY (sqlite) / UNKNOWN (live) | VERIFIED / UNKNOWN | MySQL harness skipped this host |
| Reporting | OWNER INPUT / separate | VERIFIED gap | Internal POS only; GST reports are statutory |
| Receipt / invoice | READY as internal | VERIFIED | Not a GST tax invoice / IRN |
| Backup / rollback | INFRASTRUCTURE REQUIRED | INFERRED | Opening apply is transactional; production backup is infra |
| Production configuration | INFRASTRUCTURE REQUIRED | VERIFIED | Migration + seed + accounts + branches |

## Remaining blockers (concrete)

| Blocker | Who / what |
|---------|------------|
| Owner-approved completed opening workbook path | Owner must provide the identifiable filled `.xlsx`. Do not search arbitrary folders. |
| Preview clean + apply once + reconcile | Owner/operator after the workbook exists |
| Desk branches with confirmed GSTINs | Owner. Do not copy Bihar-from-Delhi. Import never auto-creates branches. |
| Permission seed on the target database | Infra (`deskd` already seeds `inventory.opening.import`) |
| Hardware assigned to branches | Operator after seed. Do not invent users. |
| Cash/bank + revenue accounts | Finance/infra. POS already fail-closed. |
| Statutory/shipping dirty WIP on this branch | Owner decision before any deploy of this branch |
| GST e-invoice / IRN | Separate workstream; not required to sell internally, required if a legal GST invoice must be issued |
| Live InnoDB cashier latency | UNKNOWN until measured on production |

## Tests this ticket

- Opening import service + authorization (including new CLI, unit-cost, variant-parent cases)
- `InventoryPosAuthorizationTest`, `InventoryStockServiceTest`, `InventoryBranchIsolationTest`, `InventoryPosAccessTest`, `PosSaleServiceTest`
- `InventoryPosOperationalWorkflowTest` (receipt assertion aligned to committed view)
- RadiumBox Read API + identifier unit tests (regression; code untouched)
- `InventoryPosMysqlConcurrencyTest` — gate ran; **7 InnoDB two-process cases skipped** (no disposable MariaDB on this host)
- Suite total this ticket: **89 passed**, **7 skipped**, 475 assertions
- Pint on POS-changed PHP; `php -l` on those files; Blade compile of opening-import + serials/show + workspace-nav (POS tab only)
- PHPStan/Larastan: **NO** — no config in this repo
- Frontend/build: **NO** — no POS frontend build change

## Isolation

AWS, DNS, rdservice.in/net, radiumsign.com, `radiumbox_prod`, Desk production, and the completed inventory workbook were not touched. RadiumBox Read API was not changed. Deferred Support assignment / WhatsApp defect was not touched. Statutory/shipping WIP was left in the worktree.

Do not treat passing sqlite tests as a production cutover.
