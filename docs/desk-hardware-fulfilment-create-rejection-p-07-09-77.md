# Shiprocket create rejection for RDE318421 — RadiumDesk-P-07-09-77

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-77`  
**Mode:** Diagnosis of the live create rejection, then local UI/error-extraction and retry-safety correction. No second live create. No deploy. RDE318421 not rewritten.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-76**. This ticket: **P-07-09-77**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Answer

Avinash’s Create Shipment **did reach Shiprocket** and was **rejected**. Nothing was created on the provider.

Stored provider message: **`Oops! Invalid Data.`**

The UI showed only **Provider validation error** because readiness used a hardcoded blocker and discarded Shiprocket’s `errors` object. Laravel logs do **not** contain the HTTP body. HTTP status was **not stored**.

This is **not** a Delhivery 15084 create failure. `POST /orders/create/adhoc` does **not** send `courier_id`. Courier selection is Desk-side only until AWB.

**Do not invent a channel ID.** Official adhoc documents `channel_id` as optional. Whether this account rejected the omitted key is still **UNKNOWN** because field errors were not retained. Historical successful API orders all had one channel id — that does not authorize writing `.env`.

Do **not** retry live create until this overlay is deployed (so the next rejection keeps field errors) and an owner decides whether a production channel id must be supplied from Shiprocket’s Get All Channels.

---

## Part 1 — Exact provider error

| Item | Value | Class |
|------|-------|-------|
| Local shipment | `shipments.id=1`, `HW-RDE318421` | VERIFIED |
| Status / failure | `failed` / `provider_rejected` | VERIFIED |
| `last_error` | `Oops! Invalid Data.` | VERIFIED |
| Attempts | 1 | VERIFIED |
| Provider order / shipment / AWB / label | all null | VERIFIED |
| Fulfilment `shipment_id` | still null (bind only on success) | VERIFIED |
| HTTP status | not stored; not in `laravel.log` | UNKNOWN |
| Provider `errors` object | not stored (gateway kept only `message`) | VERIFIED gap |
| Correlation | `12c07c68-ba5a-4d3f-b859-5b26eec5e2a4` | VERIFIED |
| Classification | Non-retryable provider validation (`markFailed`) | VERIFIED |

Shiprocket’s documented shape for this message is typically HTTP 422 or HTTP 200 + `status_code: 422` plus `errors.{field}`. **UNKNOWN** which fields failed on this call.

---

## Part 2 — Reconstructed create request

Endpoint: `POST {base}/orders/create/adhoc` (config base `https://apiv2.shiprocket.in/v1/external`). **VERIFIED** in `HttpShiprocketGateway`.

Reconstructed from mapper + production rows (no second live call):

| Field | Sent | Class |
|-------|------|-------|
| `order_id` | `HW-RDE318421` | VERIFIED (`shipment_no`) |
| `order_date` | `2026-09-07 15:04` (paid_at, app TZ) | INFERRED from mapper + `paid_at` |
| `pickup_location` | `RADDELHI` | VERIFIED snapshot |
| `billing_customer_name` | present, length 12 | VERIFIED shape |
| `billing_address` | structured line1 (52 chars) | VERIFIED |
| `billing_address_2` | line2 present (29 chars) | VERIFIED |
| `billing_city` | `Paschim Medinipur (West Midnapore)` | VERIFIED |
| `billing_pincode` | `721130` | VERIFIED |
| `billing_state` | `West Bengal` | VERIFIED |
| `billing_country` | `India` | VERIFIED snapshot |
| `billing_email` | valid `@gmail.com` | VERIFIED shape |
| `billing_phone` | 10 digits | VERIFIED shape |
| `billing_last_name` | **omitted** (null, empty key dropped) | VERIFIED code at time of failure |
| `shipping_is_billing` | `true` | VERIFIED |
| `payment_method` | `Prepaid` | VERIFIED |
| `order_items[0].name` | `Mantra MFS 100 / 110 L1 Fingerprint Scanner [INV-67275]` | VERIFIED mapper |
| `order_items[0].sku` | `946` (commerce `sku`, not `RBMFS110L1`) | VERIFIED |
| `units` / `selling_price` / `tax` | 1 / 2549.00 / 18.00 | VERIFIED |
| `sub_total` | 2549.00 | VERIFIED |
| `weight` / L×B×H | 0.24 / 14×9×7 | VERIFIED snapshot |
| `channel_id` | **omitted** (`SHIPROCKET_CHANNEL_ID` empty) | VERIFIED |
| `courier_id` / company id | **not in create payload** | VERIFIED |
| `customer_gstin` | omitted (none) | VERIFIED |

`commerce_orders.parcel` is still null. Create used the fulfilment snapshot. **VERIFIED**.

---

## Parts 3–5 — Channel, courier, serviceability vs create

| Question | Finding | Class |
|----------|---------|-------|
| Channel ID required for auth / serviceability | No | VERIFIED prior |
| Official adhoc `channel_id` | Optional; default Custom channel | VERIFIED Shiprocket docs |
| Historical successful API orders with a channel id | 4,326 / 4,326, one channel, name `Radium Box (CUSTOM)` | VERIFIED P-07-09-58 |
| This rejection caused by missing channel id? | Possible, not proven | **UNKNOWN** |
| Invent / copy channel id | **NO — Not performed.** | — |
| Courier-specific (15084)? | No. Create does not send courier | VERIFIED |
| Serviceability inputs | pickup 110019, delivery 721130, weight 0.24, prepaid (`cod=0`) | VERIFIED prior + snapshot |
| Create differences | no courier id; SKU `946`; city string with parentheses; last name omitted; channel omitted | VERIFIED |

---

## Root cause

| Class | Finding |
|-------|---------|
| VERIFIED | Live create was rejected. Stored message `Oops! Invalid Data.` No provider ids. UI hid the stored message. Field errors not persisted. Channel id omitted. Last name omitted. Courier not on create. |
| INFERRED | Message is Shiprocket’s generic 422 wrapper. Community cases with the same wrapper often list `billing_last_name: validation.present` when the key is absent. Official samples include `billing_last_name: ""`. |
| UNKNOWN | Exact field list / HTTP status for this call. Whether channel id, city text, SKU `946`, or last-name presence was the failing key. |

**STOP on channel-id configuration.** The correct production value, if required, must come from Shiprocket **Get All Channels** for this account (the historical custom channel). Do not copy hashes or Admin hardcodes into `.env` without owner authorization.

---

## Parts 7–8 — UI and retry (local)

| Item | Change |
|------|--------|
| UI | Shows `Shiprocket rejected shipment creation: {stored last_error}`. Distinguishes selected courier vs attempted vs rejected vs not created. |
| Error extraction | Persists HTTP status (when 4xx) + safe `errors` fields. Drops password/token-like keys. |
| Payload | Always sends `billing_last_name` (empty string if unknown). Does **not** invent a name. Still omits empty `channel_id`. |
| Retry | Provider rejection is no longer a hard “never retry”. Second Create searches first, then may create once if search finds nothing. Bound ids are never overwritten. Timeout/ambiguous path unchanged. |
| Parcel / payment / invoice / serial | Unchanged |

Production still has the old UI until overlay. After overlay, Avinash can see `Oops! Invalid Data.` and retry **only when authorized**. The next rejection should retain field errors.

---

## Safety confirmations

- No second Create Shipment attempt was made.
- No duplicate shipment was created.
- No AWB was assigned.
- No label was generated.
- No pickup was requested.
- No manifest was generated.
- No parcel data was changed.
- No payment/invoice/serial data was changed.
- No credentials were exposed.
- Channel ID was not written.

---

## Completion report

| Field | Value |
|-------|-------|
| Project | Radium Desk |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| HEAD | see commit |
| Prompt ID | `RadiumDesk-P-07-09-77` |
| Provider HTTP status | UNKNOWN (not stored) |
| Provider error code | UNKNOWN (`status_code` not stored) |
| Exact provider rejection reason | `Oops! Invalid Data.` |
| Provider response classification | Non-retryable validation |
| Create endpoint | `POST /v1/external/orders/create/adhoc` |
| Create request reconstruction | See Part 2 |
| Channel ID requirement | Official optional; this failure UNKNOWN; **do not invent** |
| Courier-specific issue | No |
| Payload issue | Last name omitted (fixed locally). Other fields UNKNOWN |
| Serviceability/create difference | Courier not on create; SKU/city/channel/last name differ from serviceability tuple |
| UI correction | Yes — safe stored message |
| Retry behavior | Search-then-create after rejection |
| Implementation | Local only |
| Migration | **NO — Not performed.** |
| Tests | Gateway 422/200-error; P5 search-before-retry; UI rejection copy |
| Pint/lint | Passed |
| Live Shiprocket calls | **NO — Not performed.** (prior attempt already existed) |
| Production data changed | **NO — Not performed.** |
| RDE318421 changed | **NO — Not performed.** |
| Shipment created | **NO — Not performed.** |
| AWB | **NO — Not performed.** |
| Label | **NO — Not performed.** |
| Pickup | **NO — Not performed.** |
| Manifest | **NO — Not performed.** |
| Pushed | **NO — Not performed.** |
| Deployed | **NO — Not performed.** |
| Remaining risks | Next authorized retry may fail again until field errors or a verified channel id exist. City/SKU still unproven. Do not mass-retry. |
