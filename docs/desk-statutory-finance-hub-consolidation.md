# Desk statutory / Finance Hub consolidation

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-05-09-03  
**Date:** 2026-09-05  
**Canonical tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` (`main`)  
**Sibling WIP backup:** `backup/statutory-shipping-wip-20260905` `fa26695c` on `/Users/ravi/RadiumWebsites/radium-desk`

## Strategy

Git merge/cherry-pick of `feat/rd-fresh-01-inventory-pos` was rejected: that branch and `main` diverged after `21fc11c5`, and sibling `issueFromCommerceOrder` requires seller profiles + `INV-SSFFNNNN`. Copying it would break the Phase 1 config-series commerce path already on `main`.

Uncommitted sibling WIP was snapshotted first (`fa26695c`) without cleaning the sibling worktree.

Reconstructed only verified Finance Hub work onto the Phase 1 mint engine.

## Merged

- `issueFromPosSale()` on the existing `StatutoryInvoiceService`
- Finance pending queue lists POS sales + commerce orders
- `POST /finance/invoices/sales/{sale}/issue`
- Statutory register, show, PDF download, CSV export
- Optional POS snapshot columns (`buyer_gstin`, `billing_address`, `place_of_supply_state`)
- `completeSale(..., statutory: [])` stores the snapshot; it does not mint
- Accountant-facing export permission `finance.reports.export` (granted with Finance view)

## Deliberately excluded

- Seller profiles / `gst_states` / `INV-SSFFNNNN` / `NumberingScope`
- WhiteBooks HTTP adapter binding
- Shiprocket / shipping schema and services
- POS outbox auto-issue worker
- Sibling rewrite of commerce issue (seller-profile-only)
- Historical Admin `INV*` import or remint
- Cashfree changes
- Live IRN / auto-issue flags (remain OFF)

## POS statutory snapshot (RadiumDesk-P-05-09-04)

The POS counter now captures optional **sale-time** snapshot fields:

- Buyer GSTIN (optional; validated with `BuyerGstin` when present)
- Billing address
- Place of supply (Indian state/UT list; required later for Finance Hub issuance)

These persist on `inventory_sales`. Completing a POS sale still does **not** mint a GST invoice. POS reprint/show remain SELECT-only. Finance Hub remains the sole statutory issuer. Live IRN stays OFF. Historical Admin `INV*` invoices remain a later read-only import.

Customer GSTIN is a default copied onto the sale at complete time. Finance Hub reads the sale snapshot, not later customer edits.

## Historical Admin invoices

Still out of scope. Reprint remains Admin `print/invoice/{id}`. Future work is a read-only import that keeps the original Admin number.
