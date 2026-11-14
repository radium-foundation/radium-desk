# RadiumBox implementation spec — Desk authoritative payment event v1

**For:** Owner-approved **radiumbox.com** follow-up task (not P-14-38).  
**Desk contract version:** `desk_authoritative_payment_v1`

## Endpoint

`POST /api/integrations/v1/desk/payment-events`

**Auth:** Existing integration Bearer token (`DESK_ORDER_API_TOKEN` / integration middleware).

**Headers (required):**

| Header | Purpose |
|--------|---------|
| `Authorization` | `Bearer {token}` |
| `X-Desk-Event-Id` | Unique event id (audit) |
| `X-Desk-Idempotency-Key` | Stable retry identity |
| `X-Desk-Signature` | `sha256=` HMAC of **raw JSON body** using signing secret |

## Request body (JSON)

See Desk `DeskAuthoritativePaymentEvent::payload()`.

Required validations on Box:

- `contract_version === desk_authoritative_payment_v1`
- `business_order_id` resolves to local `orders.ordercode` (lock row)
- `payment.status === paid`, `payment.currency === INR`
- `payment.amount` matches local payable within ₹0.05
- `payment.cashfree_payment_id` matches stored or empty field on Box order
- HMAC signature valid
- **Must not** call `CaseFree::fetchOrder` or other provider verify on this path

## Processing

1. Idempotent on `idempotency_key` + Box order id (return `duplicate_ack` if handoff already accepted).
2. In TX: transition `payment_status` → Paid using Desk references (no Cashfree).
3. `DeskOutboxService::enqueue()` if handoff not yet delivered.
4. Persist `desk_payment_events` row (suggested) with ack payload.

## Response `200`

```json
{
  "status": "accepted",
  "first_paid": true,
  "gateway_order_id": "RBP2138",
  "business_order_id": "RBP2138",
  "payment_status": "Paid",
  "handoff": {
    "id": 123,
    "status": "pending",
    "idempotency_key": "statutory:radiumbox_com:commerce_order:RBP2138"
  },
  "acknowledgement_id": "box-pay-ack:..."
}
```

Duplicate: `status: duplicate_ack`, `first_paid: false`.

Reject unauthenticated/invalid signature → `401`/`403`. Validation failure → `409` with `status: rejected`.

## Migration

1. Deploy Box receiver behind feature flag.
2. Desk staging: `RADIUMBOX_PAYMENT_CONFIRM_CONTRACT=desk_authoritative_v1`.
3. Shadow-compare legacy vs authoritative in dry-run (optional).
4. Cutover; deprecate `cashfree/confirm-payment` for Desk recovery (keep read-only dry-run until drained).

## Refunds

Unchanged: Desk remains refund authority; Box wallet credit endpoints stay downstream executors only.