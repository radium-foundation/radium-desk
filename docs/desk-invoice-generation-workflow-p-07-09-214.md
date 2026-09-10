# Finance Hub hardware serial gate — P-07-09-214

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-214  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Before SHA:** `4c075b930f7d711840548ce03cd7f62d09be7c65`  
**After SHA:** `e6371221f96d6d606bb66d0ebf4b5a04cebe6c7d`

## Verified rule

> **Hardware statutory invoice generation requires completion of required serial allocation. Payment alone does not trigger the final statutory hardware invoice.**

Paid / order completion does not by itself make a hardware commerce order invoice-ready.

```text
Order/payment successful
        ↓
NOT YET invoice-ready
        ↓
Required serial number(s) assigned
        ↓
Hardware fulfilment becomes complete/eligible
        ↓
Exactly one statutory invoice
        ↓
Existing IRN outbox if B2B eligible
        ↓
Existing WhiteBooks worker
```

## Root cause (re-verified on this HEAD)

P-07-09-213 minted hardware invoices after `HardwareSerialAllocationService::allocate()` via `HardwareStatutoryInvoiceIssuer` → `HardwareFulfilmentInvoiceService::issueInvoice()`.

Finance Hub `StatutoryInvoiceIssueController::issue()` still called `StatutoryInvoiceService::issueFromCommerceOrder()`. That path minted from payment-eligible commerce lines and did not require `SERIALS_ALLOCATED` or a matching allocated serial count. Unique `(channel, source_type, source_id)` only prevented a *second* invoice.

## Finance Hub hardware workflow

`issueFromCommerceOrder()`:

1. If a statutory invoice already exists for `(channel, commerce_order, source_id)`, return it (no remint).
2. If `HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice()`:
   - fulfilment row exists, **or**
   - hardware source id (`RDE`/`RBP`/`RIN`/`RDP`) **and** physical merchandise lines  
   then **do not** use the service HSN mint path. Delegate to `HardwareFulfilmentInvoiceService::issueInvoice()` (existing `lockForUpdate` on the fulfilment row).
3. Missing fulfilment, state before `serials_allocated`, blank/duplicate/partial serials, or frozen HOLD: **no statutory invoice**.

Finance Hub UI `evaluateOrder()` adds the same serial-required error and skips the service issuer/GST matrix for hardware. The Issue action is not “ready” until serial allocation is complete.

Serial numbers are not invented. A NULL/blank/empty allocated list is incomplete.

## Offline POS workflow (unchanged)

```text
Offline POS hardware sale
  → completeSale persists payment + required serials
  → transaction commits
  → PosStatutoryInvoiceIssuer mints
  → eligible B2B queued on existing IRN outbox
  → worker processes IRN
```

`auto_issue_on_pos_complete` remains **false**. WhiteBooks is not required during POS capture.

## Service workflow (unchanged)

### Service Reference

Valid `orders.transaction_id` → one invoice → existing IRN path if eligible.

### Closure fallback

Case Closed, if no invoice exists → one invoice → existing IRN path if eligible.

Both events share `statutory:{channel}:commerce_order:{source_id}` and do not duplicate.

## Invoice / IRN separation

Unchanged. Mint is not GENERATE. Eligible B2B tax invoices enter `statutory.invoice.einvoice` once. WhiteBooks, P-206 ambiguous handling, Get-IRN recovery, P-207 eligibility, Signed Invoice, Signed QR, retry classification are untouched.

A premature hardware invoice is not created merely to reach the IRN queue.

## Idempotency

Preserved:

- Commerce: `(channel, source_type, source_id)`
- POS: `statutory:desk_pos:inventory_sale:{id}`
- IRN outbox `firstOrCreate` on invoice key

Serial assignment, Finance Hub retry, payment/fulfilment retry, and overlapping hub + allocate converge on at most one statutory invoice.

## Concurrency

`HardwareFulfilmentInvoiceService::issueInvoice()` and `HardwareSerialAllocationService::allocate()` both `lockForUpdate()` the same `hardware_fulfilments` row. Finance Hub cannot mint from a partially-complete fulfilment: READY / incomplete serials fail closed; after commit, both paths share `findBySource` + unique identity.

True two-connection InnoDB races were not executed here (`phpunit.xml` forces sqlite `:memory:`). Sequential overlapping hub + allocate is tested.

## Tests

Focused `FinanceHubHardwareSerialGateTest` (12 passed): paid without serials; incomplete/blank serials; allocate → one invoice; repeated allocate; Finance Hub HTTP retry before serials; hub retry after serials + IRN outbox once; overlapping hub + allocate; B2C skip IRN; hardware without fulfilment; service path not gated; `auto_issue_on_pos_complete` false.

Regression run on this change:

- Invoice generation / IRN separation, service issuance, hardware P3/P4, EInvoice path/policy, PDF, Get-IRN, P-206 recovery boundary, signed invoice/QR: intended cases passed.
- Broader statutory feature+unit: 336 tests, 331 passed.
- Unchanged Vite `layouts.app` GET failures (missing `public/build/manifest.json`).
- Unchanged POS B2B `completeSale` tests that omit billing city (`City is required for B2B sales.`) — verified failing on `4c075b93` before this change.

## Production / migration

No schema migration. No historical invoice rewrite. No bulk IRN requeue. WhiteBooks credentials unchanged. Unrelated UQC/catalog worktree files were not modified (parked in stash).

Named-file overlay of `e6371221` PHP only (not `deskd`) on KVM8 `srv1910783` `/var/www/radium-desk` DB `radium_desk`. Backup `storage/app/private/overlays/p-07-09-214-20260910T155807Z`. Rollback: restore those four previous files and delete the new Guard file.

## Production verification (controlled)

Live `/up` 200 (`desk.radiumbox.com`, Cloudflare). Queue worker RUNNING. Invoice count **1202** before and after the gate probe.

`RDE318526` / HF 29: paid B2C, `ready_for_fulfilment`, no serials, no statutory invoice. Finance Hub `evaluateOrder()` ineligible (`Serial allocation required`). `issueFromCommerceOrder()` threw `SERIALS_ALLOCATED`. Fulfilment state unchanged. No IRN generated.

Serial assignment on a live customer order was **not** performed in P-214: no isolated authorized leftover (`serials_allocated` count 0); allocating would sell production stock.

P-07-09-215 overlaid the P-213 after-commit issuers while leaving this Finance Hub guard and `HardwareFulfilmentInvoiceService::issueInvoice()` intact. P-07-09-216 re-verified the guard SHA MATCH and re-probed `RDE318526` (still blocked). See `docs/desk-invoice-generation-deploy-p-07-09-215.md` and `docs/desk-invoice-generation-production-gate-p-07-09-216.md`.

## Remaining limitations

- True two-connection MariaDB lock races remain unproven in this sqlite PHPUnit environment.
- HTTP Blade tests that render `layouts.app` still fail without a Vite manifest.
- Historical invoices minted via Finance Hub before this gate are not rewritten.
