# Statutory invoice cancellation vs Wallet/OPM refund lifecycle (RadiumDesk-P-23-09-47)

Investigation only. **No production payment/refund mutations.** Documents verified application behavior as of production **v4.0.133** (`2e436f9b`).

## Executive separation (verified)

| Event | Implemented today? | Coupled to invoice cancel? |
|-------|-------------------|----------------------------|
| Statutory invoice cancellation | YES — `StatutoryInvoiceCancellationOrchestrator` | n/a |
| IRN cancellation | Partial — fail-closed when IRN submitted; WhiteBooks IRP cancel not implemented | Only when submitted IRN exists |
| Credit note minting | NO — `StatutoryInvoiceCreditNotePolicy` returns `not_required_current_release` | NO |
| Wallet refund | YES — separate `RefundRequest` workflow | **NO** |
| OPM (Original Payment Method) refund | YES — separate `RefundRequest` workflow (manual Cashfree execution) | **NO** |

**Verified business rule:** Invoice cancellation and customer payment refund are **separate events** (Option **C**, with method-specific execution — Option **D**).

Architecture reference: `docs/rd-central-finance-invoice-architecture.md` §11 — POS cancel after statutory issue lists customer money as “refund if paid out” via separate ops/workflow, not automatic on cancel.

---

## Canonical cancellation path (DeskPos statutory)

Entry: `StatutoryInvoiceController::cancel()` → `StatutoryInvoiceCancellationOrchestrator::cancel()`.

On success:

1. IRN pre-check/cancel when required (`StatutoryInvoiceIrnCancellationService`) — **fail-closed** on failure.
2. Linked POS inventory + `pos_sale` journal reversal (`StatutoryInvoiceLinkedInventoryReversalService` → `PosSaleService::cancelSale()`).
3. Statutory invoice status → `cancelled` (`StatutoryInvoiceService::cancel()` — internal only, not Finance UI direct).
4. Persist `statutory_invoice_cancellations` + audit `statutory_invoice.cancelled`.

**Does not:** create `RefundRequest`, call wallet APIs, call Cashfree refund APIs, post refund journals, change external wallet balances, emit `RefundCompleted`.

**Verified:** zero matches for `RefundRequest`, `wallet`, or `refund` under `app/Services/StatutoryInvoice/`.

---

## Wallet lifecycle (verified)

### What “Wallet” means in Desk

- Customer wallet **balance lives on spokes** (`rdservice.in`, `radiumbox.com` `users_wallet`). Desk has **no** wallet balance table (**VERIFIED** — `docs/desk-rdservice-in-wallet-refund-p-16-09-02.md`, `WalletLedgerReadService` read-only).
- Desk records split-tender wallet **facts** on `commerce_orders.wallet_tender_amount` during hardware channel ingest only (**VERIFIED** — not a wallet ledger).

### Payment path

| Step | Mechanism | Evidence |
|------|-----------|----------|
| Wallet debit at checkout | External storefront | **VERIFIED** — not performed in Desk |
| Split-tender recording | `ChannelIngestService` + `HardwareHandoffTenderContract` | **VERIFIED** |
| Desk GL for wallet tender | Not posted as cash | **VERIFIED** — architecture §10 |

### Refund path (after invoice cancellation)

```
RefundRequest created (manual/agent) → pending
  → approve (refunds.review) → pending_execution + approved_refund_method
  → complete (refunds.execute) → RefundExecutorResolver
      → wallet: WalletRefundExecutor → spoke POST /wallet-refunds
      → non-wallet: ManualRefundExecutor (UTR/txn attestation)
  → RefundCompleted event → RefundJournalService (Dr expense / Cr bank clearing)
  → case close + notifications (best-effort)
```

Key classes: `RefundRequestService`, `WalletRefundExecutor`, `RdServiceInWalletRefundClient`, `RadiumBoxWalletRefundClient`, `RefundJournalService`, `PostRefundCompletedJournal`.

### Answers A–I (Wallet)

| Question | Finding | Status |
|----------|---------|--------|
| A. On invoice cancel today? | Nothing wallet-related | **VERIFIED** |
| B. Auto-refund? | NO | **VERIFIED** |
| C. How initiated? | Explicit `RefundRequest` create → approve → complete | **VERIFIED** |
| D. Who authorized? | `refunds.create`, `refunds.review`, `refunds.execute` (role-gated) | **VERIFIED** |
| E. Duplicate refund? | `complete()` requires `pending_execution`; remote idempotency on `desk_refund_reference` (REF-*) | **VERIFIED** |
| F. Cancel ok, refund fails? | Invoice stays cancelled; refund remains `pending_execution` or throws (wallet fail-closed) | **VERIFIED** |
| G. Refund ok, later retry? | `complete()` on already-completed refund rejected; remote API idempotent replay | **VERIFIED** |
| H. Accounting entries? | `refund:{id}` journal — always Cr bank clearing (method ignored) | **VERIFIED** (known limitation) |
| I. Customer balance? | Changed only via spoke wallet API on successful wallet executor | **VERIFIED** |

### Wallet flags / fail-closed

- `RDSERVICE_IN_WALLET_REFUND_CREDIT_ENABLED` default **false**
- `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED` default **false**
- Unconfigured destination → wallet completion **blocked** (no silent manual fallback) — **VERIFIED**

### Wallet revoke (credit reversal)

- `RefundRevokeService` + reversal clients; `wants_service` outcome only.
- Reversal API on rdservice.in documented **NOT DEPLOYED** at P-17-09-03 — **VERIFIED** in docs; treat live reversal as **UNKNOWN** until re-verified in production config.

### Wallet reconciliation

- Read-only C360 ledger badges (`WalletLedgerReadService`); no automated reconcile job — **VERIFIED** gap.

---

## OPM lifecycle (verified)

### What “OPM” means

**OPM = Original Payment Method** — customer preference enum (`CustomerPreferredRefundMethod::Opm`), **not** a payment processor (**VERIFIED** — `config/refunds.php`, form labels).

Actual payout channel is `approved_refund_method` at approval time (`cashfree`, `bank_transfer`, `upi`, `wallet`, `other`) — **VERIFIED** — preference does not drive executor.

### Payment path (typical service order)

```
Cashfree payment (external) → POST /api/webhooks/cashfree
  → CashfreeWebhookProcessorService → orders.payment_amount, cashfree_payment_id, gateway IDs
  → OrderPaid → OrderPaymentJournalService (Dr bank / Cr revenue)
```

No Desk-initiated Cashfree payment capture for service orders — **VERIFIED**.

### Refund path (OPM / Cashfree)

```
RefundRequest (customer_preferred_method=opm default)
  → approve with approved_refund_method=cashfree (typical OPM path)
  → complete with execution_reference_no / execution_transaction_id
  → ManualRefundExecutor (provider: manual) — NO Cashfree refund API in Desk
  → RefundCompleted → refund journal
```

**Verified:** `docs/desk-refund-revoke-p-17-09-03.md` — “Cashfree refund executor in Desk: **NO**”.

### Answers A–J (OPM)

| Question | Finding | Status |
|----------|---------|--------|
| A. On invoice cancel? | Nothing | **VERIFIED** |
| B. Auto-refund? | NO | **VERIFIED** |
| C. How initiated? | Manual refund workflow + ops Cashfree dashboard | **VERIFIED** |
| D. Refund requested state? | `RefundStatus::Pending` → `PendingExecution` after approve | **VERIFIED** |
| E. Refund succeeded state? | `RefundStatus::Completed` + execution_reference_no/transaction_id populated | **VERIFIED** |
| F. Provider failure? | Ops cannot complete without UTR/txn; no automated provider failure state | **VERIFIED** |
| G. Timeout? | No async OPM callback; remains pending_execution until manual complete | **VERIFIED** |
| H. Async confirmation? | Not applicable for OPM manual path | **VERIFIED** |
| I. Duplicate prevention? | Status gate + journal idempotency `refund:{id}` | **VERIFIED** |
| J. Financial representation? | Refund journal Dr 5100 / Cr 1100 (bank clearing) regardless of method | **VERIFIED** |

### OPM revoke

- `RefundRevokeCustomerOutcome::WantsOriginalPaymentMethod` → `isImplemented() = false` — **VERIFIED** — blocked at revoke.

### Cashfree reconciliation

- Payment webhook reconciliation exists (`CashfreePaymentIntegrityService`, CLI) — **VERIFIED**.
- **No** Cashfree **refund** reconciliation — **VERIFIED** gap.

---

## End-to-end sequence (where the system stops)

### Wallet (service/commerce order with wallet-approved refund)

```
Payment captured (external wallet debit)          [external]
→ Order.payment_amount set (Cashfree/webhook or ingest)   [VERIFIED for service/Cashfree path]
→ Statutory invoice issued (separate workflow)            [VERIFIED]
→ Invoice cancelled (orchestrator)                        [VERIFIED — no wallet effect]
→ Refund eligibility (RefundCalculationService max)       [VERIFIED — manual; not auto-triggered]
→ Refund requested (RefundRequest pending)                [VERIFIED — manual]
→ Approved pending_execution (approved_refund_method=wallet) [VERIFIED]
→ WalletRefundExecutor → spoke API credit                 [VERIFIED when flags on]
→ RefundCompleted → refund journal                        [VERIFIED]
→ Audit + notifications                                 [VERIFIED]
```

**Stop point after invoice cancel:** system waits for explicit refund workflow; no auto-bridge.

### OPM (Cashfree original payment)

Same as above through invoice cancel, then:

```
→ Refund requested (preferred opm)                        [VERIFIED]
→ Approved (approved_refund_method=cashfree typical)    [VERIFIED]
→ Ops processes refund in Cashfree dashboard              [external/manual]
→ complete() with UTR/txn → ManualRefundExecutor        [VERIFIED]
→ RefundCompleted → refund journal                      [VERIFIED]
```

### DeskPos retail (P-23-09-46 UAT shape)

- Payment: POS `Cash` on `inventory_sales` — **VERIFIED** for sale 47.
- Refund workflow (`refund_requests`) requires `orders` FK — **VERIFIED** — **not wired to inventory POS sales**.
- Customer cash return after POS cancel: architecture says “Cash refund ops” — **VERIFIED** as **manual ops**, not Desk refund executor.

---

## Post-cancellation refund matrix

| Event | Wallet | OPM |
|-------|--------|-----|
| Invoice cancelled | No wallet action (**VERIFIED**) | No OPM action (**VERIFIED**) |
| Refund eligible | Manual calculation via `RefundCalculationService` on linked `Order`; not auto-set by cancel (**VERIFIED**) | Same (**VERIFIED**) |
| Refund initiated | `RefundRequestService::create()` (**VERIFIED**) | Same (**VERIFIED**) |
| Refund pending | `pending` / `pending_execution` (**VERIFIED**) | Same (**VERIFIED**) |
| Refund successful | `completed` + wallet API credit + `RefundCompleted` (**VERIFIED**) | `completed` + manual UTR + `RefundCompleted` (**VERIFIED**) |
| Refund failed | Wallet executor throws; stays pending_execution (**VERIFIED**) | Ops cannot complete without IDs (**VERIFIED**) |
| Retry | Re-attempt `complete()`; remote idempotent on REF-* (**VERIFIED**) | Re-attempt `complete()` with same validation (**VERIFIED**) |
| Duplicate request | Blocked by status + idempotency keys (**VERIFIED**) | Same (**VERIFIED**) |
| Customer balance/state | Spoke wallet credit/debit (**VERIFIED**) | Cashfree/bank credit via external ops (**VERIFIED**) |
| Finance journal | `refund:{id}` always bank clearing (**VERIFIED**) | Same (**VERIFIED**) |
| Audit | `refund.*` events (**VERIFIED**) | Same (**VERIFIED**) |
| Provider correlation | `desk_refund_reference`, wallet API response fields (**VERIFIED**) | `execution_reference_no`, `execution_transaction_id`, order `cashfree_payment_id` (**VERIFIED**) |
| Customer notification | `RefundNotificationService` on complete (**VERIFIED**, best-effort) | Same (**VERIFIED**) |

---

## Orchestrator recommendation (investigation conclusion)

**Keep `StatutoryInvoiceCancellationOrchestrator` cancellation-only.**

Do **not** directly invoke `WalletRefundExecutor` or manual OPM completion from cancellation unless product owner defines explicit coupling rules.

If future automation is desired, prefer:

```
statutory_invoice.cancelled (audit exists today)
  → optional future RefundEligibilityEvaluator (NOT IMPLEMENTED)
  → separate RefundRequest draft/approval workflow (human gate)
  → existing executors (wallet / manual OPM)
```

**UNKNOWN:** whether Finance should auto-create refund **drafts** after statutory cancel for channel orders — not established in code or owner docs.

---

## Production UAT evidence (P-23-09-46)

Disposable invoice **INV-0767294** (id 6397), sale 47, Cash POS:

- Cancellation succeeded; serial restored; audit row created.
- **No** `RefundRequest` created (**VERIFIED** — cancellation_rows=1, refund workflow untouched).
- **No** wallet/OPM provider calls (**VERIFIED**).

Protected **INV-0767292** (6347) unchanged after UAT.

---

## Remaining UNKNOWNs / blockers

1. **Credit note after statutory cancel** — architecture requires CN for post-issue cancel; current release explicitly does not mint (**VERIFIED** policy gap vs architecture §11).
2. **Refund journal method awareness** — wallet/OPM payouts still Cr bank clearing (**VERIFIED** limitation).
3. **Auto refund eligibility after cancel** — no code bridge; owner policy for channel vs POS unclear (**UNKNOWN**).
4. **OPM revoke** — not implemented (**VERIFIED**).
5. **Wallet reversal APIs** — deployment state on spokes may have changed since P-17-09-03 (**UNKNOWN** — re-verify before revoke UAT).
6. **POS cash refund tracking** — no Desk `refund_requests` path for `inventory_sales` Cash/UPI (**VERIFIED** gap; manual ops only per docs).

---

## Related documentation

- `docs/rd-central-finance-invoice-architecture.md` §11
- `docs/desk-rdservice-in-wallet-refund-p-16-09-02.md`
- `docs/desk-refund-revoke-p-17-09-03.md`
- `docs/finance-architecture-audit.md` (partially stale on wallet automation)
- `CHANGELOG.md` — “Desk cancel/return … does not refund UPI”

---

## Owner policy implementation (RadiumDesk-P-23-09-48)

**Policy:** Invoice cancellation does **not** automatically execute Wallet, OPM, or Cashfree refunds.

### Bridge (implemented)

```
StatutoryInvoiceCancellationOrchestrator
  → cancellation completed (inventory/IRN/statutory only)
  → StatutoryInvoiceRefundReviewService::snapshot() [read-only]
  → audit + cancellation.result_summary.refund_review
  → Finance invoice show UI
  → explicit RefundRequest (existing workflow)
  → WalletRefundExecutor / ManualRefundExecutor (unchanged)
```

Key classes:

- `StatutoryInvoiceLinkedOrderResolver` — resolves service `Order` from statutory invoice
- `StatutoryInvoiceRefundReviewService` — derived refund-review state; **no payment execution**
- `StatutoryInvoiceRefundReviewStatus` — `not_applicable`, `refund_review_required`, `refund_requested`, `pending_execution`, `completed`, `failed`

### POS boundary (preserved)

Desk POS `inventory_sales` → refund review status **Not applicable** with explicit manual-ops message. No POS refund system invented.

### Separate concerns (unchanged)

| Concern | Coupled to cancel? |
|---------|-------------------|
| Invoice cancellation | Primary orchestrator outcome |
| IRN cancellation | Separate gate when IRN submitted |
| Credit note | Not implemented (`not_required_current_release`) |
| Wallet refund | Independent — existing RefundRequest + WalletRefundExecutor |
| OPM refund | Independent — existing RefundRequest + ManualRefundExecutor |

### UNKNOWNs remaining

- Auto-create refund **drafts** after cancel — **not implemented** (owner chose explicit human action)
- Refund journal method awareness — unchanged limitation
- Wallet reversal API deployment on spokes — re-verify before revoke UAT
