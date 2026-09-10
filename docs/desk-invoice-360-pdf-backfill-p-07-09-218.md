# Invoice 360, final PDF, Annexure A, 05 Sep 2026 IRN backfill — P-07-09-218

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-218  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Remote:** `git@github.com:radium-foundation/radium-desk.git`  
**Branch:** `feat/irn-foundation-phase-a`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`  
**Host:** `desk.radiumbox.com`

Does not redesign the invoice/IRN architecture. Does not invent UQC/PIN/state. Does not use `deskd`. Unrelated UQC/catalog WIP remains uncommitted.

## Production boundary (re-verified)

| Axis | Value | Class |
|------|--------|--------|
| Hostname | `srv1910783` / `srv1910783.hstgr.cloud` | VERIFIED |
| Address | `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| DB | `radium_desk` @ `127.0.0.1` | VERIFIED |
| Host | `https://desk.radiumbox.com` `/up` HTTP 200 | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` 8.4.24 | VERIFIED |
| Deploy | named-file overlay. **Not** `deskd`. | VERIFIED |
| Worker | `queue:work redis` running as `ravi` | VERIFIED |
| Schedule | `bin/schedule-run.sh` every minute | VERIFIED |
| Provider | `STATUTORY_EINVOICE_PROVIDER=whitebooks` | VERIFIED |
| Worker mint | `STATUTORY_EINVOICE_WORKER_MAY_MINT=true` | VERIFIED |
| Policy | `STATUTORY_EINVOICE_ISSUANCE_POLICY=all_eligible_b2b` | VERIFIED |
| GSP IP | `187.127.129.16` | VERIFIED |
| Auto-issue POS | unchanged / false | VERIFIED |

## 360° Invoice drawer

Customer 360 Overview always shows **Invoice**.

When an invoice exists:

- Invoice No. / Invoice Date / Invoice Status (`Generated` or `Cancelled`)
- View Invoice (existing authenticated inline PDF)
- Download PDF (existing authenticated attachment)
- Share Invoice — `navigator.share` of the authenticated view URL when available; otherwise copy that same authorized URL. Not a public file.
- Existing Email / WhatsApp share actions retained

E-INVOICE block (authoritative `e_invoice_records` + eligibility, never inferred from invoice existence):

| State | UI |
|-------|----|
| Submitted IRN | Status: IRN Generated + IRN + Ack No + Ack Date |
| Eligible B2B, no IRN | Status: Pending |
| B2C | Status: Not Applicable. Reason: B2C / not eligible |
| Failed / incomplete / cancelled | Status: Failed + safe reason only |
| Policy excluded | Status: Not Applicable |

Authorization unchanged: incident `view` + invoice belongs to the case (404 otherwise). Guest redirected. Storage paths, tokens, SignedInvoice payloads are not exposed.

Empty state: `No invoice has been generated for this order.`

## Final PDF

Existing A4 `SimplePdfRenderer` remains the renderer (RADIUM mark, TAX INVOICE, hairlines). Presentation updates:

- First-page compact **Serial Numbers** (up to 8)
- If more remain: `* More serial numbers in Annexure A`
- `ANNEXURE A — Serial Numbers` with invoice number, order/reference, total count, complete unique serial list, statement it is part of the same invoice
- If all serials fit on page 1, no annexure
- IRN / Ack No. / Ack Date / real Signed QR matrix when issued
- No empty QR when IRN is absent
- UQC column unchanged
- Financial values still come from stored invoice rows (`regeneratePresentation()` does not recompute tax)

## IRN backfill methodology (05 Sep 2026 → now)

Authoritative date: `statutory_invoices.issued_at`.

Read-only inventory first (no GENERATE). Then oldest-first bounded batches:

```
Eligibility → existing IRN? → Get-IRN recovery → GENERATE only if genuinely absent
  and mapper submittable → persist → PDF rewrite → verify
```

`EInvoiceIrnRecoveryService` never GENERATE. `generateAfterConfirmedAbsent()` never GENERATE when IRN exists or status is processing/ambiguous. Ambiguous GENERATE stays recover-only.

Invoices lacking UQC / PIN / loc / buyer state are **NOT ISSUED — statutory data incomplete**. Values are not invented.

Skipped `worker_may_mint_off` rows are not bulk-requeued through the worker. Backfill is explicit and resumable (`desk:einvoice-backfill`).

## Read-only reconciliation (production, before GENERATE)

Date range: `2026-09-05 00:00:00` Asia/Kolkata through `2026-09-10 22:57:52`.

| Count | Value |
|-------|-------|
| Invoices examined | 1217 |
| Issued / cancelled | 1217 / 0 |
| Before 05 Sep 2026 | 0 |
| B2B | 51 |
| B2C / not eligible | 1166 |
| Already IRN | 3 |
| B2B eligible, mapper submittable, no IRN | 5 |
| B2B missing statutory data | 43 |
| Cancelled | 0 |
| Outbox einvoice pending | 0 |

Already IRN (do not GENERATE):

| Invoice | ID | Source | IRN prefix |
|---------|----|--------|------------|
| INV-076738 | 818 | RDE318388 | `c03672a5…` |
| INV-076746 | 1062 | RDE318400 | `974b217a…` |
| INV-076749 | 1123 | POS-000002 | `d858cf9f…` |

GENERATE-eligible after Get-IRN 2154 (oldest first):

| Invoice | ID | Source | Qty | Total |
|---------|----|--------|-----|-------|
| INV-076724 | 629 | RDE318516 | 10 | 24990.00 |
| INV-076729 | 645 | RDE318503 | 1 | 3049.00 |
| INV-076730 | 648 | RDE318500 | 1 | 2499.00 |
| INV-076735 | 793 | RDE318517 | 5 | 12495.00 |
| INV-076736 | 795 | RDE318490 | 10 | 30480.00 |

INV-076724 is the hardware example: 10 serials, B2B, mapper gaps empty, skip `worker_may_mint_off`.

43 remaining B2B without IRN: mapper gaps (`missing_uqc` 34, plus PIN/loc/is_servc/state mismatch combinations). Fail-closed. Exact list in `docs/desk-einvoice-reconciliation-2026-09-05-p-07-09-218.md`.

## Rollback

Restore overlay backup files under `/var/www/radium-desk/storage/app/private/overlays/p-07-09-218-*`. Do not delete invoice/IRN rows. Presentation PDF rewrite is not a financial reissue.

## Testing

Focused: Customer 360 invoice actions, PDF presentation, hardware P3 serials, backfill safety, Get-IRN recovery, processor recovery boundary, eligibility. Pint on dirty PHP. Pre-existing Vite `public/build/manifest.json` 500s on some layout GETs unchanged.
