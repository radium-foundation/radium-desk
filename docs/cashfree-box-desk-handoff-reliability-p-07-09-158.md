# Cashfree → Box Paid → Desk Handoff Reliability

## Root cause (RBP94)

Cashfree delivered the payment webhook to **Desk only** (`desk.radiumbox.com/api/webhooks/cashfree`). Box never received `/api/payments/cashfree/webhook`, so `PaidOrderFulfillmentService::confirmByFetching()` never ran, the Box order stayed unpaid, and `DeskOutboxService::enqueue()` never created a handoff. Desk enrichment recovery retried metadata only and could mark `radiumbox_sync_status=SYNCED` without commerce.

## Payment source of truth

Cashfree **server-side verified** payment status via Box `CaseFree::fetchOrder()` inside `PaidOrderFulfillmentService::confirmByFetching()`. Client return URLs and Desk webhook alone are not authoritative for Box paid state.

## Flow

1. Cashfree payment succeeds (webhook may arrive at Desk and/or Box).
2. Desk `OrderPaid` listener calls Box `POST /api/integrations/v1/cashfree/confirm-payment` for radiumbox.com hardware (`RBP*`, `RDE*`), passing the **business order id** as `gateway_order_id` (Box uses that as Cashfree `cf_order_id`; Desk’s numeric `gateway_order_id` from webhooks must not be sent).
3. Box verifies with Cashfree, marks order Paid idempotently, enqueues `desk_order_handoffs`.
4. Box outbox delivers to Desk `POST /api/v1/channel-orders` (existing HMAC + idempotency).
5. Desk ingest creates commerce order once; duplicates return 200 duplicate.
6. Scheduler `radiumbox:reconcile-handoff` recovers stale paid hardware missing commerce (SLA default 15 minutes).

## Idempotency

| Stage | Key |
|---|---|
| Box payment confirm | Cashfree fetch + `confirmGatewayPayment` transaction lock |
| Box handoff | `statutory:radiumbox_com:commerce_order:{source_id}` unique on `desk_order_handoffs` |
| Desk ingest | Same idempotency key + source uniqueness on `commerce_orders` |
| Desk Box confirm | Safe to retry; Box returns `already_paid` |

## State machine (Desk `radiumbox_sync_status`)

- `HANDOFF_PENDING` — enrichment may be complete but commerce/handoff still missing.
- `RECONCILIATION_REQUIRED` — Box confirm retry scheduled.
- `HANDOFF_FAILED` — non-retriable Box confirm failure.
- `SYNCED` — only when commerce exists for hardware, or non-hardware enrichment complete.

## RBP94 recovery (post-deploy)

Do **not** manually edit payment rows. After deploy:

```bash
# Desk targeted recovery (preferred for one order)
php artisan radiumbox:reconcile-handoff --order-id=RBP94

# Or batch reconcile (dry-run first)
php artisan radiumbox:reconcile-handoff --dry-run --limit=1
php artisan radiumbox:reconcile-handoff --limit=25
```

When Cashfree payment links onto a pre-existing Desk order, `OrderPaid` now fires after commit so the Box confirm listener runs immediately; batch reconcile remains the safety net.

Verify Cashfree payment `6470331477`, Box order `318703`, handoff idempotency `statutory:radiumbox_com:commerce_order:RBP94`.

## Rollback

Revert named-file deploy overlay for both repos. Disable `RADIUMBOX_PAYMENT_CONFIRM_ENABLED=false` and `RADIUMBOX_HANDOFF_RECONCILIATION_ENABLED=false` on Desk if needed. Box endpoint is auth-gated and no-op safe when not called.

## Provider action still required

Configure Cashfree merchant webhooks to include **Box** `https://radiumbox.com/api/payments/cashfree/webhook` (per-order `notify_url` already points there). Desk-only merchant webhook caused RBP94 split-brain.
