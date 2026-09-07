# Statutory invoice PDF — final customer-facing design — RadiumDesk-P-07-09-47

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-47`  
**Mode:** Design/presentation correction only. Do not change statutory values. Do not submit IRN. Do not remint. Do not regenerate production PDFs. Do not deploy.

Ledger file is `docs/cursor-prompt-ledger.md`. Last committed row is **P-07-09-46**. `P-07-09-45` was the prior read-only “today’s invoices” lookup (chat report only). This ticket uses the next unused ID **P-07-09-47**.

---

## Identity

| Item | Value | Class |
|---|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (tracks `origin/main`) | VERIFIED |
| Before SHA | `9779e656749323939559bd5a9210f3dbecbd40c5` | VERIFIED |
| Worktree | This path. Sibling worktrees were not used. | VERIFIED |

---

## What was reviewed

`9779e656` already stopped printing `IRN not submitted` and only passes a submitted IRN into the payload. Rendered output of that commit still looked like a system dump: stacked text cards, duplicated seller, `GSTIN B2C`, `RD Technical Support ??? included`, `unset`, and the internal annexure identity `statutory:radiumbox_com:...`. **VERIFIED** by rendering `/tmp/desk-invoice-qa/before` from that SHA, then inspecting first-page PNGs.

This ticket rewrote `SimplePdfRenderer` as a commercial A4 tax invoice and inspected the **new** rendered pages, not only source.

---

## Sample PDFs rendered (local /tmp only)

Not committed. Not production records. No real IRNs.

| Sample | File | Purpose | Visual QA |
|---|---|---|---|
| A B2C MH CGST+SGST | `/tmp/desk-invoice-qa/after/a-b2c.pdf` | Seller/buyer, Maharashtra, no IRN | VERIFIED first page |
| B B2B + submitted IRN | `/tmp/desk-invoice-qa/after/b-b2b-irn.pdf` | Buyer GSTIN, IRN + ack (fixture only) | VERIFIED first page |
| C IRN absent | `/tmp/desk-invoice-qa/after/c-irn-absent.pdf` | B2B queued/failed/skipped presentation | VERIFIED first page |
| D multi-page + annexure | `/tmp/desk-invoice-qa/after/d-multipage.pdf` | Long address, wrapping lines, page 2 totals, annexure | VERIFIED pages 1–3 |

---

## Design decisions (presentation only)

- Navy header: **Phil Technologies (P) Limited**, seller GSTIN, **TAX INVOICE**, invoice number.
- Two boxed panels: Seller | Bill To. B2C GSTIN prints **Unregistered**. Missing values print `-`, never `unset` / `not recorded` / `B2C`.
- Particulars table: `# Description HSN/SAC Qty Rate Taxable GST % Total`, with a CGST/SGST/IGST sub-line under the description. Separate CGST/SGST/IGST columns were not forced; they were unreadable at A4 width. **INFERRED** from rendered column widths.
- Currency: **`Rs.`** + two decimals. Helvetica cannot draw `₹`. Mapping `₹` → `Rs.` is presentation only.
- Em dash `—` → `-` so companion line `RD Technical Support — included` no longer becomes `???`. Wording is the stored description.
- IRN box only when a non-empty submitted IRN is supplied. B2C / queued / failed / skipped: no IRN field, no skip reason, no provider error.
- Footer: seller legal name only. No invented bank details, declarations, or signatory.
- Multi-page: header + table header repeat; totals stay on the last invoice page; annexure is counted in `Page X of Y`.
- Annexure prints invoice number + order `source_id` + serials. Internal statutory identity / fulfilment id are not printed.

---

## SAC 998313 vs 998314

Unchanged. Description still contains `(SAC - 998313)`. Column still prints stored `hsn_sac` `998314`. Both remain visible. Authoritative SAC remains **UNKNOWN**. Statutory line data was not rewritten.

---

## Not performed

No production SELECT/UPDATE. No remint. No IRN submit. No regenerate of `INV-276710`. No deploy. No other Radium projects. Unrelated dirty/untracked docs were not staged.

---

## Validation

| Check | Result |
|---|---|
| Focused PDF tests | 27 passed |
| `tests/Feature/StatutoryInvoice` + `tests/Unit/StatutoryInvoice` | 133 passed |
| Hardware P3 annexure presentation | included in focused run |
| Pint (intended PHP only) | passed |
| PHPStan / Larastan | NO — no config in this repo |
