# Statutory invoice PDF + INV-276710 IRN — RadiumDesk-P-07-09-46

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-46`  
**Mode:** Investigate IRN for `INV-276710`. Correct customer PDF presentation. Do not submit IRN. Do not remint. Do not regenerate production PDFs.

Ledger file is `docs/cursor-prompt-ledger.md`. `P-07-09-45` was the prior read-only “today’s invoices” lookup (chat report only, no ledger row). This ticket uses the next unused ID **P-07-09-46**.

---

## Identity

| Item | Value | Class |
|---|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` | VERIFIED |
| Production | KVM `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk` | VERIFIED |
| App timezone | `Asia/Kolkata` | VERIFIED |

---

## Lifecycle (code)

```
Service Reference assign
  → ServiceStatutoryInvoiceIssuer::issueAfterWorkflowCommit
    → StatutoryInvoiceService::issueFromSupportOrder / issueFromCommerceOrder
      → mint() persists statutory_invoices + items
      → StatutoryDocumentService::generate (immutable once the PDF file exists)
      → queueEinvoiceIfEligible
          B2C / invalid GSTIN → e_invoice_records.status=skipped, no outbox
          B2B eligible → status=queued + outbox statutory.invoice.einvoice
      → EInvoiceProcessor (if outbox claimed) never HTTP-submits
          persistSkip: worker_may_mint_off | provider_disabled | eligibility reason
```

Bound adapter: `NullEInvoiceGateway`. Config `statutory_invoices.einvoice.provider` default `none`. `worker_may_mint` hardcoded `false`.

Invoice creation does **not** mean IRN submission. **VERIFIED.**

---

## INV-276710 (production SELECT, 2026-09-07 ~22:55 IST)

| Field | Value | Class |
|---|---|---|
| id / number / status | 213 / `INV-276710` / `issued` | VERIFIED |
| Issued | 2026-09-07 18:28:19 IST by user 3 | VERIFIED |
| Channel / source | `rdservice_in` / `commerce_order` / `RD3512919` / support 51539 | VERIFIED |
| Buyer | CHANDRAKANT GANPAT SARODE · `buyer_gstin` NULL · B2C | VERIFIED |
| Seller | Phil Technologies (P) Limited · `27AAICP1128M1Z7` | VERIFIED |
| Amounts | taxable 422.88 · CGST 38.06 · SGST 38.06 · IGST 0.00 · total 499.00 | VERIFIED |
| `e_invoice_records` | id 213 · provider `none` · status `skipped` · irn/ack NULL · no request payload | VERIFIED |
| skip_reason | `b2c_not_eligible` | VERIFIED |
| Outbox `statutory-irn:213` | none | VERIFIED |
| PDF document | `statutory-invoices/213.pdf` generated 18:28:19 · attempts 1 | VERIFIED |

IRN attempt: **NO — not attempted.** Eligibility skip at mint. No GSP/IRP HTTP. No retry state.

Today’s register (all 259 statutory invoices): every `e_invoice_records` row is `skipped`; **0** IRNs. **VERIFIED.** Integration-wide + B2C rule, not invoice-specific.

Live config: `einvoice.provider=none`, `worker_may_mint=false`, gateway `none`. **VERIFIED.**

---

## Root cause

1. **PDF text `IRN not submitted`** was hardcoded in `SimplePdfRenderer` for every invoice. It is debug copy, not a provider result. **VERIFIED (code).**
2. **No IRN exists** because this invoice is B2C (`EInvoiceEligibility` → `b2c_not_eligible`) and live IRN HTTP is disabled. **VERIFIED.**
3. This is **not** an application defect that should be “fixed” by submitting an IRN. GST e-invoice/IRN is for eligible B2B. Enabling `worker_may_mint` / binding a GSP is an Owner/CA gate, not this ticket.

**IRN code changes:** none. No fabricated IRN. No status flip to submitted. No production replay.

---

## SAC 998313 vs 998314

| Source | Value | Class |
|---|---|---|
| Chargeable line `description` (invoice + commerce) | `... (SAC - 998313) - (Sr. No. 10500255) ...` | VERIFIED |
| Line / commerce `hsn_sac` | `998314` | VERIFIED |

Desk copies both fields from the commerce ingest. It does not choose a SAC. Authoritative GST SAC class remains **UNKNOWN** (same as P-07-09-04 / P-07-09-05). Stored statutory data was **not** rewritten.

PDF now prints the stored description **and** the stored `HSN/SAC` column. Presentation is consistent with persisted fields. Changing 998313↔998314 would alter statutory line text/HSN and is **STOP** until CA/spoke authority.

---

## PDF design changes (new documents only)

`SimplePdfRenderer` is a customer tax-invoice layout: branded header, **TAX INVOICE**, invoice number/date, seller/buyer blocks, particulars with HSN/SAC, qty, rate, taxable, GST %, CGST/SGST/IGST, totals, amount payable.

IRN display rule:

- Print IRN + acknowledgement only when `e_invoice_records.status=submitted` **and** `irn` is non-empty.
- B2C / skipped / queued / failed: omit IRN. Do not print `IRN not submitted`, skip reasons, or provider errors.
- Never invent IRN, Ack No, QR, bank details, signatures, or declaration text.

`StatutoryDocumentService::generate` still will not overwrite an existing generated PDF file. **INV-276710’s stored PDF is unchanged** until an Owner-authorized regenerate (that is a write).

---

## Not performed

No production UPDATE/mint/IRN submit/PDF regenerate. No flag/credential change. No deploy. No other Radium projects.
