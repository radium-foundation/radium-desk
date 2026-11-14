# P-14-38 — Enforce Desk sole payment authority (contract migration)

**Global ID:** P-14-38  
**Cursor ledger:** **RadiumDesk-P-14-38**  
**Base:** P-14-37 `d654b61d0`

## Existing contract (VERIFIED)

| Step | Behaviour |
|------|-----------|
| 1 | Desk Cashfree webhook sets authoritative paid fields on `orders`. |
| 2 | Desk outbox → `ConfirmRadiumBoxPaymentOnOrderPaid`. |
| 3 | Desk `POST /api/integrations/v1/cashfree/confirm-payment` (Bearer token). |
| 4 | Box `PaidOrderFulfillmentService::confirmByFetching()` → **`CaseFree::fetchOrder()`** (spoke Cashfree). |
| 5 | Box marks Paid + `DeskOutboxService::enqueue()` handoff. |
| 6 | Box → Desk `POST /api/v1/channel-orders`. |

**Violation:** Steps 3–4 delegate **provider verification** to RadiumBox (second payment authority).

## Proposed contract (`desk_authoritative_v1`)

Desk sends signed **`desk_authoritative_payment_v1`** JSON to Box `POST /api/integrations/v1/desk/payment-events`. Box validates token + HMAC, applies paid transition without Cashfree HTTP, enqueues handoff idempotently, returns correlated ack.

Desk config: `RADIUMBOX_PAYMENT_CONFIRM_CONTRACT=desk_authoritative_v1` (default remains **`legacy_cashfree_via_box`** until Box ships receiver).

## RBP2138 relevance

Desk already paid; legacy path still invoked Box Cashfree (timeout). Authoritative path eliminates redundant provider call on Box (Box spec + P-14-34 fast path).

## Claim status

**Sole payment authority is NOT established in production** until:
1. Box implements spec (`docs/radiumbox-desk-authoritative-payment-event-v1-spec.md`).
2. Contract flag flipped on Desk.
3. Legacy route retired per migration plan.

**Production changes: NO — Not performed.**