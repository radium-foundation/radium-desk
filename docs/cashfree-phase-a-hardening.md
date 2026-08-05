# Cashfree Phase A — Production Hardening

**Date:** 2026-08-05  
**Inputs:** [cashfree-integrity-root-cause.md](./cashfree-integrity-root-cause.md), [performance-release-blockers.md](./performance-release-blockers.md)  
**Scope:** Test harness isolation, Platform Health visibility, webhook pre-flight, self-test service  
**No Canvas.**

---

## Problem

Successful Cashfree `PAYMENT_SUCCESS` webhooks could be marked `failed` with **zero Desk orders** when `CASHFREE_SYSTEM_USER_EMAIL` pointed at a missing/inactive user. HTTP still returned 200. Local PHPUnit suites leaked `.env` (`info@radiumbox.com`) while fixtures created `superadmin@radium.local`, masking the same failure mode in CI/dev.

---

## Root cause

`CashfreeWebhookProcessorService` resolves the automation actor by configured email **before** the payment transaction. If that user is absent/inactive, persistence never starts — no order, no outbox, no broadcast.

See [cashfree-integrity-root-cause.md](./cashfree-integrity-root-cause.md).

---

## Implementation

### 1. Test harness

| Change | Purpose |
|--------|---------|
| `phpunit.xml` `CASHFREE_SYSTEM_USER_EMAIL=superadmin@radium.local` with `force="true"` | Local `.env` cannot override the test actor |
| `Tests\Concerns\EnsuresCashfreeSystemUser` | Shared bootstrap: pin config + ensure active Super Admin exists |
| Wired into `CashfreePaymentIntegrityTest`, `CashfreeWebhookReliabilityTest`, `OutboxProcessingTest` | No duplicated ad-hoc config |

Payment flow assertions are unchanged (orders, outbox, broadcast still required).

### 2. `CashfreeHealthService` (self-test)

`app/Services/Cashfree/CashfreeHealthService.php` — read-only structured status:

- Configuration (signature + secret via existing `CashfreeConfigurationValidator`)
- System user (email configured, exists, active) → **Healthy** / **Missing**
- Database tables (`cashfree_webhook_logs`, `outbox_events`)
- Queue pending/failed
- Cashfree outbox pending/failed
- Latest webhook / last successful payment / last failed payment

Returns `CashfreeHealthReport` (`toArray()` for UI/JSON).

Does **not** create or mutate business rows.

### 3. Webhook pre-flight

On successful payment payloads, `CashfreeWebhookProcessorService::process()`:

1. Validates system user via `CashfreeHealthService` **before** SC allocation / transaction
2. On failure:
   - Writes explicit `processing_error` on the webhook log (`failed`)
   - Creates high-severity audit `cashfree.system_user_missing` on the webhook log
   - Does **not** continue into order/outbox/broadcast
3. On success: existing transaction + outbox + deferred dispatch + `OrderPaid` unchanged

SC reference allocation now occurs only after pre-flight passes (avoids burning sequence numbers on config failures).

### 4. Platform Health / Operations visibility

Existing Cashfree Health card (`admin.operations.partials.cashfree-health`) now surfaces:

| Field | Values |
|-------|--------|
| Webhook Secret | Configured / Missing / Not required |
| System User | Healthy / Missing (+ configured email, role label) |
| Queue | pending / failed |
| Outbox | pending / failed (Cashfree deferred events) |
| Latest webhook | timestamp |
| Last successful payment | timestamp |
| Last failed payment | timestamp |

`OperationsCashfreeHealthService` and `CashfreeIntegrationHealthProbe` consume `CashfreeHealthService`. Missing system user marks Cashfree unhealthy. No dashboard redesign.

---

## Health checks (operator view)

**Healthy system user:** configured email resolves to an active user.

**Missing system user:** empty email, user not found, or inactive/soft-deleted — Platform Health shows **Missing** and the configured email.

**Webhook secret:** when `CASHFREE_VERIFY_SIGNATURE=true`, secret must be present; otherwise “Not required”.

---

## Operational behaviour

| Scenario | Behaviour |
|----------|-----------|
| System user healthy | Unchanged success path: order + incident + outbox + deferred ops |
| System user missing | Webhook stored → pre-flight fail → `processing_status=failed` + explicit error + high-severity audit → HTTP still handled by controller (no order) |
| Signature misconfigured | Existing validator / probe degraded path unchanged |

---

## Recovery

1. Set `CASHFREE_SYSTEM_USER_EMAIL` to an **existing, active** Desk user (production typically the automation/superadmin actor).
2. Confirm Platform → Integration Health → Cashfree expand shows System User **Healthy**.
3. Re-process failed PAYMENT_SUCCESS webhooks via existing recovery / Webhook Explorer tooling (integrity recovery commands remain the recovery path for paid-without-order).

---

## Tests

| Suite | Coverage |
|-------|----------|
| Existing Cashfree / Outbox / Reliability | Still green; harness isolated from `.env` |
| `CashfreeHealthServiceTest` | Healthy / Missing system user + report fields |
| `CashfreeSystemUserPreflightTest` | Fail + audit; success still creates order |
| `CashfreeHealthVisibilityTest` | Operations widget fields |

```bash
PAO_DISABLE=1 ./vendor/bin/phpunit \
  tests/Feature/CashfreePaymentIntegrityTest.php \
  tests/Feature/CashfreeWebhookReliabilityTest.php \
  tests/Feature/OutboxProcessingTest.php \
  tests/Unit/Cashfree \
  tests/Feature/Cashfree
```

---

## Explicitly unchanged

- Payment persistence transaction shape (order → incident → mark processed → outbox write)
- Deferred operations (automation monitor, dashboard broadcast, RadiumBox)
- Finance `OrderPaid` journal listener
- Cashfree integrity recovery / reconcile algorithms

---

## Code map

| Piece | Path |
|-------|------|
| Health self-test | `app/Services/Cashfree/CashfreeHealthService.php` |
| Health DTO | `app/Data/Cashfree/CashfreeHealthReport.php` |
| Pre-flight + audit | `app/Services/Cashfree/CashfreeWebhookProcessorService.php` |
| Ops widget | `app/Services/Operations/OperationsCashfreeHealthService.php` |
| Probe | `app/Infrastructure/IntegrationHealth/Probes/CashfreeIntegrationHealthProbe.php` |
| UI | `resources/views/admin/operations/partials/cashfree-health.blade.php` |
| PHPUnit pin | `phpunit.xml` |
| Shared test trait | `tests/Concerns/EnsuresCashfreeSystemUser.php` |

---

*End of Phase A hardening note.*
