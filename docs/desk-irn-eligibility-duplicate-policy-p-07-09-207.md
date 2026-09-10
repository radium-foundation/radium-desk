# Eligibility, duplicate-IRN mapping, hardware policy — P-07-09-207

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-207  
**Date:** 2026-09-10  
**Before SHA:** `e30e89709ccfc4f49ed40bb25fd85e53e98266ed`

Automatic issuance remains OFF. No production WhiteBooks calls. No deploy. No requeue.

## What this gate implemented

Fail-closed e-Invoice eligibility:

| Guard | Reason | GENERATE |
|---|---|---|
| Cancelled | `invoice_cancelled` | no |
| Status not `issued` | `invalid_invoice_status` | no |
| Not `tax_invoice` | `unsupported_document_type` | no |
| `issued_at` before 2026-09-01 | `outside_invoice_scope` | no |
| No / invalid GSTIN | existing B2C / invalid | no |
| Incomplete stored GST | `incomplete_gst` | no |

Queued then cancelled: processor skips, outbox can complete, GENERATE = 0.

Cancelled while `processing`/`ambiguous`: **recover-only** (P-206). Local cancel does not undo GENERATE. Get-IRN may persist IRN onto a cancelled invoice. Existing IRN is never cleared.

Desk has no `voided` status. Statutory statuses are only `issued` and `cancelled`. Credit/debit exist as document types; minting currently issues tax invoices (`DocTyp=INV`). Receipts/proforma are not statutory invoice documents.

## Duplicate WhiteBooks GENERATE errors

**Verified duplicate GENERATE codes: none.**

| Source | Finding |
|---|---|
| Production GENERATE | One success (P-203). No duplicate GENERATE envelope observed. |
| Production Get-IRN | `2154` = IRN not found (P-196). Not a GENERATE duplicate signal. |
| WhiteBooks Postman | Error stubs are empty 404/500. No GENERATE duplicate JSON. |
| NIC `E-invoicing-error-codes.docx` | Mentions 2150/2154. **Not WhiteBooks production proof** (P-196). |

`WhitebooksResponseMapper::VERIFIED_GENERATE_DUPLICATE_ERROR_CODES` is empty. GENERATE HTTP 200 with no IRN remains `permanentFailure` (`missing_irn`), including NIC-looking `2150`. Get-IRN `2154` stays `irn_not_found` and does not GENERATE.

When a WhiteBooks GENERATE duplicate envelope is production-verified, add that exact `errorCode` to the list. The mapper will then return Ambiguous → Get-IRN only (P-206).

## Hardware vs service — OWNER DECISION REQUIRED

Current eligibility is **all valid B2B tax invoices**. There is no hardware allowlist.

Production mix from P-205 (do not requeue):

- 42 `rdservice_in` SAC 99 (services)
- 8 `radiumbox_com` HSN 84/85
- 1 POS hardware already has IRN

### Option A — hardware-only automatic issuance

**Affected:** `desk_pos` and `radiumbox_com` lines with non-99 HSN (`IsServc=N`). RD-service/AMC SAC 998313 would skip.

**Path:** `EInvoiceEligibility` after B2B checks, using `EInvoiceServiceClassification` / HSN family / channel. Fail closed if `IsServc` is null.

**Current:** not implemented.

**Required:** new skip reason, tests, no requeue of the 49 skipped B2B rows.

**Risk:** service B2B legally required for IRN would be skipped until a later policy change.

**Production:** enabling worker+provider would GENERATE Box/POS B2B hardware only. The 42 service invoices stay skipped unless requeued.

### Option B — all eligible B2B

**Affected:** every issued tax invoice with valid GSTIN, including RD-service.

**Path:** current `EInvoiceEligibility` after this gate.

**Required:** owner acceptance that SAC 99 B2B will GENERATE when issuance is enabled.

**Risk:** 42 live service invoices would GENERATE if those skipped rows are requeued. New service invoices would GENERATE as they mint.

### Option C — other existing policy

No third issuance allowlist exists. `service_sac` is for SAC/IsServc mapping, not GENERATE permission.

```text
OWNER DECISION REQUIRED:

Choose:
A — hardware-only
B — all eligible B2B
C — other, specify
```

Not implemented. Not guessed.

## Production

```text
STATUTORY_EINVOICE_PROVIDER=none
Gateway=NullEInvoiceGateway
worker_may_mint=false
auto_issue_on_pos_complete=false
```

**GENERATE / Get-IRN / Cancel / invoice / enablement / deploy:** NO — Not performed.
