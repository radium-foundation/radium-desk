# Permanent B2B IRN + QR automation — P-07-09-241

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-241  
**Date:** 2026-09-11  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`

## INV-076765 diagnosis (read-only)

| Field | Production evidence |
|-------|---------------------|
| Invoice | INV-076765 (`statutory_invoices.id` 1441) |
| Order | POS-000007 (`inventory_sales.id` 7) |
| Buyer | Obbless Technologies LLP |
| Buyer GSTIN | `07AAGFO2888L1ZN` |
| Seller GSTIN | `07AAICP1128M1Z9` |
| Place of supply | Delhi |
| Document | tax_invoice / issued |
| Eligibility | **eligible** (`b2b_eligible`) |
| Dispatch | `outbox_events.id` 713465 `statutory.invoice.einvoice` **completed** (created 11:05:04Z, processed 11:06:02Z, attempts 1) |
| E-invoice | status **skipped**, IRN/Ack/Signed QR empty |
| Skip | `irp_fields_incomplete` / gaps `["missing_uqc"]` |
| Line UQC | `statutory_invoice_items.uqc` NULL |
| Catalog UQC | `inventory_products.id` 44 `RBMARC11L1` **NULL** |
| PDF | `statutory-invoices/1441.pdf` generated at sale commit (16:35:04 IST), never finalized after IRN |
| Provider | WhiteBooks configured; `worker_may_mint` true; `issuance_policy` `all_eligible_b2b` |
| GENERATE | **not called** — worker skipped locally before submit |

**Classification: H — required payload data is missing (`missing_uqc`).**

Not A (dispatch existed). Not B (worker processed). Not C (provider never reached). Not a fabricated IRN. P-187 assigned `PCS` only to nine priority SKUs; RBMARC11L1 was outside that set.

## Future automatic path (already in code)

POS `completeSale` → `issueAfterSaleCommit` → mint (snapshots catalog `uqc`) → `generateDocumentSafely()` → `queueEinvoiceIfEligible` → outbox → `EInvoiceProcessor` → WhiteBooks GENERATE → persist IRN/Ack/Signed QR → `finalizeAfterIrn()`.

That path is fail-closed: missing UQC skips GENERATE and does not invent PCS/NOS. Duplicate outbox is keyed `statutory-irn:{invoice_id}`. Timeout after GENERATE is Ambiguous / Get-IRN, not a second GENERATE.

## This prompt’s fix

1. Catalog: assign `PCS` to remaining serialized active products in the P-187 hardware HSN class (`84716050`, `85269190`, `84716090`) where `uqc` is NULL. Does not backfill historical invoice lines. Does not requeue INV-076765.
2. Product create/edit: persist NIC UQC (already tested; the form/controller field was missing).
3. Automated tests use the **real** IRP mapper (not `FakeEInvoicePayloadMapper`) for the POS B2B lifecycle.

## INV-076765 repair

**NO — not submitted.** Outbox already completed with a local skip. No provider-side IRN is expected, but this prompt does not GENERATE that invoice. Future RBMARC11L1 sales mint after catalog `PCS` and follow the automatic path.
