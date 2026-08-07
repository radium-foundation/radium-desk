# Cashfree Webhook Log 35109 — Auto-recovery Failed

**Date:** 2026-08-07  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no production writes · no code changes in this investigation  
**Environment:** Production (`desk.radiumbox.com` / `radium-desk`) via SSH + `php artisan tinker`  
**Canvas:** [`cashfree-35109-auto-recovery-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/cashfree-35109-auto-recovery-investigation.canvas.tsx)

---

## Bottom line

Paid Cashfree payment **RD3478853** / **cf_payment_id 6183507506** never created a Desk order because `order_tags.serial_no` is a **152-character padded duplicate** (`7071331` + spaces + `7071331`). `orders.serial_number` is `varchar(100)`. The INSERT fails, the payment transaction rolls back, and `cashfree:auto-recover-missing` keeps classifying the log as **recoverable** and retrying every ~5 minutes.

**It will not auto-heal** until serial normalization (or skip-on-overflow) is deployed.

---

## Root cause

| Layer | Detail |
|-------|--------|
| Trigger | Cashfree `PAYMENT_SUCCESS_WEBHOOK` log `#35109` |
| Bad field | `data.order.order_tags.serial_no` = `"7071331" + ~138 spaces + "7071331"` (len **152**) |
| Normalizer | `RadiumBoxOrderSearchResponseMapper::normalizeSerialNumber` → `strtoupper(trim(...))` only — **preserves** the 152-char string |
| DB constraint | `orders.serial_number` = `varchar(100)` |
| Exception | `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'serial_number'` |
| Effect | Order/incident/outbox never committed; webhook stays `failed` |
| Loop | Integrity `assessLog` → `recoverable` / `ready` → auto-recovery re-processes → same failure |

Causal chain:

```text
Cashfree SUCCESS (₹499 UPI)
  → CashfreeWebhookProcessorService::createOrder
      → resolveOrderTagIdentity
          → normalizeSerialNumber(padded duplicate)  // still 152 chars
      → Order::create([ serial_number => 152-char value ])
          → MySQL 1406  ❌
  → markWebhookFailed
  → cashfree:auto-recover-missing (every 5 min)
      → assessLog = recoverable
      → process(log) again → same 1406
      → Ira "Auto-recovery failed … Webhook log(s): 35109"
```

---

## Risk

**High (customer-facing payment gap).**

- Customer paid; **no Desk order**, **no service case**.
- Critical alert + Ira Telegram stay active while `missingOrdersCount = 1`.
- Scheduler burns a recovery attempt every 5 minutes with zero progress.
- Not a queue backlog; not a system-user misconfig (system user `info@radiumbox.com` id 1 is active).

---

## Recommended fix

1. **Harden serial ingest (primary):** In `RadiumBoxOrderSearchResponseMapper::normalizeSerialNumber` (and/or Cashfree tag path):
   - Collapse internal whitespace / take the **first** non-empty token.
   - Enforce max length **100** (column limit).
   - If still invalid after sanitize: **skip serial** and create the order without it (payment must not die on tag junk). Log a warning + audit.
2. **Deploy**, then let `cashfree:auto-recover-missing` heal `#35109` (or run `cashfree:recover-historical --log=35109` once).
3. **Upstream:** Find why RadiumBox/Cashfree checkout wrote a duplicated padded `serial_no` for this order (intended value is `7071331`).
4. **Optional hardening:** Treat `SQLSTATE[22001]` on identity fields as non-fatal for payment persist (identity skip), separate from true DB outages.

Do **not** manually invent an order in production without the code fix — the next auto-recovery pass would hit the same INSERT if serial is still taken from the raw tag.

---

## Investigation answers

### Original payment

| Field | Value |
|-------|-------|
| Webhook log | **35109** |
| `cf_payment_id` | **6183507506** |
| Cashfree `order_id` | **RD3478853** |
| Gateway order / payment | `6596806811` / `6183507506` |
| Amount | ₹499 |
| Method | UPI (`qrcode`, `9850516632@axl`) |
| Paid at | 2026-08-07 13:50:46 IST |
| Customer | PRASAD BAGAL · kavyacomputervaduj@gmail.com · 9850516632 |
| Cashfree customer id | R525968 |
| Product / plan | MFS110 · 1 Year Unlimited |
| Bank reference | 089203379118 |

### Webhook payload (relevant)

- `type`: `PAYMENT_SUCCESS_WEBHOOK`
- `data.payment.payment_status`: `SUCCESS`
- `data.order.order_tags.serial_no`: padded duplicate (**152** chars) — intended serial `7071331`
- Single related webhook log for this payment (no Cashfree redelivery duplicates recorded)

### Why recovery failed

Same path as first ingest: `createOrder` writes the oversized serial → MySQL 1406 → transaction rollback → `processing_status=failed`. Latest `processing_error` timestamp matches last recovery (`2026-08-07 14:20:50 IST`).

### Retry history

| Audit id | Time (UTC) | IST | Result |
|----------|------------|-----|--------|
| 863723 | 08:25:27 | 13:55:27 | still_failed |
| 863865 | 08:30:57 | 14:00:57 | still_failed |
| 864288 | 08:35:24 | 14:05:24 | still_failed |
| 864458 | 08:40:25 | 14:10:25 | still_failed |
| 864821 | 08:46:03 | 14:16:03 | still_failed |
| 865070 | 08:50:50 | 14:20:50 | still_failed |

Ira Telegram `integration_failure` (user 1):

- `#26768` at 08:25:27 UTC — “Auto-recovery failed … Webhook log(s): **35109**”
- `#26802` at 08:46:04 UTC — same message

`storage/logs/cashfree-auto-recover.log` shows repeated `Found: 1 / Recovered: 0 / Still failed: 1`.

### Queue history

| Store | Hits for 35109 / 6183507506 / RD3478853 |
|-------|------------------------------------------|
| `jobs` | none |
| `failed_jobs` | none |
| `outbox_events` | none |

Cashfree success persist is synchronous (HTTP webhook + scheduled artisan recovery), not a queued job for this path.

### Existing order

**None.** Queries on `cashfree_payment_id`, `gateway_payment_id`, `gateway_order_id`, and `order_id = RD3478853` returned empty. No order with serial `7071331`.

### Existing case

**None.** `cashfree_webhook_logs.incident_id` is null; no incidents linked.

### Duplicate protection

| Guard | Behavior here |
|-------|----------------|
| `findExistingIncidentForPayment(cf_payment_id)` | No order → proceeds to create |
| Serial uniqueness skip in `resolveOrderTagIdentity` | Looks up exact 152-char string → no owner → **attempts insert** |
| Integrity `AlreadyExists` | Would clear alert if order existed — does not |

Duplicate protection is working as designed; it never gets a chance to short-circuit because nothing was created.

### Why alert still active

1. Integrity reconcile still has **1** missing paid order (`webhookLogId=35109`, disposition `recoverable`).
2. Watchdog critical alert key `cashfree:paid_missing_order` fires while that count &gt; 0.
3. Auto-recovery continues to fail and re-notify Ira (`CashfreeMissingOrderAutoRecoveryService::notifyRecoveryFailure`).

### Can it auto-heal?

**No** with current code. Disposition stays `recoverable`, so the poison payload is retried indefinitely. Healing requires a code change (sanitize/skip serial) then one successful recover pass — or a carefully controlled one-off ops action after the fix.

---

## Related same-day failed logs (not this bug)

| Log | Payment | Error | Disposition now |
|-----|---------|-------|-----------------|
| 34888 | RD3478588 | Duplicate `order_id` unique | `already_exists` |
| 34985 | RD3478650 | Duplicate `order_id` unique | `already_exists` |
| **35109** | **RD3478853** | **serial_number too long** | **recoverable** |

Only `#35109` remains an active missing-order failure.

---

## Evidence sources

- `cashfree_webhook_logs` id 35109 (payload + `processing_error`)
- `audit_logs` event `cashfree.missing_order_auto_recovery` (6 rows)
- `ira_notifications` `integration_failure` #26768, #26802
- `CashfreePaymentIntegrityService::reconcile()` / `assessLog()`
- `SHOW COLUMNS FROM orders LIKE 'serial_number'` → `varchar(100)`
- Code: `CashfreeWebhookProcessorService::createOrder` / `resolveOrderTagIdentity`, `RadiumBoxOrderSearchResponseMapper::normalizeSerialNumber`, `CashfreeMissingOrderAutoRecoveryService`

---

## Constraints honored

- Investigate only  
- No production writes  
- No deploy / recover commands executed  
