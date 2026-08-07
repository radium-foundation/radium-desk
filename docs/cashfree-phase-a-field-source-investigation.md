# Cashfree Phase A — Field Source Production Investigation

**Date:** 2026-08-07  
**Mode:** Read-only (production MySQL via SSH / `artisan tinker`; no writes, no code changes)  
**Canvas:** [`cashfree-phase-a-field-source-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/cashfree-phase-a-field-source-investigation.canvas.tsx)  
**Related:** [cashfree-order-tags-production-investigation.md](./cashfree-order-tags-production-investigation.md), [cashfree-integration-data-inventory.md](./cashfree-integration-data-inventory.md)

---

## Verdict

For **RD3477979** (`cf_payment_id` `6182359361`), Product Name, Serial Number, and Service Plan were populated from Cashfree `order_tags` at PAYMENT_SUCCESS ingest — **before** RadiumBox ran.

RadiumBox completed with `lookup_result=no_data` and `fields_applied=[]`, so it did **not** change identity.

Device Model was initially set from the same `product_name` tag, then remapped by Desk master assignment (`device-model.bulk-assigned`) to `MSO E3` in the same second — **not** by RadiumBox.

**Phase A reduced RadiumBox dependency for payment-time identity when tags are present.**

---

## Selected payment

Latest unique SUCCESS payment that:

1. Contained non-empty `order_tags.product_name`, `serial_no`, `rd_service_name`
2. Had Phase A `cashfree.order_tags_imported` including serial
3. Had `radiumbox.enrichment_completed`

| Item | Value |
|------|-------|
| Order | `RD3477979` (db id `28368`) |
| Cashfree payment | `6182359361` |
| Service case | `SC28978` (incident `29049`) |
| Amount | ₹617 |
| App timezone | Asia/Kolkata |
| Phase A on prod | Yes (`AUDIT_EVENT_ORDER_TAGS_IMPORTED` present; 240+ tag-import audits) |

### Raw `order_tags` (both webhook deliveries)

```json
{
  "product_name": "MSO1300 E3-L1 RD",
  "rd_service_name": "1 Year Unlimited",
  "serial_no": "2508I040915"
}
```

---

## 1. End-to-end timeline

MySQL `DATETIME` has **second precision only** (no milliseconds stored). Cashfree `event_time` includes timezone offset.

| # | Timestamp (IST) | Event | Evidence |
|---|-----------------|-------|----------|
| 1 | 2026-08-07 10:28:02 +05:30 | Cashfree payment success (`event_time`) | Webhook payload |
| 2 | 2026-08-07 10:28:03 | Webhook received | `cashfree_webhook_logs.id=34336` |
| 3 | 2026-08-07 10:28:03 | `order_tags` parsed + imported | audit `cashfree.order_tags_imported` `#851929` |
| 4 | 2026-08-07 10:28:03 | Order created | `orders.created_at` |
| 5 | 2026-08-07 10:28:03 | Product Name / Serial / Service Plan populated | Tag-import audit fields |
| 6 | 2026-08-07 10:28:03 | Device Model canonicalized (Desk) | audit `device-model.bulk-assigned` `#851930` |
| 7 | 2026-08-07 10:28:03 | Customer360 first available | Order + `SC28978` exist with identity filled |
| 8 | 2026-08-07 10:28:03 | RadiumBox enrichment dispatched | outbox `#334637` `radiumbox_enrichment` created |
| 9 | 2026-08-07 10:28:17 | Outbox radiumbox op processed | outbox `processed_at` |
| 10 | 2026-08-07 10:29:01 | RadiumBox enrichment started | audit `radiumbox.enrichment_started` `#851947` |
| 11 | 2026-08-07 10:29:01 | RadiumBox enrichment completed | audit `radiumbox.enrichment_completed` `#851950` · `no_data` · `fields_applied=[]` |
| 12 | 2026-08-07 10:30:14 | Duplicate webhook | log `#34349` · same `cf_payment_id` · processed · no second order |

~**58 seconds** from webhook receipt to RadiumBox completion. Identity was available for Customer360 for that entire window without RB.

### Tag-import audit payload

```json
{
  "source": "cashfree_order_tags",
  "fields": [
    "product_name",
    "device_model",
    "service_history",
    "rd_service_name",
    "serial_number"
  ],
  "product_name": "MSO1300 E3-L1 RD",
  "device_model": "MSO1300 E3-L1 RD",
  "service_history": ["1 Year Unlimited"],
  "rd_service_name": "1 Year Unlimited",
  "serial_number": "2508I040915"
}
```

### Device model Desk remap (same second)

| | Value |
|--|-------|
| Before | `device_model=MSO1300 E3-L1 RD`, `device_model_id=null` |
| After | `device_model=MSO E3`, `device_model_id=2` |
| Actor | System user (audit user_id=1) |
| Source | Desk `device-model.bulk-assigned` — **not** RadiumBox |

---

## 2. Source of every displayed field

| Field | Answer | Detail |
|-------|--------|--------|
| `product_name` | **A** — Cashfree `order_tags` | Imported at create; unchanged after RB |
| `device_model` | **A** then Desk master (not B) | Tag set initial string; Desk remapped to `MSO E3` before RB |
| `serial_number` | **A** — Cashfree `order_tags` | Imported at create; `missing_serial.completed` reason=`cashfree_order_tags` |
| `service_plan` | **A** — Cashfree `order_tags` | `rd_service_name` → `service_history[0]` |

Legend (as requested):

- **A** = Populated from Cashfree `order_tags`
- **B** = Populated from RadiumBox
- **C** = Already existed
- **D** = Overwritten later

No field was **B**. Device Model is the only value that changed after tag ingest, and that change was Desk canonicalization — not RadiumBox overwrite.

---

## 3. Before vs after comparison

### Product Name

- Cashfree: `MSO1300 E3-L1 RD`
- After RB: `MSO1300 E3-L1 RD`
- Changed? **No**

### Device Model

- Cashfree (tag → initial Desk field): `MSO1300 E3-L1 RD`
- After Desk map (still before RB): `MSO E3`
- After RB: `MSO E3`
- Changed by RB? **No**

### Serial Number

- Cashfree: `2508I040915`
- After RB: `2508I040915`
- Changed? **No**

### Service Plan

- Cashfree: `1 Year Unlimited`
- After RB: `1 Year Unlimited`
- Changed? **No**

---

## 4. Protection verification

| Check | Result | Evidence |
|-------|--------|----------|
| RadiumBox never overwrote Cashfree values | **PASS** | `fields_applied=[]`, `lookup_result=no_data` |
| Fill-missing logic behaved correctly | **PASS** | Identity already filled; RB applied nothing |
| Blank values never overwrote populated values | **PASS** | Order identity stable across RB sync |
| Duplicate webhook remained idempotent | **PASS** | 2 webhook logs, 1 order, 1 incident, 1 tag-import audit, 3 deferred outbox rows |
| Broader Phase A overwrite scan | **PASS** | Among last 300 `radiumbox.enrichment_completed` rows since 2026-08-06, **zero** cases had both tag import and identity fields in `fields_applied` |

### Adjacent payment — RB had data (protection still held)

**RD3477985** (`cf_payment_id` `6182358165`):

- Tags included `serial_no=10455441`, but ingest **skipped** serial because it is already owned by `RD3474540`
- RB returned `lookup_result=data_received` and applied **only** `customer_name`
- `duplicate_serial=true`
- Product / device / service plan from tags were **not** overwritten

---

## 5. Customer360 — before vs after enrichment

Customer360 reads `orders.product_name`, `orders.serial_number`, `orders.service_history` (Service Plan), and device display via `displayDeviceModelName()` / ops header product preference.

### BEFORE RadiumBox (from 10:28:03)

| UI field | Visible value |
|----------|---------------|
| Product Name | `MSO1300 E3-L1 RD` |
| Device Model (display) | `MSO E3` |
| Serial Number | `2508I040915` |
| Service Plan | `1 Year Unlimited` |

### AFTER RadiumBox (10:29:01)

| UI field | Visible value |
|----------|---------------|
| Product Name | `MSO1300 E3-L1 RD` |
| Device Model (display) | `MSO E3` |
| Serial Number | `2508I040915` |
| Service Plan | `1 Year Unlimited` |

Sync status became `SYNCED` with metadata `lookup_result=no_data`. Serial-present cases hide sync diagnostics in Customer360; identity values above were unchanged.

---

## 6. Did Phase A reduce RadiumBox dependency?

**Yes — for payment-time identity when tags are present.**

- Product Name, Serial, Service Plan were Customer360-visible ~58s before RB finished
- RB contributed **zero** identity fields on this payment
- Broader scan found no Phase A identity overwrites by RB

### Still requires RadiumBox

| Field / capability | Why |
|--------------------|-----|
| Warranty | Not in `order_tags`; enrichment metadata |
| AMC | Not in `order_tags`; enrichment metadata |
| Activation year | Returned on some RB lookups (e.g. RD3477985) |
| Customer name/phone/email fill-missing | RB may still fill thin Cashfree customer fields |
| Serial when tag blank or duplicate-owned | Ingest skips; RB cannot steal owned serials either |
| Product/plan when `order_tags` null | Rare fallback path |

---

## Method

```text
Production host via tools/config.sh SSH
Host: desk.radiumbox.com / radium-desk
Queries: cashfree_webhook_logs, orders, incidents, audit_logs, outbox_events
Filter: PAYMENT_SUCCESS + non-empty order_tags.product_name/serial_no/rd_service_name
Pick: latest unique cf_payment_id with Phase A serial import + RB completed
```

No production rows modified. No Cashfree or RadiumBox write APIs called. No code changes.
