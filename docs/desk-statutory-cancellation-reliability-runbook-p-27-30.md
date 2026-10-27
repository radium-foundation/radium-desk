# Statutory cancellation reliability — operator runbook (P-27-30)

## Stuck operation states

| Status | Meaning | Action |
|--------|---------|--------|
| `irn_reconciliation_pending` | Cancel HTTP outcome unknown | Run reconcile command; do **not** blind-cancel IRN again. Get-IRN **2154 not-found alone does not prove cancellation** — escalate if only 2154 is returned |
| `irn_confirmed` / `local_pending` | IRN cancelled at provider; local finalize incomplete | Run reconcile command |
| `manual_review_required` | Finance/CA review | Inspect `last_error`, provider portal, audit log |
| `failed_retryable` | Transient IRN failure | Reconcile first, then retry via Finance cancel (same idempotency key) |

## Reconcile (non-destructive resume)

```bash
cd /var/www/radium-desk
php artisan desk:statutory-cancellation-reconcile --invoice=<statutory_invoice_id>
```

## Rollback constraint

Deploy rollback **does not** reverse an IRN already cancelled at the provider. Use NIC/WhiteBooks evidence plus local `e_invoice_records.response_payload.irn_cancelled`.

## Alerts

Search logs for `statutory_invoice_cancellation.manual_review_required` with `correlation_id`.
