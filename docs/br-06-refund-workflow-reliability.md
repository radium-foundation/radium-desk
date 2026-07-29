# BR-06 — Refund Workflow Reliability

## Problem

Customer 360 Refund Request had three production defects:

1. Partial refunds always failed because the modal submitted `reason` while the calculator required `partial_difference_reason`.
2. The same validation message appeared twice (toast + inline alert).
3. Email / WhatsApp notify checkboxes were hardcoded checked on first open.

## Architecture

Existing refund path is unchanged:

```
C360 / Refunds Create
  → WorkspaceRefundRequestActionService / StoreRefundRequestRequest
    → RefundRequestService::create()
      → RefundCalculationService::calculate() + assertValid()
```

Business rules stay in `RefundCalculationService::assertValid()` and continue to use `RefundDifferenceReason`.

### Field separation

| Field | Purpose |
|-------|---------|
| `reason` | Why the customer is requesting a refund |
| `partial_difference_reason` | Why the requested amount is less than maximum refundable |

`partial_difference_reason` is shown only when Amount < Maximum Refundable.

### Validation messaging

Refund request validation failures return the modal fragment with an inline alert and set `toast.show = false`. Toasts remain for success and unexpected/system failures.

### Notification defaults

Notify checkboxes default unchecked. Submitted FormData (or `old()` after redirect) is the only source of checked state after a validation failure.

## Rollback

Revert the BR-06 commit/tag. No migrations. No schema changes.
