# Cashfree `order_tags` Production Investigation

**Date:** 2026-08-06  
**Mode:** Read-only (production MySQL via SSH / `artisan tinker`; no writes, no code changes)  
**Canvas:** [`cashfree-order-tags-production-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/cashfree-order-tags-production-investigation.canvas.tsx)  
**Related:** [cashfree-integration-data-inventory.md](./cashfree-integration-data-inventory.md)

---

## Verdict

**Yes — Cashfree SUCCESS webhooks already carry RadiumBox custom `data.order.order_tags`.**

Across the last **50 unique successful payments** (deduped by `cf_payment_id`), **49/50** include a non-null `order_tags` object. The only keys observed are:

| Tag Key | Sample Value | Times Seen (of 50) |
|---------|--------------|--------------------|
| `product_name` | `MSO 1300 E3 RD L1` | 49 |
| `rd_service_name` | `1 Year Unlimited` | 49 |
| `serial_no` | `2521I006956` | 49 |

One payment (`cf_payment_id` `6178095997`, order `RD3477522`) had `"order_tags": null`.

A broader scan of **500 unique SUCCESS payments** found **the same three keys only** — no additional tag keys.

### Phase A status (implemented 2026-08-06)

Desk now **promotes these tags at PAYMENT_SUCCESS ingest**:

| Tag | Desk field | Notes |
|-----|------------|-------|
| `product_name` | `orders.product_name` + `orders.device_model` | Fill-if-empty; drives Customer360 Product |
| `serial_no` | `orders.serial_number` | Normalized via `RadiumBoxOrderSearchResponseMapper::normalizeSerialNumber`; blank skipped; duplicate serial skipped |
| `rd_service_name` | `orders.service_history` | Stored as single-entry array; Customer360 “Service Plan” |

Audit event: `cashfree.order_tags_imported` with `source = cashfree_order_tags`.  
RadiumBox enrichment remains as fill-missing-only fallback (does not overwrite tag values).

---

## 1. Raw `order_tags` examples

### Typical SUCCESS payload fragment (log `#33860`, 2026-08-06 23:09 IST)

```json
{
  "order_id": "RD3477557",
  "order_amount": 499,
  "order_currency": "INR",
  "order_tags": {
    "product_name": "MSO 1300 E3 RD L1",
    "rd_service_name": "1 Year Unlimited",
    "serial_no": "2521I006956"
  }
}
```

### Additional recent examples

```json
{ "product_name": "MFS110", "rd_service_name": "1 Year Unlimited", "serial_no": "7891312" }
```

```json
{ "product_name": "MIS100 IRIS", "rd_service_name": "3 Years Unlimited", "serial_no": "8797735" }
```

```json
{ "product_name": "Access FM220 L1", "rd_service_name": "1 Year Unlimited", "serial_no": "" }
```

### Null tags example (log `#33825`)

```json
{
  "order_id": "RD3477522",
  "order_amount": 749,
  "order_currency": "INR",
  "order_tags": null
}
```

---

## 2. Complete key inventory

**Sample:** last 50 unique `PAYMENT_SUCCESS_WEBHOOK` + `payment_status=SUCCESS` (ordered by webhook log id desc).  
**Broader confirmation:** last 500 unique SUCCESS payments — keys unchanged.

| Tag Key | Sample Value | Times Seen (50 unique) | Times Seen (500 unique) |
|---------|--------------|------------------------|-------------------------|
| `product_name` | `MSO 1300 E3 RD L1` | 49 | 494 |
| `rd_service_name` | `1 Year Unlimited` | 49 | 494 |
| `serial_no` | `2521I006956` | 49 | 494 |

### `product_name` distribution (last 50 unique with tags)

| Value | Count |
|-------|------:|
| `MFS110` | 37 |
| `Access FM220 L1` | 4 |
| `MFS 110` | 4 |
| `MIS100 IRIS` | 3 |
| `MSO 1300 E3 RD L1` | 1 |

### `rd_service_name` distribution (last 50 unique with tags)

| Value | Count |
|-------|------:|
| `1 Year Unlimited` | 43 |
| `3 Years Unlimited` | 4 |
| `2 Years Unlimited` | 2 |

### `serial_no`

- Present non-empty: **47 / 50**
- Blank string: **2 / 50** (still key present)
- Tags object null: **1 / 50**

**No other keys** appeared (`device_model`, `brand`, `year`, `mac_year`, `issue`, `cf_link_id`, etc. — absent).

---

## 3. Device-related fields

| Asked | Present in `order_tags`? | Evidence |
|-------|--------------------------|----------|
| Model | **Indirectly via `product_name`** | Values like `MFS110`, `MSO 1300 E3 RD L1`, `MIS100 IRIS`, `Access FM220 L1`. There is **no** separate `model` / `device_model` key. |
| Brand | **Absent** | Not in tags (50 or 500). |
| Serial Number | **Yes — `serial_no`** | e.g. `2521I006956`, `7891312`, `8797735` |
| Manufacturing Year | **Absent** | — |
| Mac Year | **Absent** | — |
| Storage | **Absent** | — |
| RAM | **Absent** | — |
| Processor | **Absent** | — |
| Color | **Absent** | — |

Service plan (not in the asked device list, but present): **`rd_service_name`** — e.g. `1 Year Unlimited`, `3 Years Unlimited`.

---

## 4. Used vs ignored (updated after Phase A)

| Tag Key | Stored from webhook? | Used in Cashfree ingest logic? | Ignored? | Notes |
|---------|----------------------|--------------------------------|----------|-------|
| `product_name` | **Yes** (`orders.product_name` / `device_model`) | **Yes** | No | Primary identity at payment time |
| `serial_no` | **Yes** (`orders.serial_number`) when non-blank | **Yes** | Blank values still ignored | Uppercased/trimmed like RadiumBox |
| `rd_service_name` | **Yes** (`orders.service_history`) | **Yes** | No | Shown as Customer360 Service Plan |

Any other tag keys (none observed in production) remain raw-log only.

### Production lag evidence (same 50 payments)

Among 49 Desk orders matched by `cashfree_payment_id`:

| Metric | Count |
|--------|------:|
| Order already has `product_name` filled | 48 |
| Order already has `device_model` filled | 48 |
| Order serial equals tag `serial_no` | 44 |
| Tag had serial but order serial still empty | 3 |

So enrichment often catches up — but **not from the webhook tags**, and not always immediately (e.g. `RD3477549` still empty product/serial while tags had `MFS110` / `9536411`).

---

## 5. Recommendation

**Phase A shipped:** promote tags at PAYMENT_SUCCESS ingest (see Verdict). Remaining follow-ups:

| Item | Priority | Why |
|------|----------|-----|
| Historical backfill from `cashfree_webhook_logs` for older orders still missing identity | P1 | Existing SUCCESS payloads already have tags |
| Ask RadiumBox for brand / year / hardware keys if needed | Future | Not present in current tags |

### Do not invent from tags

Brand, manufacturing year, Mac year, storage, RAM, processor, color — **not present**. If needed, request RadiumBox to add those keys to Cashfree `order_tags` (or continue pulling from RadiumBox API enrichment).

### Customer360

Surface on payment / device cards:

1. Serial from payment tags (with “source: Cashfree order_tags” if useful for audit)
2. Product/model from `product_name`
3. RD service plan from `rd_service_name`

### Guardrails

- Keep idempotency: only fill empty Desk fields, or apply the same normalize rules as `RadiumBoxOrderSearchResponseMapper` (`normalizeSerialNumber`, `normalizeDeviceModel`).
- Blank `serial_no` (seen on FM220) must not overwrite a later good serial.
- Null `order_tags` (rare) → keep current enrichment fallback.

---

## Method

```text
Production host via tools/config.sh SSH
Query: cashfree_webhook_logs
Filter: type = PAYMENT_SUCCESS_WEBHOOK AND payment_status = SUCCESS
Dedup: cf_payment_id, newest first
Sizes: 50 unique (primary), 500 unique (key stability)
```

No production rows modified. No Cashfree APIs called.
