# Statutory cancellation reliability — operator runbook (P-27-30)

## Stuck operation states

| Status | Meaning | Action |
|--------|---------|--------|
| `irn_pending` | Dispatch slot reserved; HTTP may not have finished | Run reconcile command — **Get-IRN only**, no automatic second CANCEL. If IRN still active → `failed_retryable` for controlled Finance Continue |
| `irn_reconciliation_pending` | Cancel HTTP outcome unknown | Run reconcile command; do **not** blind-cancel IRN again. Get-IRN **2154 not-found alone does not prove cancellation** — escalate if only 2154 is returned |
| `irn_confirmed` / `local_pending` | IRN cancelled at provider; local finalize incomplete | Run reconcile command |
| `manual_review_required` | Finance/CA review | Inspect `last_error`, provider portal, audit log |
| `failed_retryable` | Transient IRN failure (not unknown) | Resume via Finance cancel or reconcile command — **Get-IRN runs before any new CANCEL**; unknown → `irn_reconciliation_pending` without dispatch |

## Dispatch attempt limit

Live provider `CANCEL` calls per operation are capped by `statutory_invoices.einvoice.irn_cancellation_max_dispatch_attempts` (default **3**, env `STATUTORY_EINVOICE_IRN_CANCELLATION_MAX_DISPATCH_ATTEMPTS`). Get-IRN reconciliation does not increment this counter. When the limit is reached the operation moves to `manual_review_required`.

Each allowed dispatch is **reserved atomically** on the `statutory_invoice_cancellations` row (`lockForUpdate` + increment) in a short DB transaction that commits **before** the WhiteBooks/NIC HTTP call. If the process crashes after reservation but before or during the HTTP call, the attempt remains consumed; do **not** auto-decrement the counter (avoids duplicate statutory CANCEL). Escalate via provider portal + Get-IRN reconcile; `irn_pending` with elevated `attempt_count` may need manual review.

## Reconcile (non-destructive resume)

```bash
cd /var/www/radium-desk
php artisan desk:statutory-cancellation-reconcile --invoice=<statutory_invoice_id>
```

## Rollback constraint

Deploy rollback **does not** reverse an IRN already cancelled at the provider. Use NIC/WhiteBooks evidence plus local `e_invoice_records.response_payload.irn_cancelled`.

## Alerts

Manual review is signaled via structured **`Log::warning`** (`statutory_invoice_cancellation.manual_review_required` with `correlation_id`). This is **not** guaranteed operator delivery (no email/queue/notification in this scope). Ensure log aggregation or monitoring watches this key; reliable notification delivery is a separate follow-up.

Search logs for `statutory_invoice_cancellation.manual_review_required` with `correlation_id`.
