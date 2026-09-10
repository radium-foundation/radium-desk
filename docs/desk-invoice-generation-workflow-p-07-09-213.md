# Invoice generation workflow — hardware serials and service triggers — P-07-09-213

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-213  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`

## Verified architecture (before change)

Mint-at-trigger. `StatutoryInvoiceStatus` is `issued` or `cancelled` only. `StatutoryInvoiceService::mint()` writes Issued immediately. Identity is unique on `(channel, source_type, source_id)`.

IRN is not mint. `queueEinvoiceIfEligible()` writes the existing `statutory.invoice.einvoice` outbox after mint. GENERATE is the existing worker. WhiteBooks is not inside the sale/allocate/close transaction.

`auto_issue_on_pos_complete` remains **false**. That flag still aborts POS checkout.

## Hardware

**Trigger (verified):** `HardwareSerialAllocationService::allocate()` commits serial allocation, then `HardwareStatutoryInvoiceIssuer::issueAfterSerialsAllocated()` calls `HardwareFulfilmentInvoiceService::issueInvoice()` **outside** the allocation transaction.

- Paid / ingested / ready-for-fulfilment without required serials: no invoice.
- Incomplete serial set: allocation fails; state stays `ready_for_fulfilment`; no invoice.
- All required serials assigned: one statutory invoice; fulfilment becomes `invoice_issued`.
- Re-save / HTTP retry / `issueInvoice()` again: same invoice (`findBySource` + unique identity).
- Serials are not invented. Frozen / HOLD source ids remain blocked.

Finance Hub `issueFromCommerceOrder()` can still mint a commerce hardware order without going through serial allocation. That path was not closed in this prompt. Unique identity still prevents a second invoice.

## Service

Authoritative Service Reference field: **`orders.transaction_id`** (UI: Service Reference). Not `incidents.reference_no`.

**Trigger A (verified, pre-existing):** `OrderTransactionService::assignTransactionId()` commits, then `ServiceStatutoryInvoiceIssuer::issueAfterWorkflowCommit()`.

**Trigger B (this prompt):** operator/generic `ServiceCaseStatusService::updateStatus(..., Closed)` after the status transaction commits. Waiting auto-close already used the same issuer.

Reference assign also closes open cases inside its transaction, then issues after commit. Closure after an existing invoice, and reference after closure, reuse the same commerce identity. One invoice.

Missing commerce / fail-closed mint: case/reference commit remains; failure is logged (`service_statutory_invoice.workflow_issue_failed`).

## POS

Serialized POS sales still require serials **inside** `completeSale()`. After that transaction commits, `PosStatutoryInvoiceIssuer::issueAfterSaleCommit()` calls `issueFromPosSale()`.

WhiteBooks is not called there. A mint failure is logged (`pos_statutory_invoice.sale_issue_failed`) and does not roll back the sale.

`auto_issue_on_pos_complete` must stay false.

## Offline POS

There is no separate offline-POS module. Walk-in hardware POS is the same `completeSale()` path.

**Verified:**

```text
Offline POS hardware sale (no WhiteBooks connectivity required)
        ↓
completeSale persists payment + required serials in one transaction
        ↓
invoice minted after that commit (PosStatutoryInvoiceIssuer)
        ↓
eligible B2B enters existing statutory.invoice.einvoice outbox
        ↓
IRN worker (when connectivity exists) → WhiteBooks GENERATE
        ↓
IRN + Ack + Signed Invoice + Signed QR
```

- Capture with `STATUTORY_EINVOICE_PROVIDER=whitebooks` does not call GENERATE or Get-IRN (`submitCount`/`fetchCount` stay 0; `Http::assertNothingSent()`). The gateway is not a prerequisite for completing the sale.
- Serialized hardware cannot persist without the required serials in `completeSale()`. That is the serial-assignment step for walk-in POS (not a later Hardware Fulfilment allocate). Invoice mint runs after that commit.
- B2C and other ineligible invoices mint (when otherwise eligible to mint) and skip IRN.
- Worker retry / ambiguous GENERATE / Get-IRN recovery are the existing IRN path. No second IRN mechanism.

Proof: `InvoiceGenerationIrnSeparationTest` (`test_offline_pos_hardware_sale_completes_without_whitebooks_then_worker_issues_irn`, B2C skip, repeated complete).

## IRN

Eligible B2B tax invoices enter the existing IRN outbox once. B2C / historical / cancelled / incomplete / policy-skip remain fail-closed (record skipped, no GENERATE). Worker GENERATE / Get-IRN recovery / SignedInvoice / Signed QR are unchanged.

A WhiteBooks timeout or 5xx after mint leaves the invoice issued. Ambiguous GENERATE does not submit again.

## Idempotency

- Hardware: unique statutory identity + `issueInvoice()` existing-invoice short-circuit + allocate idempotent on `serials_allocated` / `invoice_issued`.
- Service: unique `statutory:{channel}:commerce_order:{source_id}`.
- POS: unique `statutory:desk_pos:inventory_sale:{id}`.
- IRN outbox: `firstOrCreate` on invoice idempotency key.

True two-connection races were not executed here (no dedicated MySQL concurrency harness in this run). Sequential overlapping paths and unique keys are tested.

## Production configuration (unchanged by this prompt)

Do not flip `auto_issue_on_pos_complete`. Do not bulk-requeue `worker_may_mint_off` rows. Do not change WhiteBooks credentials.

## Remaining limitations

- Finance Hub can still issue a commerce hardware invoice before serial allocation.
- HTTP Blade tests that render `layouts.app` fail in this worktree when `public/build/manifest.json` is absent (Vite). Not an invoice-logic failure.
- No production overlay/deploy was performed in this prompt until a named-file review of only these workflow files.
