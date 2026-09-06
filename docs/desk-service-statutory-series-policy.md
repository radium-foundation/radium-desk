# Owner-locked SERVICE statutory series (FY 2026–27)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-06-09-24, implemented RadiumDesk-P-06-09-31  
**Date:** 2026-09-06  
**Tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main`

This document is the **Owner-locked SERVICE numbering policy**. It supersedes the P-06-09-21 conclusion that `INV-671` was rejected.

**Implemented (RadiumDesk-P-06-09-31):** Desk now mints FY 2026–27 service invoices on this matrix. Delhi B2C uses an isolated `location:delhi_b2c` sequence (`INV-671…`). Delhi B2B and products keep `location:delhi` (`INV-07671…`). Mumbai B2B+B2C share `location:mumbai` (`INV-27671…`).

Products are out of scope. Product billing remains inventory/serial location (`DELHI-RETAIL` → Delhi `INV-07671…`, `MUMBAI` → Mumbai `INV-27671…`).

## Locked service matrix (FY 2026–27)

| Transaction | Customer type | Customer/service location | Issuer | FY 2026–27 first numbers |
|---|---|---|---|---|
| Service | B2C | Maharashtra | Mumbai | `INV-27671`, `INV-27672`, `INV-27673`… |
| Service | B2C | Non-Maharashtra | Delhi | `INV-671`, `INV-672`, `INV-673`… |
| Service | B2B | Maharashtra (valid GSTIN state `27`) | Mumbai | `INV-27671`, `INV-27672`, `INV-27673`… |
| Service | B2B | Non-Maharashtra (valid GSTIN, state ≠ `27`) | Delhi | `INV-07671`, `INV-07672`, `INV-07673`… |

Mumbai B2B and B2C share one series. Delhi B2B (`INV-07671…`) and Delhi B2C (`INV-671…`) are **separate** series.

POS vs Online does not choose the series. Place of Supply does not choose the issuer. Missing or ambiguous B2C state must fail closed.

## Number families (do not conflate)

| Family | Meaning |
|---|---|
| Legacy `INV67…` / `INV679…` (no hyphen) | Historical Admin `rd_slug`+`rd_no`. Immutable. Not the new Desk series. |
| `INV-671`, `INV-672`… | Owner-locked **Delhi B2C service** series for FY 2026–27. Isolated sequence `location:delhi_b2c`. |
| `INV-07671`, `INV-07672`… | Owner-locked **Delhi B2B service** series and product Delhi location series. Sequence `location:delhi`. |
| `INV-27671`, `INV-27672`… | Owner-locked **Mumbai service** series (B2B+B2C). Also current code Mumbai location series. |
| `INS…` | rdservice.in storefront allocator (blocked in prod). Not this policy. |
| `IND…` / `INM…` | Historical Admin prefixes. Immutable. |

Do **not** replace `INV-671` with `INV-07671` for Non-Maharashtra B2C.

## FY 2027–28

Mumbai / Delhi B2B code-path rollover already in `StatutoryFinancialYear` is `78` → `INV-27781` / `INV-07781`. The Owner has **not** locked the FY 2027–28 string for `INV-671`. That remains **UNKNOWN**. Do not invent `INV-781`.

## B2C / B2B issuer rules (implemented P-06-09-31)

- **B2C:** `buyer_gstin` null/empty. Issuer comes from `commerce_orders.billing_state` only, and that value must be a recognised `IndianStates` name. Maharashtra → Mumbai `INV-27671…`. Any other recognised state → Delhi B2C `INV-671…`. Missing or invalid `billing_state` fails closed. `place_of_supply_state` must not substitute.
- **B2B:** present valid 15-character GSTIN. Issuer comes from the first two GSTIN digits. `27` → Mumbai `INV-27671…`. Any other known GST state → Delhi B2B `INV-07671…`. `billing_state` does not override a valid B2B GSTIN. Invalid non-empty GSTIN fails closed and is never treated as B2C.
- POS vs Online does not choose the series.

P-06-09-28 persisted structured checkout `billing_address.state` onto `commerce_orders.billing_state`. Existing September orders are not backfilled.

## Existing invoices

Legacy `INV*` / `IND*` / `INM*` remain unchanged. No remint, rewrite, branch-change, or September backfill.

## Statutory issuance (implemented P-06-09-31)

`issueFromSupportOrder()` delegates to the existing commerce-order identity:

- channel `rdservice_in`
- source_type `commerce_order`
- source_id = Desk `orders.order_id` (`RD*`)
- idempotency `statutory:rdservice_in:commerce_order:RD*`

It does **not** mint a second `support_order` numbering identity. `support_order_id` is linked on the commerce row and, at mint time, on the invoice.

Workflow triggers (after the workflow transaction commits; mint failure does not roll back the workflow):

1. `OrderTransactionService::assignTransactionId`
2. `CustomerWaitingLifecycleService::autoCloseForNoResponse`

Do **not** hook `closeActiveServiceCasesForOrder` or generic `updateStatus(Closed)`. Payment / `OrderPaid` remains journal/eligibility only and does not mint.

Tax lines come from `commerce_order_items`. Missing required tax fields such as `gst_percentage` fail closed before a sequence number is consumed. No invented GST rate.

Auto-issue flags remain OFF: `auto_issue_on_pos_complete`, `channel_ingest.auto_issue_invoice`, `worker_may_mint`. B2C e-invoice stays skipped. B2B IRP/GSP is not called. Credit notes are not implemented.
