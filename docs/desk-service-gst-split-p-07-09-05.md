# Service GST decomposition — RadiumDesk-P-07-09-05

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-05`  
**Scope:** New statutory **service** invoices only. INV-27671 and every other issued INV-* remain immutable.

## Problem

P-07-09-04 verified that Desk calculated lump GST correctly (`422.88 × 18% → 76.12`) but never decomposed it into CGST/SGST/IGST. Header and line components stayed NULL. The PDF coerced NULL to `0`, so intra-State Maharashtra invoices printed CGST/SGST/IGST as 0.

## Legal basis used

- IGST Act s.8(2): service supply is intra-State when the **supplier GST state** and **place of supply** are the same State.
- IGST Act s.7(3): inter-State when those locations differ.
- CGST Rule 46(l)/(m): the tax invoice must show the applicable tax rate and the CGST/SGST or IGST amount.

`billing_state` remains the **B2C issuer** selector. It is **not** the intra/inter test. Place of supply is taken from persisted `place_of_supply_state` (ingest). Desk still does not invent IGST s.12 POS. Unrecognised or missing POS fails closed.

## What changed

New `GstSplitService` runs on `CommerceOrder` mint **before** sequence allocation.

| Seller GST state | Place of supply | 18% treatment |
|---|---|---|
| 27 Maharashtra | Maharashtra | CGST 9% + SGST 9% |
| 07 Delhi | Delhi | CGST 9% + SGST 9% |
| 27 Maharashtra | any other recognised State | IGST 18% |
| 07 Delhi | any other recognised State | IGST 18% |

Authoritative `tax_total` is never rewritten. Intra split is `round(tax/2, 2)` + remainder so components always sum to `tax_total` (example: `38.06 + 38.06 = 76.12`). If `round(taxable × rate/100, 2)` ≠ `tax_total`, issuance fails closed.

Product / POS `issueFromPosSale` is unchanged (components may still be NULL). Historical reprint blade is unchanged.

## Documents

- New service PDFs print description on its own line, then HSN/SAC, qty, taxable, GST %, CGST, SGST, IGST, tax, total.
- NULL components render as `not recorded` (PDF) or `—` (Finance HTML). Actual `0.00` prints as `0.00`.
- `StatutoryDocumentService::generate` will not overwrite an already-generated PDF file. Issued INV-27671 is not regenerated.

## HSN/SAC

Authoritative SAC is **UNKNOWN**. Stored service code `998314` is left unchanged. The older description/PDF text `998313` was not adopted.

## Out of scope (not done)

No update of INV-27671 or any issued row. No credit note. No product invoicing change. No rdservice.in GST change. No auto-issue / payment-trigger / series / IRN / schema change. No production deploy.
