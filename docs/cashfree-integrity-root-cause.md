# Cashfree Integrity Failures — Root Cause

**Date:** 2026-08-05  
**Inputs:** [performance-release-blockers.md](./performance-release-blockers.md)  
**Suites:** `CashfreePaymentIntegrityTest`, `CashfreeWebhookReliabilityTest`, `OutboxProcessingTest`  
**Constraints:** No test fixes, no dashboard code changes, no Canvas.

---

## Verdict

| Rank | Item | Detail |
|------|------|--------|
| **Root cause** | `CASHFREE_SYSTEM_USER_EMAIL` from local `.env` (`info@radiumbox.com`) does not match the user created by these tests (`superadmin@radium.local`). `CashfreeWebhookProcessorService::resolveSystemUser()` throws **before** the payment `DB::transaction`. Webhook is marked `failed`; **no order, no outbox, no broadcast, no `OrderPaid`**. |
| **Confidence** | **Very high** | Probe captured exact `processing_error`; forcing `CASHFREE_SYSTEM_USER_EMAIL=superadmin@radium.local` turns **29/29** green in these three files. |
| **Fix effort** | **Low** | Pin email in `phpunit.xml` (`force="true"`) and/or `config([...])` in shared Cashfree test setup; optionally create the configured email instead of hardcoding `superadmin@radium.local`. |
| **Expected tests recovered** | **~18 reds in these 3 files** (29 tests: 17 failures + 1 error without override → 29 pass with override). Likely most of the broader Cashfree/outbox cluster that shares the same fixture pattern. |

Dashboard performance work is **truly unrelated**.

---

## Trace — where execution stops

```
Webhook POST /api/webhooks/cashfree
  → CashfreeWebhookController::handle
      → storeWebhook (status=received)                    ✅
      → CashfreeWebhookProcessorService::process
          → isSuccessfulPayment                           ✅ (PAYMENT_SUCCESS + SUCCESS)
          → persistSuccessfulPayment
              → attemptPersistSuccessfulPayment
                  → findExistingIncidentForPayment        ✅ (null)
                  → incidentReferenceService->generate()  ✅ (SC allocated outside txn)
                  → resolveSystemUser()                   ❌ STOP
                       RuntimeException:
                       "Cashfree system user is not configured or inactive."
          → catch → markWebhookFailed (status=failed)     ✅ logged
          → return fresh log (HTTP still 200 ok)          ✅
      ✗ DB::transaction (order / incident / outbox)       NEVER ENTERED
      ✗ outboxProcessorService->process()                 NEVER REACHED
      ✗ OrderPaid::dispatch                               NEVER REACHED
      ✗ DashboardBroadcastService::serviceCaseCreated     NEVER REACHED
```

Exact stop: `CashfreeWebhookProcessorService::resolveSystemUser()` at lines 314–324 / call at line 137 — **before** `DB::transaction` at line 139.

---

## Answers to the five questions

### 1. Why orders are not being created?

`resolveSystemUser()` looks up `User` by `config('cashfree.system_user_email')`.

| Source | Value |
|--------|-------|
| Local `.env` | `CASHFREE_SYSTEM_USER_EMAIL=info@radiumbox.com` |
| `config/cashfree.php` default | `superadmin@radium.local` |
| `phpunit.xml` | Sets `CASHFREE_VERIFY_SIGNATURE` / secret — **does not set** `CASHFREE_SYSTEM_USER_EMAIL` |
| Test fixtures | Create active `superadmin@radium.local` only; **do not** `config()` the email |

Under PHPUnit, Laravel still loads `.env` for unset keys → config resolves to `info@radiumbox.com` → user missing in the fresh test DB → exception → `markWebhookFailed` → `orders = 0`.

Probe evidence:

```
system_user_email=info@radiumbox.com
system_user_exists=no
log_status=failed
log_error='Cashfree system user is not configured or inactive.'
orders=0
outbox=0
```

### 2. Whether a transaction rollback occurs?

**No.** Failure is **pre-transaction**. Nothing to roll back for order/incident/outbox.

(Side effect: `IncidentReferenceService::generate()` may already have consumed an SC sequence number before the throw.)

### 3. Whether an event listener now exits early?

**No.** `OrderPaid` / `PostOrderPaidJournal` only run after a successful persist + deferred context. They are never dispatched on this failure path. Not an early-exit listener bug.

### 4. Whether queue/outbox creation changed?

**No product change required to explain these reds.** Outbox writes sit **inside** the payment transaction after order+incident create (`CashfreeWebhookOutboxWriter::writeDeferredOperations`). That code is never reached when system user resolution fails.

Deferred ops (automation monitor, `dashboard_broadcast`, RadiumBox enrichment) remain unchanged in shape; they simply never get pending rows.

### 5. Whether dashboard performance work is truly unrelated?

**Yes — unrelated.**

- Perf commit `5547a2d` does not touch Cashfree/outbox processors.
- Dashboard broadcast is a **post-commit outbox consumer** (`OPERATION_DASHBOARD_BROADCAST` → `DashboardBroadcastService::serviceCaseCreated`).
- Snapshot cache / KPI DOM / Team Activity lazy load cannot prevent order creation when the processor never enters the transaction.
- Repro: same suites go **29/29 green** with only an env email override — no dashboard code involved.

---

## Reproduction matrix

| Condition | `CashfreePaymentIntegrity` + `Reliability` + `OutboxProcessing` |
|-----------|------------------------------------------------------------------|
| Default local (`.env` → `info@radiumbox.com`) | **29 tests → 17 failures + 1 error + risky** |
| `CASHFREE_SYSTEM_USER_EMAIL=superadmin@radium.local` | **29/29 OK (153 assertions)** |

Representative greens under override:

- `CashfreePaymentIntegrityTest::test_dashboard_broadcast_exception_does_not_roll_back_paid_order`
- `OutboxProcessingTest::test_outbox_events_are_written_during_webhook_transaction`
- `CashfreeWebhookReliabilityTest::test_existing_webhook_behavior_remains_unchanged`

---

## Production implication

This failure mode means: **if** `CASHFREE_SYSTEM_USER_EMAIL` points at a missing/inactive user, PAYMENT_SUCCESS webhooks return HTTP 200 but mark the log `failed` and create **no** Desk order.

That is a **configuration correctness** issue for the environment, not a dashboard regression.

- Local/dev `.env` using `info@radiumbox.com` is fine **only if** that user exists and is active.
- Tests assume `superadmin@radium.local` without isolating config → **harness leak** from `.env`.
- CI without that `.env` key would use the config default and likely pass; local (and any env that sets a non-fixture email) fails hard.

---

## Recommended fix direction (not implemented here)

1. **Harness (preferred, low effort):** In `phpunit.xml`:

   ```xml
   <env name="CASHFREE_SYSTEM_USER_EMAIL" value="superadmin@radium.local" force="true"/>
   ```

   (`force="true"` so `.env` cannot override during tests.)

2. **And/or** in Cashfree feature test `setUp()`:

   ```php
   config(['cashfree.system_user_email' => 'superadmin@radium.local']);
   ```

   (Many other suites already do this; these three do not.)

3. **Optional product hardening:** Fail closed at boot/health check if system user missing; surface webhook `processing_error` in ops alerts (already stored on the log).

Do **not** “fix” by weakening order-count assertions.

---

## What is not the cause

| Suspect | Ruled out because |
|---------|-------------------|
| Transaction rollback after order create | Never entered transaction |
| `OrderPaid` / finance journal listener | Post-success only; unreached |
| Outbox writer API change | Unreached; works when system user resolves |
| Dashboard snapshot cache / KPI split | Unreached; override alone greens suites |
| Broadcast mock rolling back payment | Broadcast is post-commit outbox; order already missing before that |

---

## Ranking summary

| Dimension | Assessment |
|-----------|------------|
| Root cause | System-user email config/fixture mismatch → pre-txn throw → failed webhook, zero orders |
| Confidence | Very high |
| Fix effort | Low (hours or less) |
| Expected recovery | 29/29 in these three files; likely large share of RC-E Cashfree/outbox reds with the same fixture |

---

*End of investigation. No code was modified.*
