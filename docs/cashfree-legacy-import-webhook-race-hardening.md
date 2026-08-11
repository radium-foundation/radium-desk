# Cashfree Legacy-Import / Webhook Race Hardening

**Date:** 2026-08-11  
**Production anchor:** `main` @ `5f0d3892` — RD3483568 / order PK 33634 / webhook #40152  
**Related:** [cashfree-phase-a-hardening.md](./cashfree-phase-a-hardening.md), [cashfree-integrity-root-cause.md](./cashfree-integrity-root-cause.md)

---

## Problem

A legacy RadiumBox import and a Cashfree `PAYMENT_SUCCESS` webhook for the same business order can arrive within the same second. When the Desk order already exists under a different **case** of `order_id` (e.g. stored `rd3483568`, webhook payload `RD3483568`):

1. **Integrity batch index** treated the order as missing (`recoverable` / `unresolved`) because lookup keys were case-sensitive.
2. **Webhook processor** INSERTed a new order instead of linking payment metadata to the existing row, causing `orders_order_id_unique` duplicate-key failure.
3. **Recovery tooling** (`heal-missed-batch`, misleading `reprocess-failed` dry-run) was not the correct fix when a Desk shell order already existed.

---

## Code changes

### 1. Integrity assessment index (`CashfreePaymentIntegrityService`)

- Business `order_id` values are normalized with `strtoupper(trim())` at the assessment-index boundary.
- `existingBusinessOrderIds()` queries with `UPPER(order_id)` and indexes normalized keys.
- `businessOrderIdExists()` uses the same normalization for both index and direct DB lookups.
- Original stored `order_id` values in the database are **not** modified.

### 2. Webhook processor (`CashfreeWebhookProcessorService`)

On successful payment webhooks, before INSERT:

1. If `cashfree_payment_id` is already linked → idempotent no-op (unchanged).
2. Else if a Desk order exists for the same business `order_id` (case-insensitive) → **link** Cashfree payment fields onto the existing order and mark the webhook processed against the order's latest incident.
3. Else → existing create-order path (unchanged).

Additional safety:

- Duplicate-key (`1062` / `orders_order_id_unique`) recovery links to the existing order when provable.
- Only empty payment columns are populated; customer/product/serial/service-case data are preserved.
- Audit event: `cashfree.payment_linked_to_existing_order`.

### 3. Reprocess dry-run (`ReprocessFailedCashfreeWebhooksCommand`)

`predictOutcome()` and skip detection now delegate to `CashfreePaymentIntegrityService::assessLog()`, so existing business orders (including mixed-case) are reported as **skipped (already exists)** instead of **would recover**.

---

## One-time production repair — RD3483568

**Do not run until this hardening is deployed.**  
**Do not use** `cashfree:heal-missed-batch` or `cashfree:reprocess-failed` for this case.

| Field | Value |
|-------|-------|
| Order PK | `33634` |
| Business order ID (stored) | `rd3483568` |
| Cashfree payment ID | `6206001295` |
| Failed webhook log | `#40152` |
| Service case | `SC34409` (incident PK `34409`) |

### Preconditions

- [ ] Hardening release deployed to production
- [ ] Operator has production DB write access (controlled maintenance window)
- [ ] SC34409 remains the authoritative incident for order 33634

### Step 0 — Snapshot (read-only)

```sql
-- Order snapshot
SELECT id, order_id, cashfree_payment_id, payment_amount, payment_method,
       payment_date, bank_reference, gateway_order_id, gateway_payment_id,
       customer_name, serial_number, product_name, updated_at
FROM orders
WHERE id = 33634;

-- Webhook snapshot
SELECT id, cf_payment_id, processing_status, processing_error,
       incident_id, processed_at, received_at
FROM cashfree_webhook_logs
WHERE id = 40152;

-- Incident snapshot
SELECT id, reference_no, order_id, status, source
FROM incidents
WHERE id = 34409;
```

Save query output to the change ticket.

### Step 1 — Verify current state

Expected before repair:

- `orders.id = 33634`, `order_id = 'rd3483568'`, `cashfree_payment_id IS NULL`
- `cashfree_webhook_logs.id = 40152`, `processing_status = 'failed'`, `incident_id IS NULL`
- No other order row with `cashfree_payment_id = '6206001295'`
- `incidents.id = 34409` linked to `orders.id = 33634`

```sql
SELECT COUNT(*) AS conflicting_payment_links
FROM orders
WHERE cashfree_payment_id = '6206001295'
  AND id <> 33634;

SELECT COUNT(*) AS order_incident_link
FROM incidents
WHERE id = 34409 AND order_id = 33634;
```

**Stop** if `conflicting_payment_links > 0` or `order_incident_link <> 1`.

### Step 2 — Apply payment link (idempotent)

Use verified values from webhook #40152 payload (payment time, amount, method, bank/gateway refs). Example skeleton — **replace placeholders with payload-verified values**:

```sql
START TRANSACTION;

UPDATE orders
SET
  cashfree_payment_id = '6206001295',
  payment_amount = :verified_amount,
  payment_method = :verified_method,
  payment_date = :verified_payment_at,
  bank_reference = :verified_bank_reference,
  gateway_order_id = :verified_gateway_order_id,
  gateway_payment_id = :verified_gateway_payment_id,
  updated_by = (SELECT id FROM users WHERE email = :cashfree_system_user_email LIMIT 1),
  updated_at = NOW()
WHERE id = 33634
  AND (cashfree_payment_id IS NULL OR cashfree_payment_id = '6206001295');

UPDATE cashfree_webhook_logs
SET
  processing_status = 'processed',
  processing_error = NULL,
  incident_id = 34409,
  processed_at = COALESCE(processed_at, NOW()),
  cf_payment_id = '6206001295'
WHERE id = 40152
  AND processing_status = 'failed';

COMMIT;
```

Re-running the `UPDATE orders` clause is safe when `cashfree_payment_id` is already `6206001295`.  
Re-running the webhook update is safe when status is already `processed` with `incident_id = 34409`.

### Step 3 — Post-repair verification

```sql
SELECT o.id, o.order_id, o.cashfree_payment_id, o.payment_amount, o.payment_method,
       w.id AS webhook_id, w.processing_status, w.incident_id, w.processing_error
FROM orders o
JOIN cashfree_webhook_logs w ON w.id = 40152
WHERE o.id = 33634;

SELECT disposition, reason
FROM (
  -- run in application shell after deploy:
  -- app(CashfreePaymentIntegrityService::class)->assessLog(CashfreeWebhookLog::find(40152))
) AS assessment_placeholder;
```

Application verification (preferred):

```bash
php artisan tinker --execute="
\$log = App\Models\CashfreeWebhookLog::find(40152);
\$a = app(App\Services\Cashfree\CashfreePaymentIntegrityService::class)->assessLog(\$log);
dump(\$a);
dump(app(App\Services\Cashfree\CashfreePaymentIntegrityService::class)->classifyFailedWebhooks()->activeFailedWebhooks);
"
```

Expected:

- Order 33634 has `cashfree_payment_id = 6206001295` and populated payment fields
- Webhook #40152 `processing_status = processed`, `incident_id = 34409`, `processing_error = NULL`
- `assessLog(#40152)` → `AlreadyExists` / `cashfree_payment_id_exists` or `order_id_exists`
- `activeFailedWebhooks` no longer includes #40152

### Step 4 — Rollback

Only if repair was applied in error **and** no downstream finance actions consumed the payment link:

```sql
START TRANSACTION;

UPDATE cashfree_webhook_logs
SET processing_status = 'failed',
    processing_error = :saved_processing_error,
    incident_id = NULL,
    processed_at = :saved_processed_at
WHERE id = 40152;

UPDATE orders
SET cashfree_payment_id = NULL,
    payment_amount = NULL,
    payment_method = NULL,
    payment_date = NULL,
    bank_reference = NULL,
    gateway_order_id = NULL,
    gateway_payment_id = NULL,
    updated_at = :saved_updated_at
WHERE id = 33634
  AND cashfree_payment_id = '6206001295';

COMMIT;
```

Restore exact pre-repair column values from Step 0 snapshot.

---

## Remaining risks

- Orders without any service case still cannot receive payment links (processor throws by design).
- Case normalization uses `UPPER()`; extremely rare collision if two distinct business IDs differ only by case (not observed in production).
- One-time repair still required for RD3483568 payment fields on order 33634 after deploy.

---

## Tests added

- `tests/Unit/Cashfree/CashfreePaymentIntegrityCandidatePaidWithoutTest.php` — mixed-case integrity
- `tests/Feature/CashfreeExistingOrderPaymentLinkTest.php` — webhook link path
- `tests/Feature/ReprocessFailedCashfreeWebhooksCommandTest.php` — dry-run skip for existing business order
- `tests/Unit/Cashfree/CashfreeMissingOrderAutoRecoveryDiscoveryTest.php` — non-recoverable mixed-case order
