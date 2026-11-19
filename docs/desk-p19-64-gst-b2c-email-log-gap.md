# P-19-64 — Email-log confirmation gap (GST B2C conversion)

**ChatGPT ID:** P-19-64  
**Status:** Documented gap — **not** implemented in this change set.

## Owner requirement

Capture and retain **email-log confirmation** explaining what the GSTIN issue was, without sending customer emails in this task.

## Repository inspection (VERIFIED)

- **Audit trail today:** `commerce_order_buyer_gst_verifications` + `commerce_order_buyer_gst_b2c_conversions` (submitted GSTIN, provider hash, approver, timestamp, masked invoice remark).
- **Customer 360:** presents statutory downgrade note via `ServiceStatutoryGstB2bClassification::c360NoteForIssuedInvoice()`.
- **Operational email:** `StatutoryInvoiceShareService` and template `emails/statutory-invoice` send invoice attachments on explicit share — **not** wired to B2C conversion approval.
- **No dedicated table or workflow** links “conversion approved” → persisted outbound email log with GST issue classification.

## Smallest compatible follow-up (proposed, **PENDING Owner**)

1. On `attachIssuedStatutoryInvoice` or post-mint hook, append an **internal** audit entry (e.g. `AuditLogService`) with conversion id, masked GSTIN, `review_reason`, and invoice number — **no customer send**.
2. Optional later phase: Finance distribution list notification using existing mail + `AuditLog` — gated by separate Owner authorization and template review.

## Explicit boundary

This P-19-64 delivery **does not** send email, fabricate confirmations, or claim email was sent.