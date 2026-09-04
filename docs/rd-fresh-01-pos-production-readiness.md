# RD-FRESH-01 — POS production readiness (P-04-09-06)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-06 (archive compatibility) · P-04-09-05 (opening-import foundation)  
**Date:** 2026-09-04  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Canvas:** [`rd-fresh-01-pos-production-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-production-readiness.canvas.tsx)

Companion implementation: [`rd-fresh-01-opening-inventory-import.md`](rd-fresh-01-opening-inventory-import.md).  
Prior engine: [`rd-fresh-01-inventory-pos-foundation.md`](rd-fresh-01-inventory-pos-foundation.md).

P-04-09-05 added the opening-import foundation. P-04-09-06 compared that foundation to the 2026-08-30 Old Admin archive (read-only) and added preview reconciliation totals. It did **not** deploy, did **not** import the owner’s completed workbook, and did **not** discard statutory/shipping WIP.

Archive report: [`rd-fresh-01-admin-archive-pos-compatibility.md`](rd-fresh-01-admin-archive-pos-compatibility.md).

## Git inspect (before)

| Check | Result |
|-------|--------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Branch | `feat/rd-fresh-01-inventory-pos` |
| Before SHA | `ba0a63250b664fa2f2bd8605480357ad70cc6036` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | Dirty — ~184 uncommitted statutory/shipping/channel-ingest files plus POS snapshot edits. Left in place. |
| RadiumBox Read API | Already committed on HEAD (`ba0a6325`). Preserved. |

## Classification used

**VERIFIED** = read from this repo’s committed POS code, tests, or the empty agreed xlsx.  
**INFERRED** = consistent with that evidence, not re-raced on production MariaDB this ticket.  
**UNKNOWN** = not inspected or owner/CA/infra decision.

## POS engine (already committed — VERIFIED)

The Day-1 POS path from P-01-09-03 through P-01-09-13 is still the engine:

- One `inventory_*` stock engine; POS does not write support `orders`
- Branch assignment / `operate-all`; hardware cannot see other locations
- Global unique serials; transfer relocates, does not clone
- Sale complete is one transaction: stock + internal invoice + fail-closed finance
- Unique `idempotency_key`; no `FOR UPDATE` on a missing unique key
- Cancel/return restores stock and posts a reversing `pos_sale` journal (not a GST credit note)
- `available_qty` cannot go negative (application check + unsigned column)
- Server-side `abort_unless` on every inventory/POS mutation route

Uncommitted POS-adjacent edits already in the worktree (GSTIN / place-of-supply snapshot, seller profiles, statutory sale document link) belong to earlier statutory tickets. They were **not** rewritten.

## Blockers found this ticket

| # | Blocker | Class | Action |
|---|---------|-------|--------|
| 1 | No import/mapping layer for the agreed Excel template | VERIFIED | Implemented preview/apply foundation |
| 2 | No Desk `condition` column — P-01-09-08 said import was blocked | VERIFIED | Added `inventory_serials.condition` |
| 3 | Quantity opening could be replayed (stock-in has no idempotency) | VERIFIED | File checksum + applied identity |
| 4 | Empty catalog until SKUs exist | VERIFIED | SKU Master creates **missing** SKUs only; does not invent prices/GST |
| 5 | Completed physical-count workbook not in this environment | VERIFIED | Import **not** performed |
| 6 | Permissions not seeded on production | VERIFIED infra | `deskd` already seeds; not run here |
| 7 | Ledger cash/bank + revenue must exist before live POS | VERIFIED | Already fail-closed; not configured here |
| 8 | Internal invoice is not GST e-invoice / IRN | VERIFIED gap | Out of scope; statutory WIP remains OFF |
| 9 | Hardware must be assigned to branches | VERIFIED | Operator step after seed |
| 10 | Damaged non-serialized qty has no Desk column | VERIFIED | Import rejects the row; no invented `damaged_qty` |
| 11 | InnoDB two-process races | INFERRED still good | Harness skipped this host (no disposable MariaDB); previously VERIFIED on 11.8.8 |
| 12 | Production `innodb_lock_wait_timeout` / live cashier latency | UNKNOWN | Not measured |

## Blockers fixed this ticket

- Opening workbook reader mapped to the agreed sheets/columns (including the official template’s absolute `/xl/worksheets/...` rels)
- Condition + optional unit cost stored without rewriting history
- Transactional apply; preview-only default
- Duplicate serial / sold status / missing branch / SKU mismatch fail closed
- Same file cannot apply twice
- Authorization: `inventory.opening.import` admin-only; hardware/agent 403
- Opening movements are auditable (`type=opening`, batch id, counted-by in notes)

## Remaining production gates (POS is **not** production-ready)

| Gate | Type | Status after P-04-09-06 |
|------|------|-------------------------|
| Filled workbook path + clean preview | Owner / workbook | Open — path still unverified |
| Apply once + reconcile to sheet | Owner / workbook | Preview now prints by-SKU/branch totals; apply not run |
| Desk branches + confirmed GSTINs | Owner decision | Open — do not copy Bihar-from-Delhi |
| Permission seed + hardware assignment | Infra / operator | Open — `deskd` seeds; not run here |
| Cash/bank + revenue accounts | Infra / finance | Open — POS already fail-closed |
| Statutory/shipping dirty WIP ship/split | Owner decision | Open — still uncommitted |
| GST tax-invoice / IRN | Separate workstream | OFF |
| Live InnoDB cashier latency | UNKNOWN | Not measured |
| Empty catalog | Workbook SKU Master | Import creates missing SKUs only |
| Damaged non-serialized qty | Archive VERIFIED | Admin never had qty-without-serial warehouse rows; Desk still rejects |
| Fresh AWS/Admin dump | Not required | Archive is code, not current stock |

Do not treat passing sqlite tests as a production cutover.

## Inventory Excel

| Check | Result |
|-------|--------|
| Mapping | Ready for the P-01-09-08 template |
| Empty template headers | VERIFIED in this environment |
| Completed workbook | **NO** — path/source not provided or verified |
| Import | **NO** — not performed |

## Tests this ticket

- `OpeningInventoryImportServiceTest` + `OpeningInventoryImportAuthorizationTest`
- Existing `Inventory*` / `PosSale*` / `InventoryPosAuthorization` suites
- `RadiumBoxRead*` (contract unchanged)
- `InventoryPosMysqlConcurrencyTest` — gate passed; 7 InnoDB cases skipped (no disposable MariaDB)
- Pint on dirty PHP; `php -l` on changed inventory services; `view:cache` then `view:clear`
- No PHPStan/Larastan config in this repo

## Isolation

AWS, DNS, rdservice.in/net, radiumsign.com, `radiumbox_prod`, Desk production, and the completed inventory workbook were not touched. RadiumBox Read API was not changed.
