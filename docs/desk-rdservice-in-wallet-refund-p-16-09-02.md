# rdservice.in wallet refund credit (RadiumDesk-P-16-09-02)

Code/design only. Does **not** credit REF-2026-000300, modify production wallets, re-complete the refund, post GL, deploy, or call production APIs.

## Routing

`WalletRefundExecutor` no longer falls back to `ManualRefundExecutor`.

| Order owner (`BusinessOrderId`) | Destination |
|---|---|
| `rdservice.in` (RD / RDP / RIN) | `POST {RDSERVICE_IN_BASE_URL}/api/integrations/v1/wallet-refunds` |
| `radiumbox.com` (RDE / RB / RBX / RBP) | existing Box client, still isolated |
| anything else | fail closed |

Wallet-approved refunds stay `pending_execution` when the destination is unconfigured or the HTTP credit is not confirmed. Explicit bank/UPI/Cashfree/other refunds still use `ManualRefundExecutor`.

## Identity

Desk sends `order_id` + `desk_refund_reference` + optional `customer_email`. rdservice.in resolves `order_rdservice.userid`. Desk `users.id` is never a wallet owner.

## Idempotency

Retries reuse `source_system=radium_desk` + `desk_refund_reference` (the Desk REF). Already-closed refunds cannot be completed again (`complete()` requires `pending_execution`).

## Flags

- `RDSERVICE_IN_WALLET_REFUND_CREDIT_ENABLED` default **false**
- `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED` unchanged; not enabled by this gate

## Historical REF-2026-000300

Closed/manual. This branch does not replay it. A later production repair gate must call the new API once with the same REF after flags and the rdservice.in migration are live.
