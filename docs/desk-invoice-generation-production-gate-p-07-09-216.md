# Invoice-generation production verification gate — P-07-09-216

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-216  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Remote:** `git@github.com:radium-foundation/radium-desk.git`  
**Branch:** `feat/irn-foundation-phase-a`  
**Before SHA:** `1f38d8de3732c491175f65650fa5131df567bf1b`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`  
**Host:** `desk.radiumbox.com` (Cloudflare `cf-ray` present)  
**Mechanism:** named-file overlay (already applied in P-215). **Not** `deskd`.

## Production boundary (re-verified)

| Axis | Value | Class |
|------|--------|--------|
| Project | Radium Desk | VERIFIED |
| Repo / HEAD | `radium-desk` `1f38d8de` = `origin/feat/irn-foundation-phase-a` | VERIFIED |
| Branch | `feat/irn-foundation-phase-a` | VERIFIED |
| Server | KVM8 `srv1910783` / `187.127.129.16` | VERIFIED |
| Path | `/var/www/radium-desk` `755 ravi:ravi` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` 8.4.24 | VERIFIED |
| DB | `radium_desk` @ `127.0.0.1` | VERIFIED |
| vhost / DNS | `desk.radiumbox.com` `/up` 200 local Host + public HTTPS | VERIFIED |
| Cloudflare | `cf-ray` present | VERIFIED |
| Deploy mechanism | named-file overlay | VERIFIED |
| Prior overlay | `p-07-09-215-20260910T161301Z` | VERIFIED |
| Integrations | WhiteBooks gateway bound; worker mint true; policy `all_eligible_b2b` | VERIFIED |
| `.env` mtime | `2026-09-10 20:13:31 +0530` (unchanged this prompt) | VERIFIED |

No critical boundary unknown. Gate did **not** STOP.

## Production/source comparison

Committed HEAD (`git show`, not dirty UQC worktree) vs production SHA-256:

**MATCH** for all P-213/P-214/P-215 invoice-workflow files, including:

- `HardwareStatutoryInvoiceIssuer.php`
- `PosStatutoryInvoiceIssuer.php`
- `HardwareSerialAllocationService.php`
- `PosSaleService.php`
- `ServiceCaseStatusService.php`
- `ServiceStatutoryInvoiceIssuer.php`
- `PosStatutorySnapshot.php` / POS counter / `InventorySale`
- `HardwareCommerceStatutoryInvoiceGuard.php`
- `StatutoryInvoiceService.php` (committed P-214, not dirty UQC)
- `OrderTransactionService.php`

**Intentional difference (not stale P-213/P-215):** production `HardwareFulfilmentInvoiceService.php` is `7a6e7bf0…` (prior UQC/billing snapshot overlay). Committed HEAD is `400c1d37…` without that overlay. Replacing production with HEAD would revert UQC. File still exposes `issueInvoice()` and `requireAllocatedSerials()`. **Not overlaid.**

Unrelated dirty UQC/catalog worktree files were parked and **not** deployed.

**Deployment required: NO.** P-215 overlay is fully present.

## Final verified production workflow

### Hardware

**Paid does not equal invoice-ready. Required serial allocation triggers the statutory invoice.**

```text
Paid hardware
        ↓
Serials incomplete → Finance Hub / commerce mint blocked (P-214)
        ↓
Required serials complete (allocate() commits)
        ↓
HardwareStatutoryInvoiceIssuer → HardwareFulfilmentInvoiceService::issueInvoice()
        ↓
ONE statutory invoice
        ↓
Eligible B2B → statutory.invoice.einvoice
        ↓
Worker → WhiteBooks → IRN + Ack + Signed Invoice + Signed QR
```

Live allocate / auto-invoice chain: **NO — Not performed: no isolated authorized production hardware sample available.**  
`hardware_fulfilments.state=serials_allocated` count 0. Fulfilment events after P-215 overlay: 0. `RDE318400` already has invoice 1062 / AWB; not reused for GENERATE.

### Offline POS

**Serials are assigned during `completeSale()`, the sale commits, then the statutory invoice is minted and eligible B2B enters the existing IRN outbox.**

Production `PosSaleService` calls `PosStatutoryInvoiceIssuer::issueAfterSaleCommit()` after the sale transaction. POS capture files contain **zero** GENERATE / Get-IRN / `submit(` calls (comment only).

`auto_issue_on_pos_complete=false`.

Live POS capture: **NO — Not performed: no isolated authorized production POS sample available.** (`inventory_sales` created after overlay: 0)

### Service

**Service Reference No. OR case closure triggers exactly one invoice.**

Both call `ServiceStatutoryInvoiceIssuer::issueAfterWorkflowCommit()` (reference: `OrderTransactionService`; close: `ServiceCaseStatusService`) on the same commerce identity.

Live Service Reference: **NO — Not performed: no isolated authorized production service-reference sample available.**  
Live Case Closed: **NO — Not performed: no isolated authorized production service-closure sample available.**  
(`orders.transaction_id` updates after overlay: 0; `incidents` Closed after overlay: 0)

### IRN

**Invoice creation is separate from WhiteBooks. The existing worker performs IRN generation asynchronously.**

Provider `whitebooks`; gateway `WhitebooksEInvoiceGateway`; worker mint true; outbox `statutory.invoice.einvoice`; `EInvoiceProcessor` + `EInvoiceIrnGuard` (P-206 recover-instead-of-GENERATE / must-not-resubmit) + `EInvoiceIrnRecoveryService` + `EInvoiceEligibility` (P-207) + Signed Invoice store present.

No second GENERATE on existing IRNs. No bulk requeue. No historical rewrite.

## Finance Hub safety

`RDE318526` / HF 29 re-probed: `validated`, `ready_for_fulfilment`, no serials, no invoice.  
`evaluateOrder()` ineligible (serials required). `issueFromCommerceOrder()` threw `SERIALS_ALLOCATED`. State unchanged. Invoices 1202. IRN 3.

Live stock not allocated.

## Idempotency (source + tests; no live remint)

- Commerce: `statutory:{channel}:{source_type}:{source_id}`
- POS: `statutory:desk_pos:inventory_sale:{id}`
- IRN outbox: `firstOrCreate` on `statutory-irn:{invoice_id}`
- Processor: `recordHasIssuedIrn` / `mustNotResubmit` → no duplicate GENERATE

Duplicate production groups: invoices 0, IRNs 0.

## Tests (committed HEAD; UQC stash parked)

| Suite | Result |
|-------|--------|
| Hardware serial gate, IRN separation, service issuance, P4 allocate, POS snapshot, POS Finance Hub, PosSaleService | 107 passed, 3 failed |
| IRN eligibility / path / policy / P-206 / Get-IRN / Signed Invoice / PDF / binding / foundation / WhiteBooks / Commerce Hub HTTP / service GST | 146 passed, 2 failed |
| Pint + `php -l` | passed |

### Pre-existing failures (unchanged Vite `public/build/manifest.json`)

- `HardwareFulfilmentP4AllocationTest::test_picker_http_requires_hardware_fulfilment_permission`
- `PosStatutorySnapshotTest::test_show_and_reprint_do_not_mint_a_statutory_invoice`
- `PosStatutorySnapshotTest::test_finance_pending_distinguishes_ready_and_missing_place_of_supply`
- `CommerceOrderFinanceHubIssueTest::test_eligible_commerce_order_shows_an_available_issue_action`
- `CommerceOrderFinanceHubIssueTest::test_ineligible_commerce_order_disables_the_issue_action`

## Production health

| Check | Result |
|-------|--------|
| `/up` | 200 local Host + public |
| Worker | RUNNING pid 2471594 |
| Invoices | 1202 issued, 0 cancelled |
| IRN filled | 3 |
| einvoice pending | 0 |
| einvoice completed | 51 |
| failed_jobs since 21:30 IST | 0 |
| failed_jobs total | 65 (recent classes: `RadiumBoxOrderEnrichmentJob`; not invoice/IRN) |
| New invoices/IRNs after overlay | 0 / 0 |
| Bounded laravel.log (`[2026-09-10 21–23:` + invoice/IRN needles) | no matching lines |

## Rollback

No new overlay this prompt. Existing P-215 backup remains:

`/var/www/radium-desk/storage/app/private/overlays/p-07-09-215-20260910T161301Z`

Restore those replaced files, delete the two created issuers, dump-autoload, `optimize:clear` + `optimize`, restart worker. Never delete invoice/IRN rows.

Rollback status: **not used**.

## Not performed

`deskd`, tag/release, migrate, `.env` edit, additional overlay, live serial allocate, live POS sale, live service mint, WhiteBooks GENERATE/Get-IRN this ticket, bulk requeue, historical rewrite.
