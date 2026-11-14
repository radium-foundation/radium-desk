# P-14-37 — Desk payment authority & durable RadiumBox sync

**Global ID:** P-14-37  
**Cursor ledger:** **RadiumDesk-P-14-37**

## Ownership boundary (VERIFIED — this repo)

| Authority | Owner | Desk evidence |
|-----------|--------|----------------|
| Cashfree webhook verify + Desk paid state | **Desk** | `CashfreeWebhookProcessorService`, `orders.cashfree_payment_id` |
| Refunds / wallet credits | **Desk** (+ spoke executors per contract) | Central wallet, refund services |
| RadiumBox `confirm-payment` (server-side spoke paid + handoff enqueue) | **Desk invokes**, **Box executes** | `RadiumBoxPaymentConfirmationClient` |
| Channel ingest / `commerce_orders` | **Desk persists** after Box handoff delivery | `ChannelIngestService` |
| Box local `payment_status` for storefront session | **Box** (Desk does not override) | Hub-spoke doc §2 |

Downstream apps must not call Cashfree to establish paid truth for Desk orders. Desk recovery calls Box `confirm-payment`, which performs spoke-side provider verification.

## Lifecycle (post P-14-37)

```mermaid
sequenceDiagram
    participant CF as Cashfree
    participant Desk as Radium Desk
    participant OB as outbox_events
    participant Box as radiumbox.com

    CF->>Desk: webhook (paid)
    Desk->>Desk: TX commit order paid
    Desk->>OB: radiumbox.payment_confirm (idempotent)
    OB->>Box: POST confirm-payment
    Box-->>Desk: paid + handoff id
    Desk->>Desk: radiumbox_sync_status HANDOFF_PENDING
    Box->>Desk: POST channel-orders (ingest)
    Desk->>Desk: commerce_orders + SYNCED
```

**Safety nets (unchanged):** `radiumbox:reconcile-handoff`, `radiumbox:recover-sync`, `outbox:process` (cron light tick).

## RBP2138 findings

| Finding | Classification |
|---------|----------------|
| Desk order **73007** / **RBP2138** Cashfree-verified | **VERIFIED** (prior read-only prod probe) |
| `radiumbox_sync_status` **RECONCILIATION_REQUIRED** | **VERIFIED** |
| Box `confirm-payment` **15s timeout** (P-14-34) | **VERIFIED** / doc |
| Commerce **CO-022155** present, **no** `hardware_fulfilments` | **VERIFIED** |
| Paid Desk state reversed on timeout | **NO** — payment fields retained (**VERIFIED** code) |
| Durable outbox row for confirm-payment before P-14-37 | **UNKNOWN** / likely **none** (sync listener only) |
| `outbox:process` retry after listener failure | **INFERRED** partial — only Cashfree deferred ops in webhook TX |
| Hardware hidden from workspace | **VERIFIED** fixed P-14-36 |

## P-14-37 implementation

- `RadiumBoxPaymentConfirmationOutboxWriter` — idempotent `radiumbox.payment_confirm:{order_id}`.
- `ConfirmRadiumBoxPaymentOnOrderPaid` — enqueue + optional immediate `processAggregate(order)`.
- `RadiumBoxPaymentConfirmationOutboxProcessor` — retriable failures bubble to outbox backoff.
- `RadiumBoxPaymentSyncDiagnostics` — cohort of paid RBP/RDE/RB* without commerce in retriable sync states.

P-14-36 serial-gap visibility **preserved** (commit `26bc279b`).

## RadiumBox follow-up (separate repo — Owner)

P-14-34: fast path when Box already Paid — reduces confirm-payment latency. **Not modified in this task.**

## Production

**Database changes: NO — Not performed.**  
**Deploy: NO — Not performed.**