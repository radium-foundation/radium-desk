# Desk refund revoke — P-17-09-03

**Date:** 2026-09-17  
**Branch:** `feat/rdservice-in-wallet-refund-parse-fix`  
**Status:** Desk implementation complete · wallet reversal API **not deployed on rdservice.in**

---

## Implemented: “Wants the service” branch

Admin **Revoke Refund** on completed wallet refunds:

1. Select customer outcome + mandatory revoke reason  
2. Reverse wallet credit via integration client (fail-closed until configured)  
3. Mark refund `status = revoked` (explicit enum — not silent `closed` reinterpretation)  
4. Automatically create `commercial_service_restorations` row  
5. Commercial state → **Service Restored** → Assign Service Reference allowed  

Distributed-state safety via `refund_revocation_attempts` (`pending` → `wallet_reversed` → `completed`).

---

## Cross-project blocker: rdservice.in wallet reversal API

**Re-verified at current HEAD on production (`187.127.129.16`):**

| System | Endpoint | Status |
|--------|----------|--------|
| rdservice.in | `POST /api/integrations/v1/wallet-refunds` | **LIVE** (credit only) |
| rdservice.in | `POST /api/integrations/v1/wallet-refund-reversals` | **NOT DEPLOYED** |
| radiumbox.com | wallet-refund-reversals | **NOT DEPLOYED** |

Desk ships `RdServiceInWalletRefundReversalClient` calling the documented reversal path, gated by:

```env
RDSERVICE_IN_WALLET_REFUND_REVERSAL_ENABLED=false
```

**Required rdservice.in gate (do not implement in this Desk gate):**

- `POST /api/integrations/v1/wallet-refund-reversals`
- Auth: same integration token as credit  
- Body: `source_system`, `desk_refund_reference`, `order_id`, `amount`, `currency`, `idempotency_key`, optional `original_wallet_transaction_id`, optional `customer_email`  
- Behavior: insert compensating **debit** row in `users_wallet`; preserve original credit row; idempotent on `(source_system, idempotency_key)` or `(source_system, desk_refund_reference, reversal)`  
- Response `data`: `wallet_reversal_transaction_id`, `wallet_reversal_reference`, `debit`, `desk_refund_reference`, `balance`

Until deployed, Desk revoke **fails closed** — no attestation checkbox bypass.

---

## Original-payment-method branch (NOT implemented)

**Finding:** Original-payment-method refund branch requires a separate implementation gate.

| Check | Result |
|-------|--------|
| Cashfree refund executor in Desk | **NO** — wallet uses `WalletRefundExecutor`; Cashfree-approved refunds use `ManualRefundExecutor` only |
| Cashfree original-payment reversal API wired | **NO** |
| Order stores Cashfree IDs | **YES** — `cashfree_payment_id`, `gateway_order_id`, `gateway_payment_id`, `bank_reference` on `orders` |
| Safe auto Cashfree refund on revoke | **NO** |

Recommended future flow (document only):

1. Reverse wallet credit first (same as service branch)  
2. Initiate Cashfree payment refund with idempotency on `desk_refund_reference`  
3. Terminal refund state distinct from `revoked` + service restored  

Do **not** substitute Cashfree merely because original payment was UPI.

---

## Permissions

- `refunds.revoke` — Admin, Operations Admin, Super Admin  
- Reuses `commercial.service.restore` infrastructure for automatic restoration after revoke  

---

## Production safety

Do **not** revoke RD3147 / debit wallet 2559 until:

1. rdservice.in reversal API deployed  
2. `RDSERVICE_IN_WALLET_REFUND_REVERSAL_ENABLED=true` on Desk production  
3. Separate controlled ops gate for REF-2026-000296  
