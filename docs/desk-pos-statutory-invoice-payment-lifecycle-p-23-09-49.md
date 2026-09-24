# POS / office hardware statutory invoice payment lifecycle (RadiumDesk-P-23-09-49)

Verified implementation as of local branch `feat/statutory-invoice-cancellation-orchestrator`.

## Executive separation

| Concern | Source of truth |
|---------|-----------------|
| Invoice status | `statutory_invoices.status` |
| Payment status (Desk POS / Desk service) | `customer_payments` + `payment_allocations` |
| POS checkout tender | `inventory_sales.payment_method` / `payment_reference` (intent only) |
| Refund status (service orders) | `refund_requests` workflow |
| Refund status (POS cash/UPI/bank) | Manual ops — **not implemented in Desk** |
| IRN status | `e_invoice_records` |
| Credit note status | Not implemented (`not_required_current_release`) |

**Verified:** PDF `Payment Status: Paid` for Desk POS is no longer inferred from POS tender alone. It is derived from Finance payment allocations when the invoice channel is Desk POS or Desk service.

## Canonical payment record (reused architecture)

Desk already had:

- `customer_payments`
- `payment_allocations` → `statutory_invoice_id`

This task extended that model for **Desk POS** statutory invoices (previously Desk service only in Finance UI).

### Minimum fields

| Field | Storage |
|-------|---------|
| Invoice | `payment_allocations.statutory_invoice_id` |
| Sale/order | `statutory_invoices.inventory_sale_id` / `source_order_id` |
| Payment status | Derived by `StatutoryInvoicePaymentReadService` |
| Amount due | `statutory_invoices.invoice_value` |
| Amount received | `SUM(payment_allocations.amount)` |
| Payment date | `customer_payments.payment_date` |
| Payment method | `customer_payments.method` |
| Bank | `customer_payments.bank_name` (free text) |
| Branch | `customer_payments.bank_branch` (free text) |
| Reference/UTR | `customer_payments.reference` |
| Received by | `customer_payments.recorded_by` |
| Notes | `customer_payments.notes` |
| Idempotency | `customer_payments.idempotency_key`, `payment_allocations.idempotency_key` |

Migration added: `bank_name`, `bank_branch` on `customer_payments`.

## Payment status model

Supported derived states for allocation-backed invoices:

- Unpaid
- Partially paid
- Paid

`Refunded` / `Partially refunded` remain **derived from refund records** for service-order refunds only. POS refund tracking is **NOT SUPPORTED** in Desk today.

## Payment method model

Finance records free-text method on `customer_payments.method`.

POS checkout tender methods (`Cash`, `UPI`, `Bank Transfer`, etc.) remain on `inventory_sales.payment_method` and are shown separately as **POS tender at checkout**.

Bank transfer Finance recording requires:

- bank name
- branch
- reference/UTR

## Multiple payments

**VERIFIED:** Supported via multiple `customer_payments` allocated to the same statutory invoice until outstanding reaches zero.

## Audit

**VERIFIED:** `ServicePaymentService` writes:

- `customer_payment.recorded`
- `customer_payment.allocated`

via `AuditLogService`.

## Correction workflow

**UNKNOWN / gap:** No accounting-safe payment correction/reversal workflow exists for `customer_payments`. Incorrect entries require owner-approved manual correction process outside this release.

## PDF boundary

**VERIFIED:** `SimplePdfRenderer` unchanged.

**VERIFIED:** `StatutoryDocumentService` now supplies payment status/method/reference from allocation-backed payment evidence for Desk POS / Desk service invoices.

## Cancellation / refund matching

```
Invoice cancelled
→ StatutoryInvoicePaymentReadService (was invoice actually paid?)
→ StatutoryInvoiceRefundReviewService (POS: manual refund boundary)
→ explicit RefundRequest (service orders only)
→ existing Wallet / OPM executors
```

Cancellation orchestrator does **not** execute refunds.

## POS invoice 6746 investigation (read-only reference)

From prior production prompts and regression fixtures (**NOT modified in this task**):

| Item | Value | Source |
|------|-------|--------|
| POS reference | `POS-6746` / sale id **46** | Production UAT / prompt log P-23-09-27 |
| Statutory invoice | **INV-0767292** / id **6347** | Production UAT |
| Invoice amount | ₹984,238.00 | POS-6746 regression fixture |
| PDF payment method shown | Bank Transfer | `statutory_invoices.payment_method` copied from POS sale tender |
| PDF payment status shown (before this change) | Paid | Inferred from non-empty payment method |
| Finance `customer_payments` allocation | **UNKNOWN on production** — likely none before this release | Requires production read-only verification |
| Actual recorded payment evidence | **INFERRED:** POS tender only; not allocation-backed Finance receipt | Investigation conclusion |

**Changed:** NO — invoice 6746 not modified.

## Lifecycle diagram

```
Statutory invoice issued (Desk POS)
→ POS tender recorded on inventory sale (checkout intent)
→ Finance records customer_payment + allocation (canonical receipt)
→ payment status derived (Unpaid / Partial / Paid)
→ optional invoice cancellation (separate event)
→ refund review reads payment facts
→ POS refund remains manual ops
→ service-order refund remains RefundRequest workflow
```

## Related docs

- `docs/desk-statutory-invoice-cancellation-refund-lifecycle-p-23-09-47.md`
- `docs/rd-central-finance-invoice-architecture.md` §11

---

## Historical POS payment reconciliation (RadiumDesk-P-23-09-50)

### Historical period

| Field | Value |
|-------|-------|
| From | **2026-09-01 00:00:00** (app timezone) |
| To | Current reconciliation date (implementation rollout) |
| Canonical invoice date | `statutory_invoices.issued_at` |
| Scope | Desk POS / Office Hardware invoices sourced from `InventorySale` |
| Out of scope | Service invoices, unrelated channels, invoices outside the period, invoices already allocation-backed |

### Owner policy

For eligible historical POS invoices:

```
NO customer_payments / payment_allocations
  → Payment Status = UNPAID
  → Reconciliation = REQUIRED
  → Admin one-time Backfill Payment
  → Unpaid / Partially Paid / Paid (+ canonical payment where verified)
  → Reconciliation = COMPLETED + locked
```

**Old POS tender is NOT payment proof.** `inventory_sales.payment_method` and `statutory_invoices.payment_method` remain checkout/tender history only.

**Do not** auto-convert tender into `customer_payment` / `payment_allocation`.

**Do not** create fake payment records to preserve legacy “Paid” display.

### Derived reconciliation state

| Condition | Payment status | Reconciliation |
|-----------|----------------|----------------|
| Historical POS, zero allocation, no reconciliation row | Unpaid | Required |
| Historical POS, allocation exists | Derived from allocations | Completed |
| Historical POS, reconciliation row exists | Derived from outcome / allocations | Completed |

No reconciliation database row is created for every unpaid invoice. A row is written only when Admin completes backfill.

### One-time Admin backfill

| Item | Detail |
|------|--------|
| Permission | `finance.invoices.payment_backfill` |
| Roles | `admin`, `superadmin` only |
| Route | `POST finance/invoices/{invoice}/payment-backfill` |
| Source | `historical_pos_backfill` on reconciliation + `customer_payments.source` |
| Locking | `locked_at` set on completion; backfill action hidden thereafter |
| Duplicate protection | Unique `statutory_invoice_id`, idempotency key, invoice row lock |

#### Outcomes

| Admin selection | Payment record | Allocation | Result |
|-----------------|----------------|------------|--------|
| Unpaid | None | None | Unpaid + reconciliation completed + locked against normal receipt |
| Partially Paid | Verified `customer_payment` | Partial allocation | Outstanding balance remains; further Finance receipts allowed |
| Paid | Verified `customer_payment` | Full allocation | Paid |

Historical payment date is preserved on `customer_payments.payment_date`. Recording timestamp is separate (`created_at`, reconciliation `completed_at`).

### Controlled payment methods (backfill only)

HDFC D · HDFC M · INDUS · CASH · UPI - HDFC · UPI - INDUS · CARD · OTHER BANK · OTHER UPI

Method-specific validation is enforced server-side via `PosHistoricalPaymentMethod`.

### Audit

Event: `statutory_invoice.payment_backfill.completed`

Captures invoice, sale, admin user, outcome, amount, method, date, bank, branch, reference, source, and verification remark. Unpaid decisions are explicitly audited.

### Correction limitation

Completed backfill records and linked payments are locked. There is **no** accounting-safe correction/reversal workflow in this release. Errors require a future controlled correction process.

### Future invoice workflow

Creating a POS statutory invoice does **not** require payment. Finance may record canonical receipts afterward using the normal payment workflow (`finance.payments.record`). POS tender alone never proves payment.

### Cancellation / refund matching

Unchanged from P-23-09-49: cancellation does not auto-refund. Refund review reads allocation-backed payment facts (status, amount, method, date, bank/reference).

### POS invoice 6746 (first reconciliation case)

| Item | Value |
|------|-------|
| POS | POS-6746 / sale id **46** |
| Invoice | INV-0767292 / id **6347** |
| Implementation | Investigated only |
| Modified during P-23-09-50 | **NO** |
| Production payment/allocation created | **NO** |
| Production invoice/PDF changed | **NO** |

Separate production reconciliation/UAT prompt will follow.
