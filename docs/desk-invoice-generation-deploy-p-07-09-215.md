# Deploy P-213 automatic invoice triggers (preserve P-214) — P-07-09-215

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-215  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Remote:** `git@github.com:radium-foundation/radium-desk.git`  
**Branch:** `feat/irn-foundation-phase-a`  
**Before SHA:** `9b253c22badf50c5796078ff21fa05df24ccb8c3`  
**Source SHA overlaid:** `9b253c22` (P-213 PHP from `8553637f` / `4c075b93`; P-214 already live from `e6371221`)  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Mechanism:** named-file overlay (`install -m 644`) from `git archive 9b253c22`. **Not** `deskd`. **Not** dirty worktree. No migrate. No `.env`. No bulk IRN requeue.

## Verified production architecture

### Hardware

**Payment does not make a hardware order invoice-ready. Required serial allocation does.**

```text
Paid hardware order
        ↓
NOT invoice-ready
        ↓
Required Serial No.(s) assigned
        ↓
HardwareStatutoryInvoiceIssuer after allocate() commit
        ↓
HardwareFulfilmentInvoiceService::issueInvoice() exactly once
        ↓
Eligible B2B → statutory.invoice.einvoice
        ↓
WhiteBooks worker
        ↓
IRN + Ack + Signed Invoice + Signed QR
```

Finance Hub still cannot mint while serials are incomplete (`HardwareCommerceStatutoryInvoiceGuard` + `issueInvoice()` SERIALS_ALLOCATED).

### Offline POS

**Serials are assigned during `completeSale()`, the sale commits, then statutory invoice is minted and eligible B2B invoice enters the existing IRN outbox.**

```text
Offline POS hardware sale
        ↓
completeSale persists payment + required serials
        ↓
transaction commits
        ↓
PosStatutoryInvoiceIssuer
        ↓
invoice
        ↓
existing IRN outbox if B2B eligible
```

WhiteBooks is never required during POS capture. `auto_issue_on_pos_complete` remains **false**.

### Service

**Service Reference No. OR case closure triggers exactly one invoice.**

```text
Service Reference No. (orders.transaction_id)
        OR
Case Closed without invoice
        ↓
ServiceStatutoryInvoiceIssuer (same commerce identity)
        ↓
invoice exactly once
        ↓
IRN outbox if eligible
```

### IRN

**Invoice generation is independent from WhiteBooks. The existing worker handles IRN asynchronously.**

No second IRN implementation. GENERATE / Get-IRN / P-206 / P-207 / Signed Invoice / Signed QR unchanged.

## Pre-change verification

| Item | Value |
|------|--------|
| Worktree | `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation` |
| Branch | `feat/irn-foundation-phase-a` = `origin/feat/irn-foundation-phase-a` |
| HEAD | `9b253c22` |
| Remote HEAD | `9b253c22` |
| P-213 source | PRESENT (`HardwareStatutoryInvoiceIssuer`, `PosStatutoryInvoiceIssuer`, case-close hook, after-commit allocate) |
| P-214 source | PRESENT (`HardwareCommerceStatutoryInvoiceGuard`, `issueFromCommerceOrder()` delegates to `issueInvoice()`) |
| Production P-213 issuers | ABSENT before overlay |
| Production P-214 guard | PRESENT, SHA MATCH `9b253c22` |
| Unrelated dirty UQC/catalog | Parked in stash `p-215-park-unrelated-uqc-catalog`; **not** overlaid |

Production `HardwareFulfilmentInvoiceService.php` already had a prior UQC/billing snapshot overlay (`7a6e7bf0…`) that is **not** in `9b253c22`. That file was **not** replaced (HEAD would have reverted UQC). `issueInvoice()` / `requireAllocatedSerials()` remain intact.

## Overlay set (6 PHP from `9b253c22`)

| File | Action | Production SHA-256 after |
|------|--------|--------------------------|
| `app/Services/HardwareFulfilment/HardwareStatutoryInvoiceIssuer.php` | created | `22d226db…` MATCH HEAD |
| `app/Services/StatutoryInvoice/PosStatutoryInvoiceIssuer.php` | created | `e9481c67…` MATCH HEAD |
| `app/Services/HardwareFulfilment/HardwareSerialAllocationService.php` | replaced | `86df376a…` MATCH HEAD |
| `app/Services/Inventory/PosSaleService.php` | replaced | `d4d3625b…` MATCH HEAD |
| `app/Services/ServiceCaseStatusService.php` | replaced | `b3dc96e9…` MATCH HEAD |
| `app/Services/StatutoryInvoice/ServiceStatutoryInvoiceIssuer.php` | replaced | `2719e882…` MATCH HEAD |

Already MATCH HEAD (not copied): P-214 guard, `StatutoryInvoiceService`, `StatutoryMintEligibility`, `InventorySale`, POS counter blade/controller, `PosStatutorySnapshot`, `PosUpiIntentService`.

Intentionally **not** copied: `HardwareFulfilmentInvoiceService.php` (preserve production UQC overlay), tests, docs, migrations, `.env`, Vite, ChannelIngest, product catalog form, POS sale show view.

`php -l` clean. `composer dump-autoload -o` via lsphp (7119 classes; both new issuers in classmap). `artisan optimize:clear` + `optimize`. Web root stayed `755 ravi:ravi`. Worker restarted RUNNING `pid 2471594`.

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-215-20260910T161301Z`

Restore replaced files from that backup with `install -m 644`. Delete the two created issuer files. Then dump-autoload, `optimize:clear` + `optimize`, restart `radium-desk-queue-worker`. Verify `/up` and worker. **Never delete** `statutory_invoices` or `e_invoice_records`.

Rollback status: **not used**.

## Tests (local HEAD, UQC stash parked)

| Suite | Result |
|-------|--------|
| Finance Hub serial gate + invoice/IRN separation + service issuance + P4 allocate + POS snapshot + POS Finance Hub | 87 passed; 3 failed |
| IRN eligibility / path / policy / P-206 recovery / Get-IRN / Signed Invoice / PDF / production binding / WhiteBooks gateway / foundation | 130 passed |
| Commerce Finance Hub + service GST split + isolated one-order | 33 passed; 2 failed |
| `PosSaleServiceTest` | 20 passed |
| Pint + `php -l` on overlay files | passed |

### Pre-existing failures (Vite `layouts.app` GET, missing `public/build/manifest.json`)

- `HardwareFulfilmentP4AllocationTest::test_picker_http_requires_hardware_fulfilment_permission`
- `PosStatutorySnapshotTest::test_show_and_reprint_do_not_mint_a_statutory_invoice`
- `PosStatutorySnapshotTest::test_finance_pending_distinguishes_ready_and_missing_place_of_supply`
- `CommerceOrderFinanceHubIssueTest::test_eligible_commerce_order_shows_an_available_issue_action`
- `CommerceOrderFinanceHubIssueTest::test_ineligible_commerce_order_disables_the_issue_action`

P-214 also recorded POS B2B `completeSale` tests that omit billing city. This run: `PosSaleServiceTest` 20 passed; `InvoiceGenerationIrnSeparationTest` (which supplies `billing_city`) passed. No new `City is required for B2B sales.` failure was observed in the focused set.

## Production configuration (unchanged)

```
auto_issue_on_pos_complete = false
STATUTORY_EINVOICE_PROVIDER=whitebooks
STATUTORY_EINVOICE_WORKER_MAY_MINT=true
STATUTORY_EINVOICE_ISSUANCE_POLICY=all_eligible_b2b
Gateway=WhitebooksEInvoiceGateway
```

`.env` mtime remained `2026-09-10 20:13:31 +0530` (before this overlay).

## Production verification

### A. Application

| Check | Result |
|-------|--------|
| `/up` Host `desk.radiumbox.com` | 200 |
| `https://desk.radiumbox.com/up` | 200 |
| DB | `radium_desk` connected |
| Worker | RUNNING after restart |
| Invoices | 1202 before and after overlay + guard probe |
| IRN filled | 3 (unchanged) |
| einvoice outbox pending | 0 |
| einvoice outbox completed | 51 (unchanged) |

### B. Finance Hub guard

`RDE318526` / HF 29: commerce `validated`, `ready_for_fulfilment`, no serials, no statutory invoice.

`evaluateOrder()` ineligible, includes: *Hardware statutory invoice generation requires completion of required serial allocation. Payment alone does not trigger the final statutory hardware invoice.*

`issueFromCommerceOrder()` threw `Hardware invoice issuance requires SERIALS_ALLOCATED. READY_FOR_FULFILMENT cannot skip serials.`

Fulfilment state unchanged. Invoice count 1202. IRN 3. **No serial allocated. No IRN generated.**

### C. Hardware automatic trigger

`hardware_fulfilments.state=serials_allocated` count **0**.

`NO — Not performed: no isolated authorized production sample available.`

Live stock was not allocated.

### D. Offline POS

Deployed source confirms: `completeSale()` persists serials inside the sale transaction, then `PosStatutoryInvoiceIssuer::issueAfterSaleCommit()` after commit. `PosSaleService` / `PosStatutoryInvoiceIssuer` contain **zero** WhiteBooks GENERATE / Get-IRN / `submit(` calls (comment only).

Live POS capture / live IRN: **NO — Not performed.**

### E. Service

Reference hook already on production (`OrderTransactionService` line 191). Case-close fallback now on production (`ServiceCaseStatusService` after commit).

Live service reference or case-close mint: **NO — Not performed: no isolated authorized production sample available.**

## Idempotency (tests; no live remint)

Preserved: commerce `(channel, source_type, source_id)`; POS `statutory:desk_pos:inventory_sale:{id}`; IRN `firstOrCreate`.

## Historical data

No historical Finance Hub hardware invoices rewritten. No bulk IRN. No skipped-row requeue.

## Not performed

`deskd`, tag/release, migrate, `.env` edit, `auto_issue_on_pos_complete=true`, live serial allocate, live POS sale, live service mint, live WhiteBooks GENERATE/Get-IRN for this ticket, rollback.

## Follow-up

P-07-09-216 re-hashed production vs committed HEAD: P-215 overlay still MATCH. No additional files deployed. Live hardware/POS/service end-to-end still not performed (no authorized samples). Report: `docs/desk-invoice-generation-production-gate-p-07-09-216.md`.
